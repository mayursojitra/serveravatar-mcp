<?php

use App\Models\McpGrant;
use App\Models\User;

// What a token can and cannot do on /mcp, beyond "is the token valid".

it('registers every client as public, whatever it asks for, so client_credentials is impossible', function () {
    $registered = $this->postJson('/oauth/register', [
        'client_name' => 'Wants a secret',
        'redirect_uris' => ['http://localhost:1/cb'],
        'token_endpoint_auth_method' => 'client_secret_post',
        'grant_types' => ['client_credentials', 'authorization_code'],
    ])->assertCreated();

    expect($registered->json('token_endpoint_auth_method'))->toBe('none')
        ->and($registered->json('grant_types'))->toBe(['authorization_code', 'refresh_token'])
        ->and($registered->json())->not->toHaveKey('client_secret');

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $registered->json('client_id'),
        'scope' => 'mcp:use',
    ]);

    expect($token->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and(json_decode($token->getContent(), true))->not->toHaveKey('access_token');
});

it('refuses a still-valid token of an account that was disabled, even if no one revoked it', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);

    $user->forceFill(['disabled' => true])->save();

    flow()->rpc($access, 'tools/list')->assertStatus(403);
});

it('refuses a still-valid token while the user has a password change pending', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);

    $user->forceFill(['must_change_password' => true])->save();

    flow()->rpc($access, 'tools/list')->assertStatus(403);
});

it('refuses a token whose connection record is gone', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);

    McpGrant::where('user_id', $user->id)->delete();

    flow()->rpc($access, 'tools/list')->assertStatus(401);
});

it('never lets one user act through another user\'s connection', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceAccess = connectedAccess($alice, 'full');
    $bobAccess = connectedAccess($bob, 'read');

    // Each token resolves to its own user and level: Bob's read-only connection cannot use Alice's full one.
    $names = fn (string $access) => collect(flow()->rpc($access, 'tools/list')->json('result.tools'))->pluck('name');

    expect($names($aliceAccess))->toContain('restart_server')
        ->and($names($bobAccess))->not->toContain('restart_server');

    // Revoking Alice's connection leaves Bob's alone.
    McpGrant::where('user_id', $alice->id)->delete();
    flow()->rpc($aliceAccess, 'tools/list')->assertStatus(401);
    flow()->rpc($bobAccess, 'tools/list')->assertOk();
});
