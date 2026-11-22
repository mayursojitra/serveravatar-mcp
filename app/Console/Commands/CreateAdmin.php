<?php

namespace App\Console\Commands;

use App\Enums\ActivityKind;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * The only way to make an admin. There is no sign-up and no promote step: an existing account is
 * never turned into an admin, so this command refuses an email that is already taken.
 */
class CreateAdmin extends Command
{
    protected $signature = 'serveravatar:create-admin {email : Login email} {--name= : Display name}';

    protected $description = 'Create an admin account (the only way to create one)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name')) ?: 'Admin';

        $secret = $this->resolvePassword();

        if ($secret === null) {
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with this email already exists. Existing accounts are never turned into admins.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $secret],
            ['name' => ['max:255'], 'email' => ['required', 'email', 'max:255'], 'password' => [Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // role is not mass assignable, so it is set explicitly here and nowhere else.
        $user = new User(['name' => $name, 'email' => $email, 'password' => $secret]);
        $user->role = User::ROLE_ADMIN;
        $user->save();

        ActivityLogger::log(ActivityKind::AdminAdminCreated, user: $user, fields: ['meta' => ['via' => 'cli']]);

        $this->info("Admin {$email} created.");

        return self::SUCCESS;
    }

    /** From the ADMIN_PASSWORD environment variable, or a hidden prompt. Never from an argument. */
    private function resolvePassword(): ?string
    {
        $fromEnv = getenv('ADMIN_PASSWORD');

        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Set ADMIN_PASSWORD in the environment, or run this command interactively.');

            return null;
        }

        $first = password('Password (input hidden)', required: true);
        $second = password('Confirm password (input hidden)', required: true);

        if ($first !== $second) {
            $this->error('The passwords do not match.');

            return null;
        }

        return $first;
    }
}
