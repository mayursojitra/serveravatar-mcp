<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountAccess;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Completes a "forgot password" reset from the emailed link. */
class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, AccountAccess $access): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $access) {
                // The user chose this password themselves, so a pending forced change is satisfied.
                $user->forceFill([
                    'password' => $request->password,
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));

                // A reset often means the old password was exposed: every session and AI client opened with it ends.
                $revoked = $access->afterPasswordChange($user);

                ActivityLogger::log(ActivityKind::AuthPasswordResetCompleted, user: $user, fields: ['meta' => ['connections_revoked' => $revoked]]);
            }
        );

        if ($status == Password::PasswordReset) {
            return to_route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
