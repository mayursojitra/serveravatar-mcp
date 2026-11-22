<?php

namespace App\Services\ServerAvatar;

use App\Models\SaToken;
use App\Models\User;
use App\Services\Mcp\ConnectionRevoker;
use App\Services\ServerAvatar\Exceptions\AuthenticationFailed;
use App\Services\ServerAvatar\Exceptions\ConnectionFailed;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use Illuminate\Support\Facades\DB;

/**
 * Saves, checks and removes a user's one ServerAvatar token (FR-4 to FR-6). A token is checked
 * against ServerAvatar before anything is stored, so a wrong or mistyped token never replaces a
 * good one. It is stored encrypted and is never returned by anything here except to the client.
 */
class TokenVault
{
    public function __construct(private readonly ConnectionRevoker $revoker) {}

    /**
     * Verify, then store (or replace). Existing connections keep working after a replace.
     *
     * @return array{token: SaToken, replaced: bool}
     *
     * @throws InvalidToken
     */
    public function store(User $user, string $raw): array
    {
        $token = TokenNormalizer::normalize($raw);

        if ($token === '') {
            throw new InvalidToken('Paste your ServerAvatar API token.');
        }

        if (preg_match('/\s/', $token)) {
            throw new InvalidToken('A token has no spaces. Paste just the token.');
        }

        $organizations = $this->check($token);
        $replaced = $user->saToken()->exists();

        $saved = SaToken::updateOrCreate(['user_id' => $user->id], [
            'token' => $token,
            'last4' => substr($token, -4),
            'organizations' => $organizations,
            'verified_at' => now(),
            'last_error' => null,
        ]);

        $user->unsetRelation('saToken');

        return ['token' => $saved, 'replaced' => $replaced];
    }

    /**
     * Check the saved token again. On failure the reason is kept on the token, so the page can show it.
     *
     * @throws InvalidToken
     */
    public function test(User $user): SaToken
    {
        $saved = $user->saToken()->firstOrFail();

        try {
            $organizations = $this->check($saved->revealToken());
        } catch (InvalidToken $e) {
            $saved->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);

            throw $e;
        }

        $saved->update(['organizations' => $organizations, 'verified_at' => now(), 'last_error' => null]);

        return $saved;
    }

    /** Remember that ServerAvatar rejected the token while a tool was using it. */
    public function markRejected(User $user, string $message): void
    {
        $user->saToken()->update(['last_error' => mb_substr($message, 0, 250)]);
    }

    /**
     * Delete the token and disconnect every AI client of the user until a new one is saved.
     *
     * @return int how many connections were revoked
     */
    public function delete(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $revoked = $this->revoker->revokeAllForUser($user);
            $user->saToken()->delete();
            $user->unsetRelation('saToken');

            return $revoked;
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     *
     * @throws InvalidToken
     */
    private function check(string $token): array
    {
        try {
            return (new Client($token))->organizations();
        } catch (AuthenticationFailed) {
            throw new InvalidToken('ServerAvatar rejected this token. Check that you copied all of it.');
        } catch (ConnectionFailed) {
            throw new InvalidToken('Could not reach ServerAvatar to check the token. Try again shortly.');
        } catch (ServerAvatarException $e) {
            throw new InvalidToken($e->getMessage());
        }
    }
}
