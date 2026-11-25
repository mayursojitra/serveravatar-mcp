<?php

namespace App\Services\Account;

use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor sign-in with an authenticator app (TOTP, RFC 6238) and single-use recovery codes.
 *
 * - The secret is stored encrypted (the `encrypted` cast on User). Recovery codes are stored only as
 *   a keyed hash, so a copy of the database holds nothing that works.
 * - A code is accepted once: the 30-second step it belongs to is saved with a conditional UPDATE, so
 *   two requests racing with the same code cannot both pass.
 * - Two-factor sign-in applies only after the user confirmed the app with a first code.
 */
class TwoFactor
{
    /** How many recovery codes a user gets. */
    public const RECOVERY_CODES = 8;

    /** Steps (of 30 seconds) accepted on either side of the current one, to allow for clock drift. */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa, private readonly AccountAccess $access) {}

    /**
     * Start a setup: a new secret that does not protect the account until confirm() succeeds.
     * Starting again replaces an unconfirmed secret.
     */
    public function begin(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->google2fa->generateSecretKey(32),
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $user->recoveryCodes()->delete();
    }

    /** The key to type into an authenticator app by hand, grouped for reading. */
    public function manualKey(User $user): string
    {
        return trim(chunk_split((string) $user->two_factor_secret, 4, ' '));
    }

    /** The QR code of the setup, as an SVG data URI, so no outside service ever sees the secret. */
    public function qrCodeDataUri(User $user): string
    {
        $url = $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, (string) $user->two_factor_secret);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Finish a setup with the first code from the app. Turns two-factor sign-in on, makes the recovery
     * codes, and signs the user out everywhere else so cookies issued without the second factor stop working.
     *
     * @return list<string>|null the recovery codes (shown once), or null when the code was wrong
     */
    public function confirm(User $user, string $code, ?string $keepSessionId = null): ?array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        if (! $this->acceptCode($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->access->endSessions($user, $keepSessionId);

        return $this->regenerateRecoveryCodes($user);
    }

    /** Check a code from the app and use it up. False for a wrong, expired or already used code. */
    public function acceptCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->two_factor_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = $this->google2fa->verifyKeyNewer(
            (string) $user->two_factor_secret,
            $code,
            // Never null: with no earlier step the library answers `true` instead of the step it matched.
            (int) $user->two_factor_last_step,
            self::WINDOW,
        );

        if ($step === false || $step === true) {
            return false;
        }

        // Save the step only if nobody saved a newer one meanwhile. One affected row means this request won.
        $won = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);

        if ($won !== 1) {
            return false;
        }

        $user->two_factor_last_step = $step;
        $user->syncOriginalAttribute('two_factor_last_step');

        return true;
    }

    /** Check a recovery code and use it up. Each code works once. */
    public function acceptRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);

        if ($normalized === '') {
            return false;
        }

        $row = $user->recoveryCodes()
            ->whereNull('used_at')
            ->where('code_hash', $this->hash($normalized))
            ->first();

        if ($row === null) {
            return false;
        }

        // Mark it used only if it still is unused: a second request with the same code changes no row.
        return TwoFactorRecoveryCode::query()
            ->whereKey($row->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /**
     * Replace all recovery codes with a fresh set.
     *
     * @return list<string> the new codes, in the form they are typed (shown once)
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];

        DB::transaction(function () use ($user, &$codes) {
            $user->recoveryCodes()->delete();

            for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
                $raw = Str::lower(Str::random(10));
                $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);

                $user->recoveryCodes()->create(['code_hash' => $this->hash($raw)]);
            }
        });

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return $user->recoveryCodes()->whereNull('used_at')->count();
    }

    /** Turn two-factor sign-in off and forget the secret and every recovery code. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $user->recoveryCodes()->delete();
    }

    /** Disable, then end every session of the user (an admin or the command reset a lost device). */
    public function reset(User $user): void
    {
        $this->disable($user);
        $this->access->endSessions($user);
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($code)) ?? '';
    }

    private function hash(string $normalizedCode): string
    {
        return hash_hmac('sha256', $normalizedCode, (string) config('app.key'));
    }
}
