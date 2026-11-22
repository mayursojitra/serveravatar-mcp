<?php

namespace App\Providers;

use App\Enums\PermissionLevel;
use App\Http\Controllers\OAuth\ApproveAuthorizationController;
use App\Http\Controllers\OAuth\AuthorizationController;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Passport's routes build their controllers through the container, so these bindings swap in
        // ours while the route names, URLs and middleware stay Passport's (the MCP discovery
        // metadata points at them): consent always shows, and approving records the level.
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);
        $this->app->bind(PassportApproveAuthorizationController::class, ApproveAuthorizationController::class);

        // Passport gives its own controller the web guard through a binding keyed to that exact class;
        // the subclass needs the same one.
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard', null)));

        // Only the authorization-code flow (with PKCE) is used. Switch the device-code flow off before
        // Passport registers its routes, so its endpoints do not exist.
        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(): void
    {
        // NFR-7: trust no proxy unless TRUSTED_PROXIES says so, so the IP in the activity log is real.
        TrustProxies::at(config('serveravatar.trusted_proxies'));

        // NFR-8: strong passwords everywhere. The breach check calls an external API, so it runs in production only.
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->configurePassport();
        $this->configureRateLimits();
    }

    private function configurePassport(): void
    {
        // Short-lived access tokens; clients renew them silently with the longer-lived refresh token.
        Passport::tokensExpireIn(CarbonInterval::minutes(config('serveravatar.oauth.access_token_minutes')));
        Passport::refreshTokensExpireIn(CarbonInterval::days(config('serveravatar.oauth.refresh_token_days')));

        // The one scope clients request. laravel/mcp registers it too; doing it here as well keeps it
        // available when routes are cached.
        Registrar::ensureMcpScope();

        // The consent screen is an Inertia page. Only explicit values are passed on, never the request
        // or the whole client model.
        Passport::authorizationView(function (array $parameters) {
            $client = $parameters['client'];
            $request = $parameters['request'];
            $redirectUri = (string) $request->query('redirect_uri', $client->redirect_uris[0] ?? '');

            return Inertia::render('oauth/authorize', [
                'clientName' => $client->name,
                'redirectUri' => $redirectUri,
                'redirectHost' => parse_url($redirectUri, PHP_URL_HOST) ?: parse_url($redirectUri, PHP_URL_SCHEME) ?: $redirectUri,
                'userEmail' => $parameters['user']->email,
                'authToken' => $parameters['authToken'],
                'levels' => PermissionLevel::options(),
                'csrfToken' => csrf_token(),
            ]);
        });
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(config('serveravatar.rate_limits.mcp'))
            ->by($request->user('api')?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('token-writes', fn (Request $request) => Limit::perMinute(config('serveravatar.rate_limits.token_writes'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip())));

        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perMinute(config('serveravatar.rate_limits.oauth_register'))
            ->by($request->ip()));
    }
}
