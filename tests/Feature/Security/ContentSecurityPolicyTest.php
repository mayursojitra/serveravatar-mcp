<?php

function nonceOf(string $csp): string
{
    preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m);

    return $m[1] ?? '';
}

it('sends a script and style policy on pages, with a nonce that the page tags carry', function () {
    $response = $this->get('/login')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    $nonce = nonceOf($csp);

    expect($nonce)->not->toBe('')
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("style-src 'self' 'nonce-{$nonce}'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");

    // Every script tag on the page (Ziggy's inline routes and the Vite module) carries this request's nonce.
    $html = $response->getContent();
    preg_match_all('/<script\b[^>]*>/', $html, $tags);
    expect($tags[0])->not->toBeEmpty();
    foreach ($tags[0] as $tag) {
        expect($tag)->toContain("nonce=\"{$nonce}\"");
    }
});

it('uses a new nonce on every request', function () {
    $first = nonceOf($this->get('/login')->headers->get('Content-Security-Policy'));
    $second = nonceOf($this->get('/login')->headers->get('Content-Security-Policy'));

    expect($first)->not->toBe('')->and($second)->not->toBe('')->and($first)->not->toBe($second);
});

it('allows nothing unsafe and nothing from other sites', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('unsafe-inline')
        ->and($csp)->not->toContain('unsafe-eval')
        ->and($csp)->not->toContain('http')
        ->and($csp)->not->toContain('*');
});

it('leaves out form-action, so the consent redirect to the AI client still works', function () {
    expect($this->get('/login')->headers->get('Content-Security-Policy'))->not->toContain('form-action');
});

it('loads no font or other file from another site', function () {
    $html = $this->get('/login')->getContent();

    expect($html)->not->toContain('bunny.net');
    preg_match_all('/(?:src|href)="(https?:\/\/[^"]+)"/', $html, $m);
    $own = parse_url(config('app.url'), PHP_URL_HOST);
    foreach ($m[1] as $url) {
        expect(parse_url($url, PHP_URL_HOST))->toBe($own);
    }
});

it('gives machine endpoints only the framing ban', function () {
    $this->get('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});

it('can run report-only while keeping the framing ban enforced', function () {
    config(['serveravatar.csp' => 'report-only']);

    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("script-src 'self' 'nonce-");
});

it('can be switched off, which still bans framing', function () {
    config(['serveravatar.csp' => 'off']);

    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});
