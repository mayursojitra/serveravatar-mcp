<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\ActivityKind;
use App\Enums\PermissionLevel;
use App\Models\McpGrant;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approving a connection records the permission level the user chose, then lets Passport issue the
 * authorization code. The grant is keyed by the user and the client taken from the stored
 * authorization request, never from the submitted form.
 */
class ApproveAuthorizationController extends PassportApproveAuthorizationController
{
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        // The token could have been deleted since the consent screen opened.
        if (! $request->user()->saToken()->exists()) {
            return redirect()->route('token.show')->with('error', 'Save your ServerAvatar token first, then connect again.');
        }

        $validated = $request->validate([
            'level' => ['required', Rule::enum(PermissionLevel::class)],
        ]);

        // Throws if the one-time token does not match the session: no grant is created then.
        $authRequest = $this->getAuthRequestFromSession($request);

        $user = $request->user();
        $clientId = $authRequest->getClient()->getIdentifier();

        $grant = McpGrant::updateOrCreate(
            ['user_id' => $user->id, 'oauth_client_id' => $clientId],
            ['level' => $validated['level']],
        );

        ActivityLogger::log(ActivityKind::McpConnectionAuthorized, user: $user, fields: [
            'mcp_grant_id' => $grant->id,
            'client_name' => $authRequest->getClient()->getName(),
            'meta' => ['level' => $validated['level'], 'created' => $grant->wasRecentlyCreated],
        ]);

        $authRequest->setAuthorizationApproved(true);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), $authRequest->getGrantTypeId() === 'implicit');
    }
}
