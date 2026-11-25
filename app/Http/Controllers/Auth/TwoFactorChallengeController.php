<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityKind;
use App\Http\Controllers\Concerns\CompletesSignIn;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Account\TwoFactor;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of sign-in for a user with two-factor sign-in. Between the password and this step the
 * session holds only the user's id (see AuthenticatedSessionController), and nobody is signed in.
 */
class TwoFactorChallengeController extends Controller
{
    use CompletesSignIn;

    public const SESSION_KEY = 'two_factor.pending';

    /** How long the password step stays valid. */
    private const PENDING_SECONDS = 300;

    /** Wrong codes allowed within one pending sign-in before the user must enter the password again. */
    private const MAX_FAILURES_PER_PENDING = 5;

    /** Wrong codes per user and IP per minute, per user across all IPs per 15 minutes, and per IP per minute. */
    private const MAX_PER_USER_AND_IP = 5;

    private const MAX_PER_USER = 20;

    private const MAX_PER_IP = 20;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return $this->backToLogin($request);
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->backToLogin($request);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $recovery = filled($data['recovery_code'] ?? null);
        $field = $recovery ? 'recovery_code' : 'code';
        $value = (string) ($data[$field] ?? '');

        if ($value === '') {
            throw ValidationException::withMessages(['code' => 'Enter the code from your authenticator app, or a recovery code.']);
        }

        $this->ensureIsNotRateLimited($request, $user, $field);

        $ok = $recovery
            ? $this->twoFactor->acceptRecoveryCode($user, $value)
            : $this->twoFactor->acceptCode($user, $value);

        if (! $ok) {
            return $this->fail($request, $user, $field, $recovery);
        }

        RateLimiter::clear($this->userAndIpKey($request, $user));

        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($recovery) {
            ActivityLogger::log(ActivityKind::AuthTwoFactorRecoveryUsed, user: $user, fields: [
                'meta' => ['remaining' => $this->twoFactor->remainingRecoveryCodes($user)],
            ]);
        }

        return $this->completeSignIn($request, $user, (bool) ($pending['remember'] ?? false), ['two_factor' => $recovery ? 'recovery_code' : 'totp']);
    }

    /**
     * The user the password step let through, or null when there is none, it expired, or the account
     * changed in the meantime (disabled by an admin, or two-factor sign-in turned off).
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (now()->getTimestamp() - (int) $pending['at'] > self::PENDING_SECONDS) {
            return null;
        }

        $user = User::find($pending['id']);

        if ($user === null || $user->disabled || ! $user->hasTwoFactorEnabled()) {
            return null;
        }

        return $user;
    }

    private function backToLogin(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('login')->withErrors(['email' => 'Your sign-in expired. Enter your email and password again.']);
    }

    private function fail(Request $request, User $user, string $field, bool $recovery): RedirectResponse
    {
        RateLimiter::hit($this->userAndIpKey($request, $user), 60);
        RateLimiter::hit($this->userKey($user), 900);
        RateLimiter::hit($this->ipKey($request), 60);

        ActivityLogger::log(ActivityKind::AuthTwoFactorFailed, ActivityLog::STATUS_FAILED, $user, [
            'meta' => ['method' => $recovery ? 'recovery_code' : 'totp'],
        ]);

        $pending = $request->session()->get(self::SESSION_KEY);
        $pending['failures'] = (int) ($pending['failures'] ?? 0) + 1;

        // Too many wrong codes on this sign-in: start over from the password, which has its own limit.
        if ($pending['failures'] >= self::MAX_FAILURES_PER_PENDING) {
            return $this->backToLogin($request)->withErrors(['email' => 'Too many wrong codes. Enter your email and password again.']);
        }

        $request->session()->put(self::SESSION_KEY, $pending);

        throw ValidationException::withMessages([$field => $recovery ? 'That recovery code is not valid, or was already used.' : 'That code is not valid. Check the code in your app and try again.']);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, User $user, string $field): void
    {
        foreach ([
            [$this->userAndIpKey($request, $user), self::MAX_PER_USER_AND_IP],
            [$this->userKey($user), self::MAX_PER_USER],
            [$this->ipKey($request), self::MAX_PER_IP],
        ] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            event(new Lockout($request));

            $seconds = RateLimiter::availableIn($key);

            // Log the lockout once per window, so a flood of attempts cannot flood the log.
            RateLimiter::attempt('lockout-logged:'.$key, 1, fn () => ActivityLogger::log(
                ActivityKind::AuthTwoFactorFailed,
                ActivityLog::STATUS_FAILED,
                $user,
                ['meta' => ['reason' => 'throttled']],
            ), $seconds);

            throw ValidationException::withMessages([
                $field => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }
    }

    private function userAndIpKey(Request $request, User $user): string
    {
        return 'two-factor|'.$user->id.'|'.$request->ip();
    }

    private function userKey(User $user): string
    {
        return 'two-factor-user|'.$user->id;
    }

    private function ipKey(Request $request): string
    {
        return 'two-factor-ip|'.$request->ip();
    }
}
