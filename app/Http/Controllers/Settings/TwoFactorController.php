<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ActivityKind;
use App\Http\Controllers\Controller;
use App\Services\Account\TwoFactor;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A user's own two-factor settings. Every change asks for the current password again. */
class TwoFactorController extends Controller
{
    private const RECOVERY_FLASH = 'two_factor_recovery_codes';

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $enabled = $user->hasTwoFactorEnabled();
        $settingUp = $user->two_factor_secret !== null && ! $enabled;

        return Inertia::render('settings/security', [
            'enabled' => $enabled,
            'required' => (bool) config('serveravatar.require_two_factor'),
            // The secret is shown only while a setup waits for its first code. Once confirmed it never leaves the server.
            'setup' => $settingUp ? [
                'qr_code' => $this->twoFactor->qrCodeDataUri($user),
                'manual_key' => $this->twoFactor->manualKey($user),
            ] : null,
            // Shown once, right after they were made.
            'recovery_codes' => $request->session()->get(self::RECOVERY_FLASH),
            'recovery_codes_left' => $enabled ? $this->twoFactor->remainingRecoveryCodes($user) : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    /** Start a setup: makes a secret and shows its QR code. Two-factor sign-in is not on until confirm(). */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('status', 'Two-factor sign-in is already on.');
        }

        $this->twoFactor->begin($user);

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        $user = $request->user();
        $codes = $this->twoFactor->confirm($user, $data['code'], $request->session()->getId());

        if ($codes === null) {
            return back()->withErrors(['code' => 'That code is not valid. Check the code in your app and try again.']);
        }

        ActivityLogger::log(ActivityKind::AuthTwoFactorEnabled, user: $user);

        return back()
            ->with(self::RECOVERY_FLASH, $codes)
            ->with('status', 'Two-factor sign-in is on. Your other browsers were signed out.');
    }

    /** Turn it off, or cancel a setup that was never confirmed. */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Cancelling a setup that was never confirmed protects nothing yet, so it needs no password.
        if ($user->hasTwoFactorEnabled()) {
            $request->validate(['current_password' => ['required', 'current_password']]);
        }

        if ($user->hasTwoFactorEnabled() && config('serveravatar.require_two_factor')) {
            return back()->withErrors(['current_password' => 'Your administrator requires two-factor sign-in, so it cannot be turned off.']);
        }

        $wasEnabled = $user->hasTwoFactorEnabled();
        $this->twoFactor->disable($user);

        if ($wasEnabled) {
            ActivityLogger::log(ActivityKind::AuthTwoFactorDisabled, user: $user);
        }

        return back()->with('status', $wasEnabled ? 'Two-factor sign-in is off.' : 'Setup cancelled.');
    }

    public function regenerateCodes(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 404);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);

        ActivityLogger::log(ActivityKind::AuthTwoFactorCodesRegenerated, user: $user);

        return back()
            ->with(self::RECOVERY_FLASH, $codes)
            ->with('status', 'New recovery codes made. The old ones no longer work.');
    }
}
