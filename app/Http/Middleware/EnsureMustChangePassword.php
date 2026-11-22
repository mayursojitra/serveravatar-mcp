<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-1b: a user created by an admin, or whose password an admin reset, can use nothing but the
 * set-password page and logout until a new password is saved. Registered on the whole web group,
 * so later routes (such as the OAuth consent screen) are covered without extra work.
 */
class EnsureMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('password.set', 'password.set.store', 'logout')) {
            // Remember the page they were heading to (for example the OAuth consent screen), so they
            // return to it after choosing a password instead of landing on the dashboard.
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('password.set');
        }

        return $next($request);
    }
}
