<?php

namespace App\Services\Mcp;

use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Cuts connections off for good: the access tokens, the refresh tokens (a refresh does not
 * re-check the user), any authorization code not yet exchanged, and the grant itself.
 */
class ConnectionRevoker
{
    /**
     * Revoke every connection of a user. Returns how many connections (grants) were revoked.
     */
    public function revokeAllForUser(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $count = McpGrant::where('user_id', $user->id)->count();

            $this->revokeTokens($user->id, null);
            McpGrant::where('user_id', $user->id)->delete();

            return $count;
        });
    }

    /** Revoke one connection (one AI client of one user). */
    public function revokeGrant(McpGrant $grant): void
    {
        DB::transaction(function () use ($grant): void {
            $this->revokeTokens($grant->user_id, $grant->oauth_client_id);
            $grant->delete();
        });
    }

    private function revokeTokens(int $userId, ?string $clientId): void
    {
        $tokens = Passport::token()->newQuery()
            ->where('user_id', $userId)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));

        $tokenIds = (clone $tokens)->pluck('id');

        $tokens->update(['revoked' => true]);

        Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', $tokenIds)
            ->update(['revoked' => true]);

        Passport::authCode()->newQuery()
            ->where('user_id', $userId)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->update(['revoked' => true]);
    }
}
