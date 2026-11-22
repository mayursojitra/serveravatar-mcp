<?php

namespace App\Mcp;

use App\Enums\PermissionLevel;
use App\Models\McpGrant;
use App\Models\User;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\NoToken;

/**
 * Who is calling /mcp and what they may do, resolved once per request from the OAuth access token
 * and the user's grant. Tools read the level from here; they never look at the token themselves.
 */
final class McpConnection
{
    private ?Client $serverAvatar = null;

    public function __construct(
        public readonly User $user,
        public readonly McpGrant $grant,
        public readonly string $clientName,
    ) {}

    /**
     * The ServerAvatar client for this user's token. The token is decrypted here, the first time a tool
     * actually needs to call ServerAvatar (FR-5), not when the connection is resolved.
     *
     * @throws NoToken
     */
    public function serverAvatar(): Client
    {
        if ($this->serverAvatar === null) {
            $token = $this->user->saToken;

            if ($token === null) {
                throw new NoToken('No ServerAvatar token is saved for this user. Save one on the ServerAvatar token page in the web app; this connection will then work again.');
            }

            $this->serverAvatar = new Client($token->revealToken());
        }

        return $this->serverAvatar;
    }

    /** The level is read from the grant on every request, so a change on /connections applies at once. */
    public function level(): PermissionLevel
    {
        return $this->grant->level;
    }
}
