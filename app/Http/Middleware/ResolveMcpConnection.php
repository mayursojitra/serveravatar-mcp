<?php

namespace App\Http\Middleware;

use App\Enums\ActivityKind;
use App\Mcp\McpConnection;
use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:api` on /mcp. A valid access token only says which user and which AI client;
 * this turns it into a connection (the user's grant for that client) or refuses the request.
 */
class ResolveMcpConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('api');
        $client = Auth::guard('api')->client();

        if (! $user || ! $client) {
            return $this->refuse(401, 'Invalid or expired access token.');
        }

        $clientName = (string) $client->name;

        if ($user->disabled) {
            return $this->refuse(403, 'This account is disabled.', $user, $clientName, 'disabled');
        }

        if ($user->must_change_password) {
            return $this->refuse(403, 'Set a new password in the web app before using this connection.', $user, $clientName, 'password_change_required');
        }

        $grant = McpGrant::where('user_id', $user->id)->where('oauth_client_id', $client->getKey())->first();

        if (! $grant) {
            // The connection was revoked (the tokens are revoked with it; this covers any gap).
            return $this->refuse(401, 'This connection was revoked. Reconnect from your AI client.', $user, $clientName, 'no_grant');
        }

        // Deleting the token revokes the connections, so this is only reached by an inconsistent state.
        if (! $user->saToken()->exists()) {
            return $this->refuse(403, 'No ServerAvatar token is saved for this user. Save one on the ServerAvatar token page in the web app; this connection will then work again.', $user, $clientName, 'no_token');
        }

        app()->instance(McpConnection::class, new McpConnection($user, $grant, $clientName));

        $grant->markUsed();

        return $next($request);
    }

    private function refuse(int $status, string $message, ?User $user = null, ?string $clientName = null, ?string $reason = null): Response
    {
        if ($user) {
            ActivityLogger::log(ActivityKind::McpRejected, ActivityLog::STATUS_DENIED, $user, [
                'client_name' => $clientName,
                'error' => $message,
                'meta' => ['reason' => $reason],
            ]);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => $message],
        ], $status);
    }
}
