<?php

namespace App\Http\Controllers\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport skips the consent screen when the user already authorized the client. Here the screen
 * always shows, because it is where the user picks the permission level for the connection: a
 * client that comes back must never get tokens without that choice being made again.
 *
 * A user with no saved ServerAvatar token is sent to the token page first (FR-4): a connection
 * with nothing behind it would be useless. They come back to this screen once the token is saved.
 */
class AuthorizationController extends PassportAuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        $user = $request->user();

        if ($user !== null && ! $user->saToken()->exists()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('token.show')
                ->with('error', 'Save your ServerAvatar token first. You will come back to connect your AI client.');
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
