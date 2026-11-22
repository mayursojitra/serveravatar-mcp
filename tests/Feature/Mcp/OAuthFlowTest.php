<?php

use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// ---------------------------------------------------------------- discovery and registration

it('publishes OAuth discovery metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
        ->assertJsonFragment(['scopes_supported' => ['mcp:use']]);

    $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
});

it('registers a client from claude.ai, from localhost and from desktop apps', function (string $redirect) {
    $response = $this->postJson('/oauth/register', ['client_name' => 'Some Client', 'redirect_uris' => [$redirect]]);

    $response->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none');
    expect($response->json('client_id'))->toBeString();
})->with([
    'claude.ai' => 'https://claude.ai/api/mcp/auth_callback',
    'claude.com' => 'https://claude.com/api/mcp/auth_callback',
    'claude code on localhost' => 'http://localhost:54545/callback',
    'loopback address' => 'http://127.0.0.1:8123/cb',
    'cursor' => 'cursor://anysphere.cursor-retrieval/oauth/callback',
]);

it('refuses a client that wants to be redirected somewhere else', function (string $redirect) {
    $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => [$redirect]])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'another https site' => 'https://evil.example/callback',
    'plain http elsewhere' => 'http://evil.example/callback',
    'a look-alike host' => 'https://claude.ai.evil.example/cb',
    'an unlisted scheme' => 'myapp://callback',
    'credentials in the url' => 'https://user:pass@claude.ai/cb',
]);

it('never allows every redirect domain', function () {
    expect(config('mcp.redirect_domains'))->not->toContain('*');
});

it('throttles open client registration per IP', function () {
    config(['serveravatar.rate_limits.oauth_register' => 2]);

    foreach ([1, 2] as $i) {
        $this->postJson('/oauth/register', ['client_name' => "C{$i}", 'redirect_uris' => ['http://localhost:1/cb']])->assertCreated();
    }

    $this->postJson('/oauth/register', ['client_name' => 'C3', 'redirect_uris' => ['http://localhost:1/cb']])->assertStatus(429);
});

it('has no device-code, password or implicit flows', function () {
    expect(Route::has('passport.device'))->toBeFalse();
    $this->post('/oauth/device/code')->assertNotFound();
    $this->get('/oauth/device')->assertNotFound();

    $this->post('/oauth/token', ['grant_type' => 'password', 'username' => 'a@b.test', 'password' => 'x', 'client_id' => 'x'])
        ->assertStatus(400);
});

// ---------------------------------------------------------------- the consent screen

it('shows the consent screen with the choices and never the client secret internals', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $clientId = flow()->register('My Claude');

    $this->actingAs($user);
    $response = flow()->consent($user, $clientId);

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('oauth/authorize')
        ->where('clientName', 'My Claude')
        ->where('redirectHost', 'localhost')
        ->where('userEmail', 'sam@example.test')
        ->has('levels', 3)
        ->where('levels.0.value', 'read')
        ->has('authToken')
        ->has('csrfToken')
        ->missing('client')
        ->missing('request'));
});

it('sends a guest to sign in and brings them back to the consent screen', function () {
    $user = User::factory()->create();
    flow()->ensureToken($user);
    $clientId = flow()->register();
    $url = '/oauth/authorize?'.http_build_query(flow()->authorizeQuery($clientId));
    // Laravel remembers the address with its query string sorted.
    $remembered = Request::create(config('app.url').$url)->fullUrl();

    $this->get($url)->assertRedirect(route('login'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($remembered);
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

it('makes a user with a temporary password choose one first, then returns to the consent screen', function () {
    $user = User::factory()->mustChangePassword()->create();
    $clientId = flow()->register();
    $url = '/oauth/authorize?'.http_build_query(flow()->authorizeQuery($clientId));

    $remembered = Request::create(config('app.url').$url)->fullUrl();

    $this->actingAs($user)->get($url)->assertRedirect(route('password.set'));

    $this->post('/set-password', [
        'current_password' => 'password',
        'password' => 'Brand-New-Passw0rd',
        'password_confirmation' => 'Brand-New-Passw0rd',
    ])->assertRedirect($remembered);

    expect(McpGrant::count())->toBe(0);
});

it('does not let a client with a pending password change approve anything', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->post('/oauth/authorize', ['auth_token' => 'x', 'level' => 'full'])
        ->assertRedirect(route('password.set'));

    expect(McpGrant::count())->toBe(0);
});

it('requires PKCE, because the clients are public', function () {
    $user = User::factory()->create();
    flow()->ensureToken($user);
    $clientId = flow()->register();
    $query = flow()->authorizeQuery($clientId);
    unset($query['code_challenge'], $query['code_challenge_method']);

    $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query));

    expect($response->getStatusCode() === 200 && ($response->viewData('page')['component'] ?? null) === 'oauth/authorize')->toBeFalse();
    expect(McpGrant::count())->toBe(0);
});

// ---------------------------------------------------------------- approving

it('records the chosen level when the user approves', function () {
    $user = User::factory()->create();
    $clientId = flow()->register('Claude');

    $approval = flow()->approve($user, $clientId, 'action');

    $approval->assertRedirect();
    expect($approval->headers->get('Location'))->toStartWith('http://localhost:7777/callback?')
        ->and(flow()->codeFrom($approval))->not->toBeEmpty();

    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('state-123');

    $grant = McpGrant::sole();
    expect($grant->user_id)->toBe($user->id)
        ->and($grant->oauth_client_id)->toBe($clientId)
        ->and($grant->level->value)->toBe('action');

    $row = ActivityLog::where('kind', 'mcp.connection_authorized')->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->client_name)->toBe('Claude')
        ->and($row->meta['level'])->toBe('action')
        ->and($row->mcp_grant_id)->toBe($grant->id);
});

it('refuses to approve without a valid level and creates nothing', function (?string $level) {
    $user = User::factory()->create();
    $clientId = flow()->register();

    flow()->approve($user, $clientId, $level)->assertSessionHasErrors('level');

    expect(McpGrant::count())->toBe(0)
        ->and(ActivityLog::where('kind', 'mcp.connection_authorized')->count())->toBe(0);
})->with([null, '', 'admin', 'root', 'FULL ']);

it('keeps the pending request after a bad level, so the user can choose again', function () {
    $user = User::factory()->create();
    $clientId = flow()->register();
    $consent = flow()->consent($user, $clientId);
    $token = flow()->authToken($consent);

    $this->post('/oauth/authorize', ['auth_token' => $token, 'level' => 'bogus'])->assertSessionHasErrors('level');
    $this->post('/oauth/authorize', ['auth_token' => $token, 'level' => 'read'])->assertRedirect();

    expect(McpGrant::count())->toBe(1);
});

it('takes the client from the stored request, never from the submitted form', function () {
    $user = User::factory()->create();
    $mine = flow()->register('Mine');
    $other = flow()->register('Other');

    flow()->approve($user, $mine, 'read', ['client_id' => $other]);

    expect(McpGrant::count())->toBe(1)
        ->and(McpGrant::sole()->oauth_client_id)->toBe($mine);
});

it('refuses an approval with a wrong or missing one-time token and creates no grant', function () {
    $user = User::factory()->create();
    $clientId = flow()->register();
    flow()->consent($user, $clientId)->assertOk();

    $this->post('/oauth/authorize', ['auth_token' => 'wrong', 'level' => 'full'])->assertForbidden();
    expect(McpGrant::count())->toBe(0);

    $this->post('/oauth/authorize', ['level' => 'full'])->assertForbidden();
    expect(McpGrant::count())->toBe(0);
});

it('does nothing when the user cancels', function () {
    $user = User::factory()->create();
    $clientId = flow()->register();
    $consent = flow()->consent($user, $clientId);

    $response = $this->delete('/oauth/authorize', ['auth_token' => flow()->authToken($consent)]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('error=access_denied')
        ->and(McpGrant::count())->toBe(0);
});

it('always shows consent again for a client that is already connected', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'read');

    // Passport would normally approve silently here and keep the old level.
    $response = flow()->consent($user, $connection['client_id']);

    $response->assertOk()->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

it('updates the same connection when the user approves again with another level', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'read');

    flow()->approve($user, $connection['client_id'], 'full')->assertRedirect();

    expect(McpGrant::count())->toBe(1)
        ->and(McpGrant::sole()->level->value)->toBe('full');
    expect(ActivityLog::where('kind', 'mcp.connection_authorized')->orderBy('id')->get()->map(fn ($r) => $r->meta['created'])->all())
        ->toBe([true, false]);
});

// ---------------------------------------------------------------- tokens

it('swaps the code for tokens once, and refuses it a second time', function () {
    $user = User::factory()->create();
    $flow = flow();
    $clientId = $flow->register();
    $code = $flow->codeFrom($flow->approve($user, $clientId));

    $first = $flow->exchange($clientId, $code);
    $first->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 3600);
    expect($first->json('refresh_token'))->toBeString();

    $flow->exchange($clientId, $code)->assertStatus(400);
});

it('refuses a wrong PKCE verifier, so a stolen code alone is useless', function () {
    $user = User::factory()->create();
    $flow = flow();
    $clientId = $flow->register();
    $code = $flow->codeFrom($flow->approve($user, $clientId));

    $stolen = $flow->exchange($clientId, $code, Str::random(64));
    $stolen->assertStatus(400);
    expect($stolen->json('access_token'))->toBeNull();

    // The real client, which knows the verifier, can still redeem it.
    $flow->exchange($clientId, $code)->assertOk();
});

it('renews an access token with the refresh token', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'action');

    $refreshed = flow()->refresh($connection['client_id'], $connection['refresh_token']);
    $refreshed->assertOk();

    flow()->rpc($refreshed->json('access_token'), 'tools/list')->assertOk();
});

// ---------------------------------------------------------------- the whole journey

it('goes from registration to a working tool call with a really issued token', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $connection = flow()->connect($user, 'action', 'Claude');
    $token = $connection['access_token'];

    flow()->initialize($token)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'ServerAvatar');

    // ping, the 16 read tools (15 ServerAvatar reads and list_application_types), and the 2 tools that need the action level (the 6 full-level tools are hidden).
    expect(flow()->toolNames($token))->toContain('ping', 'list_organizations', 'restart_server', 'control_supervisor')->not->toContain('create_cronjob')->toHaveCount(19);

    $call = flow()->rpc($token, 'tools/call', ['name' => 'ping', 'arguments' => (object) []]);
    $call->assertOk();
    $payload = json_decode($call->json('result.content.0.text'), true);

    expect($payload)->toBe(['ok' => true, 'user' => 'sam@example.test', 'client' => 'Claude', 'level' => 'action']);
});

it('answers an unauthenticated call with 401 and a pointer to the OAuth metadata, not a redirect', function (string $accept) {
    $response = $this->withHeaders(['Accept' => $accept])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

    $response->assertStatus(401);
    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer realm="mcp", resource_metadata="')
        ->and($response->headers->get('WWW-Authenticate'))->toContain('.well-known/oauth-protected-resource');
})->with([
    'json first' => 'application/json, text/event-stream',
    'event stream first' => 'text/event-stream, application/json',
    'event stream only' => 'text/event-stream',
]);

it('refuses garbage and tampered tokens', function () {
    $user = User::factory()->create();
    $token = flow()->connect($user)['access_token'];

    flow()->rpc('garbage', 'tools/list')->assertStatus(401);
    flow()->rpc(substr($token, 0, -3).'xyz', 'tools/list')->assertStatus(401);
    flow()->rpc(null, 'tools/list')->assertStatus(401);
});

it('does not accept a web session as proof for /mcp', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
});

// ---------------------------------------------------------------- the path a real MCP client follows

it('lets a client discover everything from the 401 alone', function () {
    $unauthorized = $this->withHeaders(['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

    $unauthorized->assertStatus(401);
    preg_match('/resource_metadata="([^"]+)"/', $unauthorized->headers->get('WWW-Authenticate'), $match);
    $metadataUrl = $match[1] ?? null;
    expect($metadataUrl)->not->toBeNull();

    // The URL in the header is the nested one: /.well-known/oauth-protected-resource/mcp
    $resource = $this->getJson($metadataUrl)->assertOk();
    expect($resource->json('resource'))->toBe(url('/mcp'))
        ->and($resource->json('authorization_servers'))->toBe([url('/')])
        ->and($resource->json('scopes_supported'))->toBe(['mcp:use']);

    // Clients may also ask for the authorization server's metadata under the resource's path.
    $this->getJson('/.well-known/oauth-authorization-server/mcp')
        ->assertOk()
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

it('keeps answering discovery after the registration limit is used up', function () {
    config(['serveravatar.rate_limits.oauth_register' => 1]);

    $this->postJson('/oauth/register', ['client_name' => 'One', 'redirect_uris' => ['http://localhost:1/cb']])->assertCreated();
    $this->postJson('/oauth/register', ['client_name' => 'Two', 'redirect_uris' => ['http://localhost:1/cb']])->assertStatus(429);

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
});

it('completes the flow when the client sends the RFC 8707 resource parameter, as MCP clients do', function () {
    $user = User::factory()->create();
    $flow = flow();
    $clientId = $flow->register();

    expect($flow->authorizeQuery($clientId))->toHaveKey('resource', url('/mcp'));

    $code = $flow->codeFrom($flow->approve($user, $clientId, 'read'));
    $tokens = $flow->exchange($clientId, $code);
    $tokens->assertOk();

    $flow->rpc($tokens->json('access_token'), 'tools/list')->assertOk();
    $flow->refresh($clientId, $tokens->json('refresh_token'))->assertOk();
});
