<?php

namespace App\Console\Commands;

use App\Enums\ActivityKind;
use App\Models\User;
use App\Services\Account\TwoFactor;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;

/**
 * The way back in for a user who lost their authenticator app and recovery codes, and the only way for
 * an admin: the web interface never manages admin accounts. There is no email link on purpose, since
 * that would make two-factor sign-in only as strong as the mailbox.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'serveravatar:reset-two-factor {email : Email of the account}';

    protected $description = 'Turn two-factor sign-in off for an account and sign it out everywhere';

    public function handle(TwoFactor $twoFactor): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error('No account has this email.');

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null) {
            $this->info("{$email} does not use two-factor sign-in. Nothing to reset.");

            return self::SUCCESS;
        }

        $twoFactor->reset($user);

        ActivityLogger::log(ActivityKind::AdminTwoFactorReset, user: $user, fields: [
            'meta' => ['via' => 'cli', 'target_user_id' => $user->id, 'target_email' => $user->email],
        ]);

        $this->info("Two-factor sign-in is off for {$email}, and every session was ended. They can set it up again after signing in.");

        return self::SUCCESS;
    }
}
