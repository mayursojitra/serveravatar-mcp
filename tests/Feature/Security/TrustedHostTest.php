<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

afterEach(function () {
    TrustProxies::at([]);
    URL::forceRootUrl(null);
});

beforeEach(function () {
    config(['app.url' => 'https://mcp.example.test', 'serveravatar.trusted_hosts' => []]);

    // Relative test URLs are resolved against the URL generator's root, which was set from APP_URL before the line above.
    URL::forceRootUrl('https://mcp.example.test');
});

it('answers on the host of APP_URL', function () {
    $this->get('https://mcp.example.test/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://mcp.example.test');
});

it('refuses a forged forwarded host from a trusted proxy, so discovery documents cannot be poisoned', function () {
    TrustProxies::at('*');

    foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource/mcp', '/login'] as $path) {
        $this->withHeader('X-Forwarded-Host', 'evil.example')
            ->get('https://mcp.example.test'.$path)
            ->assertStatus(400)
            ->assertDontSee('evil.example/');
    }
});

it('refuses a forged forwarded host on /mcp before any authentication', function () {
    TrustProxies::at('*');

    $this->withHeader('X-Forwarded-Host', 'evil.example')
        ->postJson('https://mcp.example.test/mcp', [])
        ->assertStatus(400);
});

it('refuses a request sent straight to another Host', function () {
    $this->get('https://evil.example/login')->assertStatus(400);
});

it('never sends a password-reset link that points at the attacker', function () {
    Notification::fake();
    $user = User::factory()->create();
    TrustProxies::at('*');

    $this->withHeader('X-Forwarded-Host', 'evil.example')
        ->post('https://mcp.example.test/forgot-password', ['email' => $user->email])
        ->assertStatus(400);

    Notification::assertNothingSent();

    // The same request without the forged header goes through, and the link names the configured host.
    $this->flushHeaders()->post('https://mcp.example.test/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, fn ($notification) => str_starts_with(
        $notification->toMail($user)->actionUrl,
        'https://mcp.example.test/reset-password/',
    ));
});

it('also answers to hosts listed in TRUSTED_HOSTS, and no others', function () {
    config(['serveravatar.trusted_hosts' => ['Alias.Example.Test']]);

    $this->get('https://alias.example.test/login')->assertOk();
    $this->get('https://other.example.test/login')->assertStatus(400);
});

it('keeps loopback names working for local development', function () {
    $this->get('http://localhost/login')->assertOk();
    $this->get('http://127.0.0.1:8000/login')->assertOk();
});

it('leaves the health check open to any host, since orchestrators call it by container address', function () {
    $this->get('http://10.0.0.7:8080/up')->assertOk();
});

it('lets a host through whatever its port, and refuses a look-alike host', function () {
    $this->get('https://mcp.example.test:8443/login')->assertOk();
    $this->get('https://mcp.example.test.evil.example/login')->assertStatus(400);
    $this->get('https://evilmcp.example.test/login')->assertStatus(400);
});

// ---------------------------------------------------------------- response headers

it('forbids framing on every kind of response, so the consent page cannot be clickjacked', function () {
    $clientId = flow()->register();

    $responses = [
        $this->get('/login'),
        $this->get('/oauth/authorize?'.http_build_query(flow()->authorizeQuery($clientId))),
        $this->postJson('/mcp', []),
        $this->get('https://evil.example/login'),
    ];

    foreach ($responses as $response) {
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin');
    }
});

it('asks browsers to insist on HTTPS only for requests that came in over HTTPS', function () {
    expect($this->get('https://mcp.example.test/login')->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($this->get('http://localhost/login')->headers->get('Strict-Transport-Security'))->toBeNull();
});
