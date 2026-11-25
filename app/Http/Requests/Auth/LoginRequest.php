<?php

namespace App\Http\Requests\Auth;

use App\Enums\ActivityKind;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP, and per IP alone, within the decay window. */
    private const MAX_PER_EMAIL = 5;

    private const MAX_PER_IP = 20;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the email and password and return the user, without signing anyone in. The caller decides
     * what happens next: sign in at once, or ask for the second factor first. Every failure is recorded
     * in the activity log.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $provider = Auth::guard('web')->getProvider();
        $credentials = $this->only('email', 'password');
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            $this->hit();
            $this->logFailure('invalid_credentials');

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // A disabled account is refused at sign-in, not only on its next request (FR-2).
        if ($user->disabled) {
            $this->hit();
            $this->logFailure('disabled', $user);

            throw ValidationException::withMessages(['email' => 'This account has been disabled.']);
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        foreach ([[$this->throttleKey(), self::MAX_PER_EMAIL], [$this->ipThrottleKey(), self::MAX_PER_IP]] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);

            // Log the lockout once per window, so a flood of attempts cannot flood the log.
            RateLimiter::attempt('lockout-logged:'.$key, 1, fn () => $this->logFailure('throttled'), $seconds);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }

    private function hit(): void
    {
        RateLimiter::hit($this->throttleKey());
        RateLimiter::hit($this->ipThrottleKey());
    }

    private function logFailure(string $reason, ?User $user = null): void
    {
        $email = Str::lower($this->string('email'));
        $user ??= User::where('email', $email)->first();

        ActivityLogger::log(ActivityKind::AuthLoginFailed, ActivityLog::STATUS_FAILED, $user, [
            'email' => $email,
            'meta' => ['reason' => $reason],
        ]);
    }
}
