<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ActivityKind;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountAccess;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, AccountAccess $access): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);

        $request->user()->update(['password' => $validated['password']]);

        // Other browsers are signed out and every AI client is disconnected. This session stays.
        $revoked = $access->afterPasswordChange($request->user(), $request->session()->getId());

        ActivityLogger::log(ActivityKind::AuthPasswordChanged, user: $request->user(), fields: ['meta' => ['forced' => false, 'connections_revoked' => $revoked]]);

        return back()->with('status', 'Password updated. Your other sessions were signed out and your AI clients were disconnected: connect them again.');
    }
}
