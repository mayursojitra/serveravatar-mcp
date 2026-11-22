<?php

use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use App\Services\Mcp\ConnectionRevoker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

function activeTokenCount(User $user): int
{
    return Passport::token()->newQuery()->where('user_id', $user->id)->where('revoked', false)->count();
}

// ---------------------------------------------------------------- the Connections page (FR-12)

it('keeps guests out', function () {
    $this->get('/connections')->assertRedirect(route('login'));
});

it('lists a user\'s own connections with client, level and last use', function () {
    $user = User::factory()->create();
    flow()->connect($user, 'action', 'Claude');
    flow()->connect($user, 'read', 'Claude Code');

    $this->actingAs($user)->get('/connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('connections/index')
            ->has('connections', 2)
            ->where('connections.0.client_name', 'Claude Code')
            ->where('connections.0.level', 'read')
            ->where('connections.1.client_name', 'Claude')
            ->where('connections.1.level', 'action')
            ->where('connections.1.last_used_at', null)
            ->has('levels', 3)
            ->where('mcpUrl', url('/mcp')));
});

it('never lists another user\'s connections', function () {
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    flow()->connect($sam, 'read', 'Sam client');
    flow()->connect($priya, 'full', 'Priya client');

    $this->actingAs($sam)->get('/connections')
        ->assertInertia(fn ($page) => $page->has('connections', 1)->where('connections.0.client_name', 'Sam client'));
});

it('changes the level of an own connection and logs it', function () {
    $user = User::factory()->create();
    flow()->connect($user, 'read', 'Claude');
    $grant = McpGrant::sole();

    $this->actingAs($user)->patch("/connections/{$grant->id}", ['level' => 'action'])->assertSessionHasNoErrors();

    expect($grant->fresh()->level->value)->toBe('action');

    $row = ActivityLog::where('kind', 'mcp.connection_level_changed')->sole();
    expect($row->meta)->toEqual(['from' => 'read', 'to' => 'action'])->and($row->client_name)->toBe('Claude');
});

it('does not log a level change that changes nothing, and refuses an invalid level', function () {
    $user = User::factory()->create();
    flow()->connect($user, 'read');
    $grant = McpGrant::sole();

    $this->actingAs($user)->patch("/connections/{$grant->id}", ['level' => 'read']);
    $this->actingAs($user)->patch("/connections/{$grant->id}", ['level' => 'root'])->assertSessionHasErrors('level');
    $this->actingAs($user)->patch("/connections/{$grant->id}", [])->assertSessionHasErrors('level');

    expect(ActivityLog::where('kind', 'mcp.connection_level_changed')->count())->toBe(0)
        ->and($grant->fresh()->level->value)->toBe('read');
});

it('cannot change or revoke another user\'s connection', function () {
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    $priyaToken = flow()->connect($priya, 'read', 'Priya client')['access_token'];
    $grant = McpGrant::where('user_id', $priya->id)->sole();

    $this->actingAs($sam)->patch("/connections/{$grant->id}", ['level' => 'full'])->assertNotFound();
    $this->actingAs($sam)->delete("/connections/{$grant->id}")->assertNotFound();

    expect($grant->fresh()->level->value)->toBe('read');
    flow()->rpc($priyaToken, 'tools/list')->assertOk();
});

it('revokes a connection at once: the access token, the refresh token and the grant', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'read', 'Claude');
    flow()->rpc($connection['access_token'], 'tools/list')->assertOk();

    $this->actingAs($user)->delete('/connections/'.McpGrant::sole()->id)->assertSessionHasNoErrors();

    expect(McpGrant::count())->toBe(0)->and(activeTokenCount($user))->toBe(0);
    flow()->rpc($connection['access_token'], 'tools/list')->assertStatus(401);
    flow()->refresh($connection['client_id'], $connection['refresh_token'])->assertStatus(400);

    $row = ActivityLog::where('kind', 'mcp.connection_revoked')->sole();
    expect($row->client_name)->toBe('Claude')->and($row->meta['by'])->toBe('user');
});

it('revokes only the chosen connection', function () {
    $user = User::factory()->create();
    $first = flow()->connect($user, 'read', 'First');
    $second = flow()->connect($user, 'read', 'Second');

    $this->actingAs($user)->delete('/connections/'.McpGrant::where('oauth_client_id', $first['client_id'])->value('id'));

    flow()->rpc($first['access_token'], 'tools/list')->assertStatus(401);
    flow()->rpc($second['access_token'], 'tools/list')->assertOk();
});

it('needs a fresh approval to connect again after a revoke', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'read');
    $this->actingAs($user)->delete('/connections/'.McpGrant::sole()->id);

    flow()->refresh($connection['client_id'], $connection['refresh_token'])->assertStatus(400);

    $again = flow()->connect($user, 'read', 'Claude again');
    flow()->rpc($again['access_token'], 'tools/list')->assertOk();
});

// ---------------------------------------------------------------- revoking everything (admin actions)

it('revokes tokens, refresh tokens, pending codes and grants for a user, and counts the connections', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $a = flow()->connect($user, 'read', 'A');
    $b = flow()->connect($user, 'action', 'B');
    $kept = flow()->connect($other, 'read', 'Other');

    // A code that was approved but never exchanged.
    $flow = flow();
    $pendingClient = $flow->register('Pending');
    $pendingCode = $flow->codeFrom($flow->approve($user, $pendingClient, 'full'));

    $count = app(ConnectionRevoker::class)->revokeAllForUser($user);

    expect($count)->toBe(3)
        ->and(McpGrant::where('user_id', $user->id)->count())->toBe(0)
        ->and(activeTokenCount($user))->toBe(0);

    flow()->rpc($a['access_token'], 'tools/list')->assertStatus(401);
    flow()->rpc($b['access_token'], 'tools/list')->assertStatus(401);
    flow()->refresh($a['client_id'], $a['refresh_token'])->assertStatus(400);
    $flow->exchange($pendingClient, $pendingCode)->assertStatus(400);

    // Another user's connection is untouched.
    flow()->rpc($kept['access_token'], 'tools/list')->assertOk();
});

it('disconnects a user\'s AI clients when an admin resets their password, and records how many', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $one = flow()->connect($user, 'read', 'One');
    $two = flow()->connect($user, 'full', 'Two');

    $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-password", ['password' => 'Fresh-Temp-Passw0rd'])
        ->assertSessionHasNoErrors();

    flow()->rpc($one['access_token'], 'tools/list')->assertStatus(401);
    flow()->rpc($two['access_token'], 'tools/list')->assertStatus(401);
    flow()->refresh($one['client_id'], $one['refresh_token'])->assertStatus(400);
    expect(McpGrant::where('user_id', $user->id)->count())->toBe(0);

    expect(ActivityLog::where('kind', 'admin.password_reset')->sole()->meta['connections_revoked'])->toBe(2);
});

it('disconnects a user\'s AI clients when an admin disables them, and they stay disconnected when re-enabled', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $token = flow()->connect($user, 'read')['access_token'];

    $this->actingAs($admin)->patch("/admin/users/{$user->id}", ['name' => $user->name, 'disabled' => true]);

    flow()->rpc($token, 'tools/list')->assertStatus(401);
    expect(ActivityLog::where('kind', 'admin.user_disabled')->sole()->meta['connections_revoked'])->toBe(1);

    $this->actingAs($admin)->patch("/admin/users/{$user->id}", ['name' => $user->name, 'disabled' => false]);

    flow()->rpc($token, 'tools/list')->assertStatus(401);
    expect(McpGrant::count())->toBe(0);
});

it('does not record a revoke count when an admin only renames a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $token = flow()->connect($user)['access_token'];

    $this->actingAs($admin)->patch("/admin/users/{$user->id}", ['name' => 'New Name', 'disabled' => false]);

    flow()->rpc($token, 'tools/list')->assertOk();
    expect(ActivityLog::where('kind', 'admin.user_updated')->sole()->meta)->not->toHaveKey('connections_revoked');
});

it('revokes the tokens of a deleted user, which have no foreign key to the user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $token = flow()->connect($user, 'read')['access_token'];
    $userId = $user->id;

    $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertSessionHasNoErrors();

    expect(Passport::token()->newQuery()->where('user_id', $userId)->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);
    flow()->rpc($token, 'tools/list')->assertStatus(401);

    expect(ActivityLog::where('kind', 'admin.user_deleted')->sole()->meta['connections_revoked'])->toBe(1);
});

it('keeps the activity history of a connection after it is revoked', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $token = flow()->connect($user, 'read', 'Claude')['access_token'];
    flow()->rpc($token, 'tools/list');
    $this->actingAs($user)->delete('/connections/'.McpGrant::sole()->id);

    $row = ActivityLog::where('kind', 'mcp.tools_list')->sole();
    expect($row->mcp_grant_id)->toBeNull()
        ->and($row->client_name)->toBe('Claude')
        ->and($row->user_email)->toBe('sam@example.test');
});

// ---------------------------------------------------------------- clean-up of unused clients

it('deletes clients that never became a connection, but keeps connected ones and recent ones', function () {
    $user = User::factory()->create();
    $connected = flow()->connect($user, 'read', 'Connected');
    $stale = flow()->register('Stale');
    $recent = flow()->register('Recent');

    Passport::client()->newQuery()->whereKey([$connected['client_id'], $stale])->update(['created_at' => now()->subDays(3)]);

    $this->artisan('oauth:prune-clients')->assertSuccessful();

    $remaining = Passport::client()->newQuery()->pluck('name')->sort()->values()->all();
    expect($remaining)->toBe(['Connected', 'Recent']);
    flow()->rpc($connected['access_token'], 'tools/list')->assertOk();
});

it('also removes what an abandoned client left behind', function () {
    $user = User::factory()->create();
    $flow = flow();
    $abandoned = $flow->register('Abandoned');
    $flow->codeFrom($flow->approve($user, $abandoned, 'read'));
    McpGrant::query()->delete();
    Passport::client()->newQuery()->whereKey($abandoned)->update(['created_at' => now()->subDays(2)]);

    $this->artisan('oauth:prune-clients')->assertSuccessful();

    expect(Passport::client()->newQuery()->count())->toBe(0)
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('schedules both clean-ups daily', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($commands->contains(fn ($c) => str_contains($c, 'oauth:prune-clients')))->toBeTrue()
        ->and($commands->contains(fn ($c) => str_contains($c, 'passport:purge')))->toBeTrue();
});
