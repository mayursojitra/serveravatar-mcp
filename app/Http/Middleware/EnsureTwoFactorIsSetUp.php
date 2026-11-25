<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When REQUIRE_TWO_FACTOR is on, a user without two-factor sign-in can use nothing but the security
 * settings page (and the set-password pages and logout) until they set it up. Registered on the whole
 * web group, after EnsureMustChangePassword, so a new user chooses a password first and then sets this
 * up, and the OAuth consent screen is covered without extra work.
 */
class EnsureTwoFactorIsSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('serveravatar.require_two_factor')
            || $user === null
            || $user->hasTwoFactorEnabled()
            || $request->routeIs('password.set', 'password.set.store', 'logout', 'security.edit', 'two-factor.*')) {
            return $next($request);
        }

        // Remember the page they were heading to (for example the OAuth consent screen), so they return to it afterwards.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('security.edit')
            ->with('status', 'Your administrator requires two-factor sign-in. Set it up to continue.');
    }
}
