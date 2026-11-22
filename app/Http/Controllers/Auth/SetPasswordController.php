<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityKind;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountAccess;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** FR-1b: the page a user is sent to until they replace an admin-set password. */
class SetPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/set-password');
    }

    public function store(Request $request, AccountAccess $access): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $request->session()->regenerate();

        $revoked = $access->afterPasswordChange($user, $request->session()->getId());

        ActivityLogger::log(ActivityKind::AuthPasswordChanged, user: $user, fields: ['meta' => ['forced' => true, 'connections_revoked' => $revoked]]);

        return redirect()->intended(route('dashboard'))->with('status', 'Password updated.');
    }
}
