<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that cost nothing and close common browser-side holes. Most important is the
 * framing ban: the OAuth consent page must never be loadable inside another site's frame, or a
 * hidden "Approve" button could be clicked for the user.
 *
 * HTML pages also get a Content-Security-Policy that allows scripts and styles only from this app
 * itself or carrying this request's nonce, so an injected script or style tag does not run. The
 * policy deliberately has no form-action: the consent page redirects back to the AI client's own
 * address (claude.ai, http://localhost:PORT, cursor://...), and form-action would block that redirect.
 *
 * serveravatar.csp: "enforce" (default), "report-only" (the browser only logs violations to its
 * console; for trying a change) or "off". Frame blocking is always enforced.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // A fresh nonce per request, set before the view renders so @vite and @routes can stamp it
        // on their tags.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        $headers += $this->contentSecurityPolicy($response);

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function contentSecurityPolicy(Response $response): array
    {
        $frames = "frame-ancestors 'none'";

        $mode = config('serveravatar.csp', 'enforce');
        $isHtml = str_contains((string) $response->headers->get('Content-Type'), 'text/html');

        // Only pages need the full policy. While the Vite dev server runs (`npm run dev`) the page
        // loads scripts from another origin, so only the frame ban is applied.
        if ($mode === 'off' || ! $isHtml || Vite::isRunningHot()) {
            return ['Content-Security-Policy' => $frames];
        }

        $nonce = Vite::cspNonce();

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            $frames,
        ]);

        // frame-ancestors is ignored in a report-only policy, so the ban stays in its own enforced header.
        return $mode === 'report-only'
            ? ['Content-Security-Policy' => $frames, 'Content-Security-Policy-Report-Only' => $policy]
            : ['Content-Security-Policy' => $policy];
    }
}
