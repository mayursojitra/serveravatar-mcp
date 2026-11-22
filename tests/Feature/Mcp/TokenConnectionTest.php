<?php

use App\Mcp\McpConnection;
use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\SaToken;
use App\Models\User;
use App\Services\ServerAvatar\Exceptions\NoToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// FR-4: a connection needs a saved ServerAvatar token behind it.

it('sends a user without a token to the token page instead of the consent screen', function () {
    $user = User::factory()->create();
    $clientId = flow()->register();

    $response = flow()->consent($user, $clientId, withToken: false);

    $response->assertRedirect(route('token.show'));
    $response->assertSessionHas('error');
    expect(McpGrant::count())->toBe(0);
});

it('shows the consent screen as soon as the user has a token', function () {
    $user = User::factory()->create();
    $clientId = flow()->register();

    flow()->consent($user, $clientId)->assertOk()->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

it('refuses to approve a connection if the token was deleted after the consent screen opened', function () {
    $user = User::factory()->create();
    $flow = flow();
    $clientId = $flow->register();
    $consent = $flow->consent($user, $clientId);

    SaToken::query()->delete();

    $this->post('/oauth/authorize', ['auth_token' => $flow->authToken($consent), 'level' => 'full'])
        ->assertRedirect(route('token.show'));

    expect(McpGrant::count())->toBe(0);
});

it('refuses /mcp for a connection whose user has no token, and logs why', function () {
    $user = User::factory()->create();
    $access = flow()->connect($user)['access_token'];

    // An inconsistent state (deleting the token normally revokes the connections).
    SaToken::query()->delete();

    $response = flow()->rpc($access, 'tools/list');

    $response->assertForbidden();
    expect($response->json('error.message'))->toContain('No ServerAvatar token');
    expect(ActivityLog::where('kind', 'mcp.rejected')->sole()->meta['reason'])->toBe('no_token');
});

it('still answers ping for a user with a token, without touching ServerAvatar', function () {
    $user = User::factory()->create();
    $access = flow()->connect($user)['access_token'];

    flow()->rpc($access, 'tools/call', ['name' => 'ping', 'arguments' => (object) []])->assertOk();

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- the client a tool gets

it('builds the ServerAvatar client lazily, with the connection user\'s token', function () {
    Http::fake(['*' => Http::response(fixture('organizations'))]);
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    SaToken::factory()->for($sam)->create(['token' => 'sam-token-AAAA1111']);
    SaToken::factory()->for($priya)->create(['token' => 'priya-token-BBBB2222']);
    flow()->connect($priya);
    $grant = McpGrant::where('user_id', $priya->id)->sole();

    $connection = new McpConnection($priya->fresh(), $grant, 'Claude');
    Http::assertNothingSent();

    $connection->serverAvatar()->organizations();

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer priya-token-BBBB2222']);
    Http::assertNotSent(fn (Request $r) => $r->header('Authorization') === ['Bearer sam-token-AAAA1111']);
});

it('explains clearly when a tool needs ServerAvatar but there is no token', function () {
    $user = User::factory()->create();
    $access = flow()->connect($user)['access_token'];
    $grant = McpGrant::sole();
    SaToken::query()->delete();

    $connection = new McpConnection($user->fresh(), $grant, 'Claude');

    expect(fn () => $connection->serverAvatar())->toThrow(NoToken::class, 'No ServerAvatar token is saved');
    expect($access)->not->toBeEmpty();
});

it('works again, without reconnecting, once the missing token is saved', function () {
    $user = User::factory()->create();
    $access = flow()->connect($user)['access_token'];
    SaToken::query()->delete();

    flow()->rpc($access, 'tools/list')->assertForbidden();

    SaToken::factory()->for($user)->create();

    flow()->rpc($access, 'tools/list')->assertOk();
});
