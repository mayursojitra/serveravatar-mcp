<?php

use App\Http\Middleware\ResolveMcpConnection;
use App\Mcp\Servers\ServerAvatarServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

// OAuth discovery metadata (RFC 9728, RFC 8414). Public, and deliberately not throttled with client
// registration: claude.ai calls from shared addresses, and a whole team would share one budget.
Mcp::oauthRoutes(registration: false);

// Dynamic client registration (RFC 7591) is open by design (claude.ai registers itself), so it is
// throttled per IP. The route name lets the metadata advertise it.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:oauth-register')
    ->name('mcp.oauth.register');

// The MCP endpoint. Only a user's own OAuth connection can call it: auth:api checks the access
// token, then ResolveMcpConnection finds the user's grant (permission level) for that client.
Mcp::web('/mcp', ServerAvatarServer::class)
    ->middleware(['auth:api', 'throttle:mcp', ResolveMcpConnection::class]);
