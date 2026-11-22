<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The tools that change things, called through /mcp with a really issued token and a faked ServerAvatar.
// Nothing here reaches the real ServerAvatar: the request shapes follow its documentation and are not yet
// verified live.

const WO = 7001;
const WS = 7002;
const WA = 7005;

/** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: string, 5: array<string, mixed>|null}> name => [level, method, args, path suffix, ...] */
function writeCases(): array
{
    $s = ['organization_id' => WO, 'server_id' => WS];
    $a = $s + ['application_id' => WA];
    $base = '/organizations/'.WO.'/servers/'.WS;

    return [
        'restart_server' => ['action', 'GET', $s, $base.'/restart', null],
        'control_supervisor' => ['action', 'GET', $a + ['supervisor_id' => 77, 'action' => 'restart'], $base.'/applications/'.WA.'/supervisors/77/restart', null],
        'add_domain' => ['full', 'POST', $a + ['domain' => 'Shop.Example.com'], $base.'/applications/'.WA.'/application-domains', ['domain' => 'shop.example.com']],
        'install_ssl' => ['full', 'POST', $a + ['force_https' => true], $base.'/applications/'.WA.'/ssl', ['ssl_type' => 'automatic', 'force_https' => true]],
        'create_cronjob' => ['full', 'POST', $s + ['name' => 'Nightly clean', 'command' => 'php artisan schedule:run', 'schedule' => 'everyDay', 'system_user' => 'app_user'], $base.'/cronjobs', ['name' => 'Nightly clean', 'command' => 'php artisan schedule:run', 'schedule' => 'everyDay', 'system_user' => 'app_user']],
        'create_database' => ['full', 'POST', $s + ['name' => 'shop_db', 'username' => 'shop_user'], $base.'/databases', null],
        'create_application' => ['full', 'POST', $s + ['name' => 'demo-app', 'framework' => 'uptimekuma', 'hostname' => 'app.example.com'], $base.'/applications', null],
    ];
}

function fakeWrite(int $status = 200, array $body = ['message' => 'Done.']): void
{
    Http::fake(['*' => Http::response($body, $status)]);
}

/** The ServerAvatar requests sent, as [method, path, body]. */
function sentRequests(): array
{
    return Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH), $pair[0]->data()])->values()->all();
}

// ---------------------------------------------------------------- listing, levels, annotations

it('lists the write tools only at the level that allows them', function (string $level, array $expected) {
    $names = flow()->toolNames(connectedAccess(level: $level));
    $writes = array_values(array_intersect($names, array_keys(writeCases())));
    sort($writes);
    sort($expected);

    expect($writes)->toBe($expected);
})->with([
    'read sees none' => ['read', []],
    'action sees two' => ['action', ['restart_server', 'control_supervisor']],
    'full sees all seven' => ['full', ['restart_server', 'control_supervisor', 'add_domain', 'install_ssl', 'create_database', 'create_cronjob', 'create_application']],
]);

it('refuses a call above the connection level, logs it as denied and sends nothing to ServerAvatar', function (string $level, string $name) {
    fakeWrite();
    $access = connectedAccess(level: $level);

    $response = callTool($access, $name, writeCases()[$name][2]);

    expect($response->json('error.message'))->toContain('not permitted');
    Http::assertNothingSent();
    expect(ActivityLog::where('tool', $name)->where('status', ActivityLog::STATUS_DENIED)->count())->toBe(1);
})->with([
    ['read', 'restart_server'], ['read', 'create_cronjob'], ['action', 'add_domain'], ['action', 'create_database'], ['action', 'install_ssl'], ['action', 'create_application'],
]);

it('never marks a write tool read-only, and marks restart and supervisor control destructive', function () {
    $tools = collect(flow()->rpc(connectedAccess(level: 'full'), 'tools/list')->json('result.tools'))->keyBy('name');

    foreach (array_keys(writeCases()) as $name) {
        expect($tools[$name]['annotations'] ?? [])->not->toHaveKey('readOnlyHint', "{$name} must not be read-only")
            ->and($tools[$name]['description'])->not->toBeEmpty();
    }

    expect($tools['restart_server']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['control_supervisor']['annotations']['destructiveHint'])->toBeTrue();
});

// ---------------------------------------------------------------- the exact request sent

it('sends exactly the documented request for each tool', function (string $name, string $level, string $method, array $arguments, string $path, ?array $body) {
    fakeWrite();

    $data = toolData(callTool(connectedAccess(level: 'full'), $name, $arguments));

    $sent = sentRequests();
    expect($sent)->toHaveCount(1)
        ->and($sent[0][0])->toBe($method)
        ->and($sent[0][1])->toEndWith($path);
    if ($body !== null) {
        expect($sent[0][2])->toBe($body);
    }
    expect($data['ok'])->toBeTrue();
})->with(array_map(fn ($name, $c) => [$name, $c[0], $c[1], $c[2], $c[3], $c[4]], array_keys(writeCases()), writeCases()));

it('uses the saved token of the user who made the call', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user, 'action');
    $sent = null;
    Http::fake(['*' => function (Request $r) use (&$sent) {
        $sent = $r->header('Authorization')[0];

        return Http::response(['message' => 'ok']);
    }]);

    callTool($access, 'restart_server', ['organization_id' => WO, 'server_id' => WS]);

    expect($sent)->toBe('Bearer '.$user->saToken->revealToken());
});

it('sends create_database with the local-only connection preference', function () {
    fakeWrite();

    callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2]);

    $body = sentRequests()[0][2];
    expect(array_keys($body))->toEqualCanonicalizing(['name', 'username', 'password', 'connection_preference'])
        ->and($body['connection_preference'])->toBe('localhost')
        ->and($body['name'])->toBe('shop_db')->and($body['username'])->toBe('shop_user')
        ->and(strlen($body['password']))->toBeGreaterThanOrEqual(20)
        ->and($body['password'])->toMatch('/^[A-Za-z0-9]+$/');
});

it('sends the allowed IPv4 addresses for specific_ip_addresses and says who may connect', function () {
    fakeWrite();

    $data = toolData(callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2] + ['connection_preference' => 'specific_ip_addresses', 'hostname' => ['203.0.113.5', '203.0.113.5', '198.51.100.7']]));

    $body = sentRequests()[0][2];
    expect($body['connection_preference'])->toBe('specific_ip_addresses')
        ->and($body['hostname'])->toBe(['203.0.113.5', '198.51.100.7'])
        ->and($data['allowed_from'])->toBe('203.0.113.5, 198.51.100.7')
        ->and($data)->not->toHaveKey('host');
});

it('sends everywhere without addresses and warns that it is open to the internet', function () {
    fakeWrite();

    $data = toolData(callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2] + ['connection_preference' => 'everywhere', 'hostname' => ['203.0.113.5']]));

    $body = sentRequests()[0][2];
    expect($body['connection_preference'])->toBe('everywhere')->and($body)->not->toHaveKey('hostname')
        ->and($data['allowed_from'])->toContain('exposed to the internet')
        ->and($data['ignored_inputs'])->toBe(['hostname']);
});

it('refuses specific_ip_addresses without a valid IPv4 address, before anything is sent', function (array $extra) {
    fakeWrite();

    $response = callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2] + ['connection_preference' => 'specific_ip_addresses'] + $extra);

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'no address' => [[]],
    'empty list' => [['hostname' => []]],
    'a name' => [['hostname' => ['db.example.com']]],
    'IPv6' => [['hostname' => ['2001:db8::1']]],
    'all addresses' => [['hostname' => ['0.0.0.0']]],
    'a range' => [['hostname' => ['10.0.0.0/8']]],
]);

it('refuses an unknown connection preference', function () {
    fakeWrite();

    $response = callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2] + ['connection_preference' => 'anywhere']);

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
});

it('returns the generated database password once, and it is the one that was sent', function () {
    fakeWrite();

    $data = toolData(callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2]));

    expect($data['password'])->toBe(sentRequests()[0][2]['password'])
        ->and($data['host'])->toBe('localhost')->and($data['database'])->toBe('shop_db');
});

it('generates a different password every time', function () {
    $access = connectedAccess(level: 'full');
    fakeWrite();
    callTool($access, 'create_database', writeCases()['create_database'][2]);
    callTool($access, 'create_database', writeCases()['create_database'][2]);

    $passwords = array_column(array_column(sentRequests(), 2), 'password');
    expect($passwords[0])->not->toBe($passwords[1]);
});

// ---------------------------------------------------------------- never repeated after an error

it('does not send a restart again after a server error', function () {
    Sleep::fake();
    fakeWrite(500, ['message' => 'Server Error']);

    $text = toolError(callTool(connectedAccess(level: 'action'), 'restart_server', ['organization_id' => WO, 'server_id' => WS]));

    Http::assertSentCount(1);
    expect($text)->not->toBeEmpty();
});

it('does not send a supervisor action or a create again after a server error', function (string $name) {
    Sleep::fake();
    fakeWrite(502, ['message' => 'Bad Gateway']);

    toolError(callTool(connectedAccess(level: 'full'), $name, writeCases()[$name][2]));

    Http::assertSentCount(1);
})->with(['control_supervisor', 'add_domain', 'install_ssl', 'create_database', 'create_cronjob', 'create_application']);

it('does not send a restart again after a dropped connection', function () {
    Sleep::fake();
    $attempts = 0;
    Http::fake(['*' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('boom');
    }]);

    toolError(callTool(connectedAccess(level: 'action'), 'restart_server', ['organization_id' => WO, 'server_id' => WS]));

    expect($attempts)->toBe(1);
});

it('does retry once when ServerAvatar says it is rate limiting, because the request was not processed', function () {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '1'])->push(['message' => 'ok'], 200)]);

    toolData(callTool(connectedAccess(level: 'action'), 'restart_server', ['organization_id' => WO, 'server_id' => WS]));

    Http::assertSentCount(2);
});

it('turns a refusal into a readable error and does not claim success', function () {
    fakeWrite(403, ['message' => 'You do not have permission to perform this action.']);

    $text = toolError(callTool(connectedAccess(level: 'full'), 'add_domain', writeCases()['add_domain'][2]));

    expect($text)->toContain('permission')->not->toContain('"ok"');
});

it('marks the saved token as rejected when ServerAvatar answers 401 to a write', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user, 'action');
    fakeWrite(401, ['message' => 'Unauthenticated.']);

    toolError(callTool($access, 'restart_server', ['organization_id' => WO, 'server_id' => WS]));

    expect($user->saToken->fresh()->last_error)->toContain('rejected');
});

// ---------------------------------------------------------------- input validation: nothing is sent for bad input

it('rejects bad input and sends nothing', function (string $name, array $override) {
    fakeWrite();
    $arguments = array_merge(writeCases()[$name][2], $override);

    $response = callTool(connectedAccess(level: 'full'), $name, $arguments);

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'domain with a scheme' => ['add_domain', ['domain' => 'https://example.com']],
    'domain with a path' => ['add_domain', ['domain' => 'example.com/admin']],
    'domain with a port' => ['add_domain', ['domain' => 'example.com:8080']],
    'domain without a dot' => ['add_domain', ['domain' => 'localhost']],
    'domain with a space' => ['add_domain', ['domain' => 'exa mple.com']],
    'domain with a wildcard' => ['add_domain', ['domain' => '*.example.com']],
    'cron command with a newline' => ['create_cronjob', ['command' => "ls\nrm -rf /tmp/x"]],
    'cron command with a carriage return' => ['create_cronjob', ['command' => "ls\rwhoami"]],
    'cron command too long' => ['create_cronjob', ['command' => str_repeat('a', 1001)]],
    'cron command missing' => ['create_cronjob', ['command' => '']],
    'cron schedule not a preset' => ['create_cronjob', ['schedule' => '* * * * *']],
    'cron system user with a slash' => ['create_cronjob', ['system_user' => '../root']],
    'cron system user with a space' => ['create_cronjob', ['system_user' => 'a b']],
    'cron name with a semicolon' => ['create_cronjob', ['name' => 'a;b']],
    'database name with a semicolon' => ['create_database', ['name' => 'a;DROP']],
    'database name with a dash' => ['create_database', ['name' => 'my-db']],
    'database user too short' => ['create_database', ['username' => 'abcd']],
    'database user with a quote' => ['create_database', ['username' => "ab'cde"]],
    'supervisor action not allowed' => ['control_supervisor', ['supervisor_id' => 5, 'action' => 'delete']],
    'supervisor id not a number' => ['control_supervisor', ['supervisor_id' => 'x', 'action' => 'start']],
    'ssl without force_https' => ['install_ssl', ['force_https' => 'maybe']],
    'id is not a whole number' => ['restart_server', ['server_id' => '1/../2']],
]);

it('rejects a password supplied by the model, and an ssl type other than automatic', function () {
    fakeWrite();
    $access = connectedAccess(level: 'full');

    $a = toolData(callTool($access, 'create_database', writeCases()['create_database'][2] + ['password' => 'chosen-by-model-1']));
    callTool($access, 'install_ssl', writeCases()['install_ssl'][2] + ['ssl_type' => 'custom', 'private_key' => 'KEY']);

    // The extra arguments are ignored, never forwarded.
    $bodies = array_column(sentRequests(), 2);
    expect($bodies[0]['password'])->not->toBe('chosen-by-model-1')
        ->and($bodies[1])->toBe(['ssl_type' => 'automatic', 'force_https' => true])
        ->and($a['password'])->not->toBe('chosen-by-model-1');
});

// ---------------------------------------------------------------- results and the activity log

it('never echoes a credential-looking field from ServerAvatar, apart from the one password we generated', function () {
    fakeWrite(200, ['message' => 'Created', 'database' => ['id' => 5, 'name' => 'shop_db', 'password' => 'from-serveravatar', 'api_token' => 'x']]);

    $data = toolData(callTool(connectedAccess(level: 'full'), 'create_database', writeCases()['create_database'][2]));

    expect(json_encode($data))->not->toContain('from-serveravatar');
    foreach (allKeys($data) as $key) {
        if ($key === 'password') {
            continue; // the generated one, top level only
        }
        expect(preg_match('/passw|secret|token|credential|private/i', $key))->toBe(0, "returned the field {$key}");
    }
    expect(array_keys(array_filter($data, fn ($v, $k) => $k === 'password', ARRAY_FILTER_USE_BOTH)))->toBe(['password']);
});

it('keeps the generated database password and cron secrets out of the activity log', function () {
    fakeWrite();
    $access = connectedAccess(level: 'full');

    $password = toolData(callTool($access, 'create_database', writeCases()['create_database'][2]))['password'];
    callTool($access, 'create_cronjob', array_merge(writeCases()['create_cronjob'][2], ['command' => 'mysqldump --password=hunter2secret -u root db', 'name' => 'Dump']));
    callTool($access, 'create_cronjob', array_merge(writeCases()['create_cronjob'][2], ['command' => 'curl -H "Authorization: Bearer abc.def.ghi" https://x.test', 'name' => 'Ping']));

    $logged = ActivityLog::query()->whereIn('tool', ['create_database', 'create_cronjob'])->get();
    $all = json_encode($logged->map->toArray());

    expect($logged)->toHaveCount(3)
        ->and($all)->not->toContain($password)->not->toContain('hunter2secret')->not->toContain('abc.def.ghi')
        ->and($all)->toContain('[redacted]');
});

it('logs a write call under the user and the tool, with its status', function () {
    fakeWrite();
    $user = User::factory()->create();

    callTool(connectedAccess($user, 'action'), 'restart_server', ['organization_id' => WO, 'server_id' => WS]);

    $row = ActivityLog::where('tool', 'restart_server')->sole();
    expect($row->user_id)->toBe($user->id)->and($row->status)->toBe(ActivityLog::STATUS_OK)
        ->and($row->args_redacted)->toMatchArray(['organization_id' => WO, 'server_id' => WS]);
});

it('says plainly that the request was sent, with ServerAvatar\'s answer trimmed', function () {
    fakeWrite(200, ['message' => 'Server restart initiated.', 'internal_key' => 'k', 'extra_conf' => 'x']);

    $data = toolData(callTool(connectedAccess(level: 'action'), 'restart_server', ['organization_id' => WO, 'server_id' => WS]));

    expect($data['ok'])->toBeTrue()->and($data['message'])->toContain('requested')
        ->and($data['serveravatar_response'])->toHaveKey('message')->not->toHaveKey('internal_key');
});

// ---------------------------------------------------------------- isolation

it('uses only the calling user\'s token, never another user\'s', function () {
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    $priyaAccess = connectedAccess($priya, 'action');
    $tokens = [];
    Http::fake(['*' => function (Request $r) use (&$tokens) {
        $tokens[] = $r->header('Authorization')[0];

        return Http::response(['message' => 'ok']);
    }]);

    callTool($priyaAccess, 'restart_server', ['organization_id' => WO, 'server_id' => WS]);

    expect($tokens)->toBe(['Bearer '.$priya->saToken->revealToken()]);
});

it('tells the AI to ask the user for the database name, user and who may connect, not to invent them', function () {
    $tools = collect(flow()->rpc(connectedAccess(level: 'full'), 'tools/list')->json('result.tools'))->keyBy('name');
    $create = $tools['create_database'];

    expect($create['description'])->toContain('MUST ask')->toContain('database NAME')->toContain('Never invent')->toContain('who may connect')
        ->and($create['inputSchema']['properties']['name']['description'])->toContain('do not invent')
        ->and($create['inputSchema']['properties']['connection_preference']['description'])->toContain('do not choose it yourself')
        ->and($create['description'])->toContain('ONE popup of separate questions')->toContain('do not list or mention')->toContain('do not ask whether to create one')
        ->and($tools['update_database_user']['description'])->toContain('MUST ask');
});
