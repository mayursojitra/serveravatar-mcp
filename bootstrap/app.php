<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureMustChangePassword;
use App\Http\Middleware\EnsureTrustedHost;
use App\Http\Middleware\EnsureTwoFactorIsSetUp;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Every request, including /mcp and the OAuth discovery documents, must name this app's own host.
        // The headers go first so they also wrap the refusal below.
        $middleware->append([AddSecurityHeaders::class, EnsureTrustedHost::class]);

        // Applied to every web route, so new routes (OAuth consent in M2) are covered automatically.
        // AuthenticateSession signs out every session whose stored password hash no longer matches, so a
        // changed or reset password ends the user's other browser sessions with any session driver.
        $middleware->web(append: [
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
            EnsureMustChangePassword::class,
            EnsureTwoFactorIsSetUp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A failed save redirects back with the old input. The ServerAvatar token must never be flashed
        // into the session, where it would sit in plain text in the sessions table. The same goes for a
        // wrong two-factor code or recovery code.
        $exceptions->dontFlash(['token', 'code', 'recovery_code']);

        // Machine endpoints always answer in JSON. Without this, a client that lists text/event-stream
        // before application/json would get a redirect to /login instead of the 401 that starts its
        // OAuth discovery. The consent page (/oauth/authorize) stays a normal browser page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('mcp', 'mcp/*', 'oauth/token', 'oauth/register', '.well-known/*')
            || $request->expectsJson());
    })->create();
