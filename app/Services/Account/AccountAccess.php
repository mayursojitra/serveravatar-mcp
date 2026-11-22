<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Mcp\ConnectionRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuts off whatever a user was already signed in with. A password change or reset often means the
 * old password was exposed, so everything that was opened with it must stop working: browser
 * sessions and every connected AI client (access tokens, refresh tokens, unused authorization codes).
 *
 * Sessions: with the database session driver the user's rows are deleted at once. With any driver,
 * Laravel's AuthenticateSession middleware also signs out every session whose stored password hash
 * no longer matches, so a changed password ends the other sessions even where rows cannot be listed.
 */
class AccountAccess
{
    public function __construct(private readonly ConnectionRevoker $revoker) {}

    /**
     * End the user's browser sessions (all of them, or all but one) and rotate the remember-me token.
     *
     * @param  string|null  $exceptSessionId  the session to keep, such as the one that just changed the password
     */
    public function endSessions(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * After a new password was saved: end the other sessions and disconnect every AI client.
     *
     * @return int how many AI client connections were revoked
     */
    public function afterPasswordChange(User $user, ?string $keepSessionId = null): int
    {
        $this->endSessions($user, $keepSessionId);

        return $this->revoker->revokeAllForUser($user);
    }
}
