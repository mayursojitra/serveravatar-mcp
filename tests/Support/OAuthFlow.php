<?php

namespace Tests\Support;

use App\Models\SaToken;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Plays the part of an AI client talking to the app for real: it registers itself, goes through
 * the consent screen with PKCE, swaps the code for tokens and then calls /mcp with the access
 * token. Nothing is faked, so the tests exercise the same path claude.ai would.
 */
final class OAuthFlow
{
    public string $verifier;

    public string $challenge;

    public string $redirect = 'http://localhost:7777/callback';

    /** @param  TestCase  $test  Pest hands over a proxy that forwards to the test case. */
    public function __construct(private readonly object $test)
    {
        $this->verifier = Str::random(64);
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    /** Dynamic client registration. Returns the new client id. */
    public function register(string $name = 'Test Client', ?string $redirect = null): string
    {
        $response = $this->test->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => [$redirect ?? $this->redirect],
        ]);

        $response->assertCreated();

        return $response->json('client_id');
    }

    /** @return array<string, string> */
    public function authorizeQuery(string $clientId, ?string $state = 'state-123'): array
    {
        return array_filter([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirect,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'resource' => url('/mcp'),
            'state' => $state,
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** A saved ServerAvatar token is needed to connect (FR-4). Written directly, without calling ServerAvatar. */
    public function ensureToken(User $user): void
    {
        if (! $user->saToken()->exists()) {
            SaToken::factory()->for($user)->create();
        }
    }

    /** The consent page, as the signed-in user. */
    public function consent(User $user, string $clientId, bool $withToken = true): TestResponse
    {
        if ($withToken) {
            $this->ensureToken($user);
        }

        return $this->test->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($clientId)));
    }

    /** The one-time token the consent page puts in its forms. */
    public function authToken(TestResponse $consent): string
    {
        return $consent->viewData('page')['props']['authToken'];
    }

    /** Opens the consent page and submits the approve form. Returns the response to the approval. */
    public function approve(User $user, string $clientId, ?string $level = 'read', array $extra = []): TestResponse
    {
        $consent = $this->consent($user, $clientId);
        $consent->assertOk();

        return $this->test->post('/oauth/authorize', array_merge(
            ['auth_token' => $this->authToken($consent), 'level' => $level],
            $extra,
        ));
    }

    /** The authorization code in the redirect that follows an approval. */
    public function codeFrom(TestResponse $approval): string
    {
        $approval->assertRedirect();
        parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['code'] ?? throw new \RuntimeException('No code in '.$approval->headers->get('Location'));
    }

    public function exchange(string $clientId, string $code, ?string $verifier = null): TestResponse
    {
        return $this->test->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirect,
            'code' => $code,
            'code_verifier' => $verifier ?? $this->verifier,
            'resource' => url('/mcp'),
        ]);
    }

    public function refresh(string $clientId, string $refreshToken): TestResponse
    {
        return $this->test->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'resource' => url('/mcp'),
        ]);
    }

    /**
     * The whole journey: register, consent, approve, exchange.
     *
     * @return array{client_id: string, access_token: string, refresh_token: string}
     */
    public function connect(User $user, string $level = 'read', string $name = 'Test Client'): array
    {
        $clientId = $this->register($name);
        $code = $this->codeFrom($this->approve($user, $clientId, $level));
        $tokens = $this->exchange($clientId, $code);
        $tokens->assertOk();

        return [
            'client_id' => $clientId,
            'access_token' => $tokens->json('access_token'),
            'refresh_token' => $tokens->json('refresh_token'),
        ];
    }

    /** A JSON-RPC request to /mcp with the given bearer token. */
    public function rpc(?string $accessToken, string $method, array $params = [], int|string|null $id = 1): TestResponse
    {
        // The API guard keeps the user it resolved for the previous request.
        app('auth')->forgetGuards();

        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($accessToken !== null) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        $body = ['jsonrpc' => '2.0', 'method' => $method];

        if ($id !== null) {
            $body['id'] = $id;
        }

        if ($params !== []) {
            $body['params'] = $params;
        }

        $response = $this->test->withHeaders($headers)->postJson('/mcp', $body);

        // /mcp makes the API guard the default, and one test app serves every request of a test. Real
        // requests each start fresh, so give the next (web) request the web guard back.
        app('auth')->shouldUse('web');

        return $response;
    }

    /** Sends raw text to /mcp, for malformed messages. */
    public function rawRpc(string $accessToken, string $raw): TestResponse
    {
        app('auth')->forgetGuards();

        $response = $this->test->call('POST', '/mcp', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        app('auth')->shouldUse('web');

        return $response;
    }

    public function initialize(string $accessToken): TestResponse
    {
        return $this->rpc($accessToken, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test-harness', 'version' => '1.0'],
        ]);
    }

    /** @return list<string> the tool names the connection can see */
    public function toolNames(string $accessToken): array
    {
        return collect($this->rpc($accessToken, 'tools/list')->json('result.tools'))->pluck('name')->all();
    }
}
