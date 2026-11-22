<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose Host (or a forwarded host, from a trusted proxy) is not this app's own address.
 *
 * Without this, a caller can send "X-Forwarded-Host: evil.example" and the app builds its absolute URLs
 * from it: the OAuth discovery documents and, worse, the link in a password-reset e-mail. Reverse
 * proxies and tunnels pass a client's forwarded headers through, so trusting the proxy is not enough.
 *
 * Allowed: the host of APP_URL, anything listed in TRUSTED_HOSTS, and loopback names (so local
 * development and health checks work). The health route is exempt because orchestrators call it by
 * container address.
 */
class EnsureTrustedHost
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up') || in_array($request->getHost(), $this->allowedHosts(), true)) {
            return $next($request);
        }

        return response(
            "Invalid host. Set APP_URL to the address you open this app at (and TRUSTED_HOSTS if it has more than one).\n",
            400,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = self::LOOPBACK;

        if ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) {
            $hosts[] = strtolower($host);
        }

        foreach ((array) config('serveravatar.trusted_hosts', []) as $extra) {
            $hosts[] = strtolower(trim($extra));
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
