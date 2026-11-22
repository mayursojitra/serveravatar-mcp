<?php

use App\Mcp\Tools\PingTool;
use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\Tools\ActionStubTool;
use Tests\Support\Tools\CrashingStubTool;
use Tests\Support\Tools\FailingStubTool;
use Tests\Support\Tools\FullStubTool;
use Tests\Support\Tools\ReadStubTool;

beforeEach(function () {
    // The real read, action and full tools arrive in M4/M5; these stand in at each level.
    config(['serveravatar.mcp_tools' => [
        PingTool::class,
        ReadStubTool::class,
        ActionStubTool::class,
        FullStubTool::class,
        FailingStubTool::class,
        CrashingStubTool::class,
    ]]);
});

function mcpRows(string $kind): Collection
{
    return ActivityLog::where('kind', $kind)->orderBy('id')->get();
}

// ---------------------------------------------------------------- the permission level gate (FR-11)

it('lists only the tools the connection level allows', function (string $level, array $expected) {
    $token = flow()->connect(User::factory()->create(), $level)['access_token'];

    expect(flow()->toolNames($token))->toBe($expected);
})->with([
    'read' => ['read', ['ping', 'read_stub', 'failing_stub', 'crashing_stub']],
    'action' => ['action', ['ping', 'read_stub', 'action_stub', 'failing_stub', 'crashing_stub']],
    'full' => ['full', ['ping', 'read_stub', 'action_stub', 'full_stub', 'failing_stub', 'crashing_stub']],
]);

it('refuses a tool above the level with a clear message and logs it as denied', function () {
    $token = flow()->connect(User::factory()->create(), 'read')['access_token'];

    $response = flow()->rpc($token, 'tools/call', ['name' => 'action_stub', 'arguments' => (object) []]);

    // laravel/mcp answers a JSON-RPC error with HTTP 400.
    $response->assertStatus(400)->assertJsonPath('error.code', -32602);
    expect($response->json('error.message'))->toContain('not permitted')->toContain('"action"')->toContain('"read"');

    $row = mcpRows('mcp.tool_call')->sole();
    expect($row->status)->toBe('denied')
        ->and($row->tool)->toBe('action_stub')
        ->and($row->error)->toContain('not permitted');
});

it('refuses the full tool to an action connection, and runs it for a full one', function () {
    $action = flow()->connect(User::factory()->create(), 'action', 'Action client')['access_token'];
    $full = flow()->connect(User::factory()->create(), 'full', 'Full client')['access_token'];

    flow()->rpc($action, 'tools/call', ['name' => 'full_stub', 'arguments' => (object) []])->assertStatus(400)->assertJsonPath('error.code', -32602);
    flow()->rpc($action, 'tools/call', ['name' => 'action_stub', 'arguments' => (object) []])->assertJsonPath('result.content.0.text', 'action ok');
    flow()->rpc($full, 'tools/call', ['name' => 'full_stub', 'arguments' => (object) []])->assertJsonPath('result.content.0.text', 'full ok');

    expect(mcpRows('mcp.tool_call')->pluck('status')->all())->toBe(['denied', 'ok', 'ok']);
});

it('says "not found" for a tool that does not exist, not "not permitted"', function () {
    $token = flow()->connect(User::factory()->create(), 'full')['access_token'];

    $response = flow()->rpc($token, 'tools/call', ['name' => 'delete_everything', 'arguments' => (object) []]);

    expect($response->json('error.message'))->toContain('not found')->not->toContain('not permitted');
    expect(mcpRows('mcp.tool_call')->sole()->status)->toBe('failed');
});

it('applies a level change at once, without reconnecting', function () {
    $user = User::factory()->create();
    $token = flow()->connect($user, 'read')['access_token'];

    expect(flow()->toolNames($token))->not->toContain('full_stub');

    $this->actingAs($user)->patch('/connections/'.McpGrant::sole()->id, ['level' => 'full'])->assertSessionHasNoErrors();

    expect(flow()->toolNames($token))->toContain('full_stub');

    $this->actingAs($user)->patch('/connections/'.McpGrant::sole()->id, ['level' => 'read']);

    expect(flow()->toolNames($token))->not->toContain('full_stub');
});

it('declares the right annotations on the stand-in tool', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    $tool = collect(flow()->rpc($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'ping');

    expect($tool['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]);
});

// ---------------------------------------------------------------- activity log for every MCP request (FR-17)

it('logs initialize, tools/list and tools/call with user, client, grant, tool and timing', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $token = flow()->connect($user, 'read', 'Claude')['access_token'];
    $grant = McpGrant::sole();

    flow()->initialize($token);
    flow()->rpc($token, 'tools/list');
    flow()->rpc($token, 'tools/call', ['name' => 'ping', 'arguments' => (object) []]);

    $rows = ActivityLog::where('kind', 'like', 'mcp.%')->whereIn('kind', ['mcp.initialize', 'mcp.tools_list', 'mcp.tool_call'])->orderBy('id')->get();

    expect($rows->pluck('kind')->all())->toBe(['mcp.initialize', 'mcp.tools_list', 'mcp.tool_call'])
        ->and($rows->pluck('method')->all())->toBe(['initialize', 'tools/list', 'tools/call'])
        ->and($rows->pluck('status')->unique()->all())->toBe(['ok'])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($rows->pluck('user_email')->unique()->all())->toBe(['sam@example.test'])
        ->and($rows->pluck('client_name')->unique()->all())->toBe(['Claude'])
        ->and($rows->pluck('mcp_grant_id')->unique()->all())->toBe([$grant->id])
        ->and($rows->every(fn ($row) => $row->duration_ms !== null && $row->ip !== null))->toBeTrue();

    expect($rows[0]->meta['client_info']['name'])->toBe('test-harness')
        ->and($rows[0]->meta['protocol_version'])->toBe('2025-03-26')
        ->and($rows[2]->tool)->toBe('ping');
});

it('logs admin calls too, with the admin role, and shows them on the admin page only', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.test']);
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $adminToken = flow()->connect($admin, 'read', 'Admin client')['access_token'];
    $userToken = flow()->connect($user, 'read', 'User client')['access_token'];

    flow()->rpc($adminToken, 'tools/call', ['name' => 'ping', 'arguments' => (object) []]);
    flow()->rpc($userToken, 'tools/call', ['name' => 'ping', 'arguments' => (object) []]);

    expect(mcpRows('mcp.tool_call')->pluck('user_role')->all())->toBe(['admin', 'user']);

    $this->actingAs($admin)->get('/admin/activity?kind=mcp')
        ->assertInertia(fn ($page) => $page->where('scope', 'all')->where('rows', fn ($rows) => collect($rows)->pluck('user_email')->unique()->sort()->values()->all() === ['boss@example.test', 'sam@example.test']));

    $this->actingAs($user)->get('/activity?kind=mcp')
        ->assertInertia(fn ($page) => $page->where('rows', fn ($rows) => collect($rows)->pluck('user_email')->unique()->all() === ['sam@example.test']));
});

it('redacts secrets in the logged arguments and keeps the rest', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    flow()->rpc($token, 'tools/call', ['name' => 'read_stub', 'arguments' => [
        'note' => 'hello',
        'api_token' => 'abc123-secret',
        'authorization' => 'Bearer xyz',
        'nested' => ['password' => 'hunter2', 'count' => 3],
    ]]);

    $row = mcpRows('mcp.tool_call')->sole();

    // toEqual: MySQL stores JSON keys in its own order, so the order of keys must not matter.
    expect($row->args_redacted)->toEqual([
        'note' => 'hello',
        'api_token' => '[redacted]',
        'authorization' => '[redacted]',
        'nested' => ['password' => '[redacted]', 'count' => 3],
    ]);
    expect(ActivityLog::all()->toJson())->not->toContain('abc123-secret')->not->toContain('hunter2')->not->toContain('Bearer xyz');
});

it('never stores the access token in the log', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];
    flow()->initialize($token);
    flow()->rpc($token, 'tools/list');

    expect(ActivityLog::all()->toJson())->not->toContain($token);
});

it('logs a tool that reports an error as failed, with its message', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    $response = flow()->rpc($token, 'tools/call', ['name' => 'failing_stub', 'arguments' => (object) []]);

    $response->assertOk()->assertJsonPath('result.isError', true);

    $row = mcpRows('mcp.tool_call')->sole();
    expect($row->status)->toBe('failed')->and($row->error)->toBe('ServerAvatar said no');
});

it('never leaks the message of a crashing tool, and logs it as failed', function () {
    config(['app.debug' => false]);
    $token = flow()->connect(User::factory()->create())['access_token'];

    $response = flow()->rpc($token, 'tools/call', ['name' => 'crashing_stub', 'arguments' => (object) []]);

    expect($response->getContent())->not->toContain('hunter2');

    $row = mcpRows('mcp.tool_call')->sole();
    expect($row->status)->toBe('failed')->and($row->tool)->toBe('crashing_stub');
    expect(ActivityLog::all()->toJson())->not->toContain('hunter2');
});

it('logs requests that never reach a handler: bad JSON and unknown methods', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    flow()->rawRpc($token, '{this is not json');
    flow()->rpc($token, 'nope/missing');

    $rows = mcpRows('mcp.invalid_request');
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['failed'])
        ->and($rows[0]->error)->toBe('Invalid or unparseable request')
        ->and($rows[1]->method)->toBe('nope/missing')
        ->and($rows[1]->error)->toBe('Unknown method [nope/missing]');
});

it('does not log notifications, which carry no action', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];
    $before = ActivityLog::count();

    flow()->rpc($token, 'notifications/initialized', [], null);

    expect(ActivityLog::count())->toBe($before);
});

it('records when a connection was last used', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    expect(McpGrant::sole()->last_used_at)->toBeNull();

    flow()->rpc($token, 'tools/list');

    expect(McpGrant::sole()->last_used_at)->not->toBeNull();
});

// ---------------------------------------------------------------- isolation and refusal

it('answers each connection as its own user, never as another', function () {
    $sam = User::factory()->create(['email' => 'sam@example.test']);
    $priya = User::factory()->create(['email' => 'priya@example.test']);
    $samToken = flow()->connect($sam, 'read', 'Sam client')['access_token'];
    $priyaToken = flow()->connect($priya, 'full', 'Priya client')['access_token'];

    $samPing = json_decode(flow()->rpc($samToken, 'tools/call', ['name' => 'ping', 'arguments' => (object) []])->json('result.content.0.text'), true);
    $priyaPing = json_decode(flow()->rpc($priyaToken, 'tools/call', ['name' => 'ping', 'arguments' => (object) []])->json('result.content.0.text'), true);

    expect($samPing)->toMatchArray(['user' => 'sam@example.test', 'client' => 'Sam client', 'level' => 'read'])
        ->and($priyaPing)->toMatchArray(['user' => 'priya@example.test', 'client' => 'Priya client', 'level' => 'full']);

    // Sam's read connection does not inherit Priya's full level.
    expect(flow()->toolNames($samToken))->not->toContain('full_stub');
});

it('refuses a valid token whose connection no longer exists', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];
    McpGrant::query()->delete();

    $response = flow()->rpc($token, 'tools/list');

    $response->assertStatus(401);
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata');
    expect(mcpRows('mcp.rejected')->sole()->meta['reason'])->toBe('no_grant');
});

it('refuses a disabled user even if the token was not revoked', function () {
    $user = User::factory()->create();
    $token = flow()->connect($user)['access_token'];
    $user->forceFill(['disabled' => true])->save();

    flow()->rpc($token, 'tools/list')->assertForbidden();

    $row = mcpRows('mcp.rejected')->sole();
    expect($row->status)->toBe('denied')->and($row->meta['reason'])->toBe('disabled')->and($row->user_id)->toBe($user->id);
});

it('refuses a user who has a temporary password to replace', function () {
    $user = User::factory()->create();
    $token = flow()->connect($user)['access_token'];
    $user->forceFill(['must_change_password' => true])->save();

    flow()->rpc($token, 'tools/list')->assertForbidden();

    expect(mcpRows('mcp.rejected')->sole()->meta['reason'])->toBe('password_change_required');
});

it('rate limits /mcp per user, not across users', function () {
    config(['serveravatar.rate_limits.mcp' => 2]);
    $sam = flow()->connect(User::factory()->create(), 'read', 'Sam client')['access_token'];
    $priya = flow()->connect(User::factory()->create(), 'read', 'Priya client')['access_token'];

    flow()->rpc($sam, 'tools/list')->assertOk();
    flow()->rpc($sam, 'tools/list')->assertOk();
    flow()->rpc($sam, 'tools/list')->assertStatus(429);

    flow()->rpc($priya, 'tools/list')->assertOk();
});

it('advertises a tool without parameters with an object schema, not an empty list', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    $raw = flow()->rpc($token, 'tools/list')->getContent();

    // `properties` must be a JSON object ({}), which strict MCP clients validate.
    expect($raw)->toContain('"inputSchema":{"type":"object","properties":{}}')
        ->and($raw)->not->toContain('"properties":[]');
});

it('keeps real parameters in the schema of a tool that has them', function () {
    $token = flow()->connect(User::factory()->create())['access_token'];

    $tool = collect(flow()->rpc($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'read_stub');

    expect($tool['inputSchema']['properties'])->toHaveKey('note')
        ->and($tool['inputSchema']['properties']['note']['type'])->toBe('string');
});

it('records which tools a tools/list answered with, so an admin can see what a client was shown', function () {
    flow()->rpc(connectedAccess(level: 'full'), 'tools/list');

    $meta = mcpRows('mcp.tools_list')->sole()->meta;

    expect($meta['tools_listed'])->toBe(count(config('serveravatar.mcp_tools')))
        ->and($meta['names'])->toContain('ping', 'read_stub', 'full_stub')
        ->and($meta['next_cursor'])->toBeNull();
});

it('tells the AI not to use a browser when a tool is missing, and to ask the user first', function () {
    $instructions = flow()->rpc(connectedAccess(), 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])->json('result.instructions');

    expect($instructions)->toContain('Do NOT open or use a browser')->toContain('ask whether they want you to try the browser');
});
