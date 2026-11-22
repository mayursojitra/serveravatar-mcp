<?php

use Illuminate\Support\Facades\Http;

// update_database_user, called through /mcp with a faked ServerAvatar. The request shapes follow
// ServerAvatar's documentation; the list of a database's users has not been seen live.

const UO = 7001;
const US = 7004;
const UD = 7003;

function updateArgs(array $override = []): array
{
    return array_merge(['organization_id' => UO, 'server_id' => US, 'database_id' => UD, 'username' => 'report_user', 'connection_preference' => 'specific_ip_addresses', 'hostname' => ['203.0.113.5', '198.51.100.7']], $override);
}

/** The ServerAvatar requests sent, as [method, path, body]. */
function sentUserRequests(): array
{
    return Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH), $pair[0]->data()])->values()->all();
}

function fakeDatabaseUsers(): void
{
    $base = '*/organizations/'.UO.'/servers/'.US.'/databases/'.UD.'/database-users*';

    Http::fake([
        $base => fn ($request) => $request->method() === 'PATCH'
            ? Http::response(['message' => 'Database user has been updated successfully.'])
            : Http::response(['databaseUsers' => ['data' => [['id' => 9, 'username' => 'other_user'], ['id' => 77, 'username' => 'report_user', 'password' => 'must-not-leak']], 'current_page' => 1, 'last_page' => 1, 'total' => 2]]),
    ]);
}

it('finds the user by name, then replaces the allowed addresses and resets the password', function () {
    fakeDatabaseUsers();

    $data = toolData(callTool(connectedAccess(level: 'full'), 'update_database_user', updateArgs()));
    $sent = sentUserRequests();

    expect($sent)->toHaveCount(2)
        ->and($sent[0][0])->toBe('GET')
        ->and($sent[1][0])->toBe('PATCH')
        ->and($sent[1][1])->toBe('/organizations/'.UO.'/servers/'.US.'/databases/'.UD.'/database-users/77')
        ->and(array_keys($sent[1][2]))->toEqualCanonicalizing(['username', 'password', 'connection_preference', 'hostname'])
        ->and($sent[1][2]['username'])->toBe('report_user')
        ->and($sent[1][2]['hostname'])->toBe(['203.0.113.5', '198.51.100.7'])
        ->and($data['allowed_from'])->toBe('203.0.113.5, 198.51.100.7')
        ->and($data['password'])->toBe($sent[1][2]['password'])
        ->and(strlen($data['password']))->toBeGreaterThanOrEqual(20)
        ->and(json_encode($data))->not->toContain('must-not-leak');
});

it('sends no addresses for localhost or everywhere and reports a dropped hostname', function (string $preference) {
    fakeDatabaseUsers();

    $data = toolData(callTool(connectedAccess(level: 'full'), 'update_database_user', updateArgs(['connection_preference' => $preference])));
    $body = sentUserRequests()[1][2];

    expect($body['connection_preference'])->toBe($preference)->and($body)->not->toHaveKey('hostname')
        ->and($data['ignored_inputs'])->toBe(['hostname']);
})->with(['localhost', 'everywhere']);

it('finds the user on a later page of the list', function () {
    $base = '*/organizations/'.UO.'/servers/'.US.'/databases/'.UD.'/database-users*';
    Http::fake([$base => function ($request) {
        if ($request->method() === 'PATCH') {
            return Http::response(['message' => 'ok']);
        }

        $page = (int) ($request->data()['page'] ?? 1);

        return Http::response(['databaseUsers' => ['data' => [$page === 1 ? ['id' => 9, 'username' => 'other_user'] : ['id' => 88, 'username' => 'report_user']], 'current_page' => $page, 'last_page' => 2, 'total' => 2]]);
    }]);

    callTool(connectedAccess(level: 'full'), 'update_database_user', updateArgs());

    expect(collect(sentUserRequests())->pluck(0)->all())->toBe(['GET', 'GET', 'PATCH'])
        ->and(sentUserRequests()[2][1])->toEndWith('/database-users/88');
});

it('says so, and changes nothing, when the database user does not exist', function () {
    fakeDatabaseUsers();

    $response = callTool(connectedAccess(level: 'full'), 'update_database_user', updateArgs(['username' => 'nobody_here']));

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('No database user named nobody_here')
        ->and(collect(sentUserRequests())->pluck(0)->all())->toBe(['GET']);
});

it('refuses bad input before anything is sent', function (array $override) {
    fakeDatabaseUsers();

    $response = callTool(connectedAccess(level: 'full'), 'update_database_user', updateArgs($override));

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'no addresses for specific_ip_addresses' => [['hostname' => []]],
    'a name instead of an address' => [['hostname' => ['db.example.com']]],
    'IPv6' => [['hostname' => ['2001:db8::1']]],
    'all addresses' => [['hostname' => ['0.0.0.0']]],
    'unknown preference' => [['connection_preference' => 'anywhere']],
    'short user name' => [['username' => 'abcd']],
    'user name with a quote' => [['username' => "ab'cde"]],
    'database id with a path' => [['database_id' => '1/../2']],
]);

it('is listed only at the full level', function (string $level, bool $listed) {
    $names = flow()->toolNames(connectedAccess(level: $level));

    expect(in_array('update_database_user', $names, true))->toBe($listed);
})->with([['read', false], ['action', false], ['full', true]]);

it('is refused below the full level', function () {
    fakeDatabaseUsers();

    $response = callTool(connectedAccess(level: 'action'), 'update_database_user', updateArgs());

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
});
