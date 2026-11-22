<?php

use App\Models\ActivityLog;
use App\Models\SaToken;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The 15 read tools, called through /mcp with a really issued token and a faked ServerAvatar.

const O = 7001;
const S = 7002;
const A = 7005;

/** @return array<string, array{0: string, 1: array<string, int>, 2: array<string, string>, 3: string}> */
function toolCases(): array
{
    $o = ['organization_id' => O];
    $s = $o + ['server_id' => S];
    $a = $s + ['application_id' => A];

    return [
        'list_organizations' => ['list_organizations', [], ['*/organizations' => 'organizations'], 'organizations'],
        'list_servers' => ['list_servers', $o, ['*/servers*' => 'servers_paginated'], 'items'],
        'get_server' => ['get_server', $s, ['*/status' => 'server_status', '*/summary' => 'server_summary', '*/servers/'.S => 'server_show'], 'server'],
        'get_server_usage' => ['get_server_usage', $s, ['*/usage' => 'server_usage'], 'usage'],
        'get_server_logs' => ['get_server_logs', $s, ['*/logs' => 'logs_files'], 'log_files'],
        'list_applications' => ['list_applications', $s, ['*/applications*' => 'applications_paginated'], 'items'],
        'get_application' => ['get_application', $a, ['*/applications/'.A => 'application_show'], 'application'],
        'list_domains' => ['list_domains', $a, ['*/application-domains*' => 'domains_paginated'], 'items'],
        'get_ssl' => ['get_ssl', $a, ['*/ssl' => 'ssl_show'], 'ssl'],
        'list_databases' => ['list_databases', $s, ['*/databases*' => 'databases_paginated'], 'items'],
        'list_database_users' => ['list_database_users', $s + ['database_id' => 2002], ['*/database-users*' => 'database_users_paginated'], 'items'],
        'list_cronjobs' => ['list_cronjobs', $s, ['*/cronjobs*' => 'cronjobs_paginated'], 'items'],
        'list_system_users' => ['list_system_users', $s, ['*/system-users*' => 'system_users_paginated'], 'items'],
        'list_backups' => ['list_backups', $o, ['*/backups*' => 'error_forbidden'], 'items'],
        'list_supervisors' => ['list_supervisors', $a, ['*/supervisors*' => 'supervisors_assumed_shape'], 'items'],
    ];
}

/** @param  array<string, string>  $stubs  url pattern => fixture name */
function fakeServerAvatar(array $stubs): void
{
    Http::fake(array_map(fn (string $fixture) => Http::response(fixture($fixture), str_starts_with($fixture, 'error_') ? 403 : 200), $stubs));
}

// ---------------------------------------------------------------- listing and annotations

it('lists all fifteen read tools, plus the local list_application_types, at the read level', function () {
    $tools = collect(flow()->rpc(connectedAccess(), 'tools/list')->json('result.tools'))->keyBy('name');

    expect($tools->keys()->diff(['ping', 'list_application_types'])->sort()->values()->all())->toBe(collect(toolCases())->keys()->sort()->values()->all());
});

it('marks every read tool read-only, idempotent and open-world, with described, required ids', function (string $name, array $arguments) {
    $tool = collect(flow()->rpc(connectedAccess(), 'tools/list')->json('result.tools'))->firstWhere('name', $name);

    expect($tool['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => true])
        ->and($tool['description'])->not->toBeEmpty();

    foreach (array_keys($arguments) as $id) {
        expect($tool['inputSchema']['properties'][$id])->toMatchArray(['type' => 'integer', 'minimum' => 1])
            ->and($tool['inputSchema']['properties'][$id]['description'])->not->toBeEmpty()
            ->and($tool['inputSchema']['required'])->toContain($id);
    }
})->with(fn () => collect(toolCases())->map(fn ($c) => [$c[0], $c[1]])->all());

// ---------------------------------------------------------------- each tool

it('lists organizations with id and name only, using the connection user\'s token', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);
    fakeServerAvatar(['*/organizations' => 'organizations']);

    $data = toolData(callTool($access, 'list_organizations'));

    expect($data['organizations'])->toHaveCount(2)
        ->and(array_keys($data['organizations'][0]))->toBe(['id', 'name']);
    expect(json_encode($data))->not->toContain('member@example.test');

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer '.$user->saToken->revealToken()]);
});

it('lists servers a page at a time and says what comes next', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/servers*' => 'servers_paginated']);

    $data = toolData(callTool($access, 'list_servers', ['organization_id' => O, 'page' => 2]));

    expect($data)->toHaveKeys(['items', 'page', 'last_page', 'total', 'next_page'])
        ->and($data['items'])->toHaveCount(2)
        ->and($data['items'][0])->toHaveKeys(['id', 'name', 'ip'])
        ->and($data['next_page'])->toBeNull();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/organizations/'.O.'/servers')
        && str_contains($r->url(), 'pagination=1') && str_contains($r->url(), 'page=2'));
});

it('points to the next page while there is one', function () {
    $access = connectedAccess();
    $page = fixture('servers_paginated');
    $page['servers']['current_page'] = 1;
    $page['servers']['last_page'] = 3;
    Http::fake(['*' => Http::response($page)]);

    expect(toolData(callTool($access, 'list_servers', ['organization_id' => O]))['next_page'])->toBe(2);
});

it('shows a server with its status and counts', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/status' => 'server_status', '*/summary' => 'server_summary', '*/servers/'.S => 'server_show']);

    $data = toolData(callTool($access, 'get_server', ['organization_id' => O, 'server_id' => S]));

    expect($data['server'])->toHaveKeys(['id', 'name', 'ip', 'web_server'])
        ->and($data['setup_status'])->toHaveKeys(['items', 'configuration_percentage'])
        ->and($data['counts'])->toHaveKeys(['system_users', 'applications', 'databases', 'cronjobs'])
        ->and($data['notes'])->toBe([]);
});

it('still shows a server when its status or counts are refused, and says what is missing', function () {
    $access = connectedAccess();
    Http::fake([
        '*/status' => Http::response(fixture('error_forbidden'), 403),
        '*/summary' => Http::response(fixture('server_summary')),
        '*/servers/'.S => Http::response(fixture('server_show')),
    ]);

    $data = toolData(callTool($access, 'get_server', ['organization_id' => O, 'server_id' => S]));

    expect($data['server']['id'])->not->toBeNull()
        ->and($data)->not->toHaveKey('setup_status')
        ->and($data['counts'])->toHaveKey('applications')
        ->and($data['notes'][0])->toContain('setup_status is not available')->toContain('permission');
});

it('shows server resource use', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/usage' => 'server_usage']);

    $data = toolData(callTool($access, 'get_server_usage', ['organization_id' => O, 'server_id' => S]));

    expect($data['usage'])->toHaveKeys(['disk', 'memory', 'swap', 'load', 'cores', 'uptime'])
        ->and($data['usage']['disk'])->toHaveKeys(['total', 'usage_in_percentage']);
});

it('lists applications with the server id the next tool needs', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/applications*' => 'applications_paginated']);

    $data = toolData(callTool($access, 'list_applications', ['organization_id' => O, 'server_id' => S]));

    expect($data['items'][0])->toHaveKeys(['id', 'server_id', 'name', 'framework', 'primary_domain', 'php_version', 'ssl'])
        ->and($data['items'][0]['ssl'])->toBeBool();
});

it('shows an application without its keys or raw configuration, and says whether git deployment exists', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/applications/'.A => 'application_show']);

    $data = toolData(callTool($access, 'get_application', ['organization_id' => O, 'server_id' => S, 'application_id' => A]));

    expect($data['application'])->toHaveKeys(['id', 'name', 'memory_limit', 'upload_max_filesize', 'pm_type', 'system_user'])
        ->and($data['has_git_deployment'])->toBeFalse();
    expect(allKeys($data))->not->toContain('key')->not->toContain('extra_conf')->not->toContain('object_cache_pro_license_key')
        ->not->toContain('open_basedir')->not->toContain('disabled_functions')->not->toContain('gitDeployment');
});

it('reports a git deployment without returning what is in it', function () {
    $access = connectedAccess();
    $show = fixture('application_show');
    $show['gitDeployment'] = ['repository' => 'git@example.test:x/y.git', 'deploy_key' => 'fake-private-key'];
    Http::fake(['*' => Http::response($show)]);

    $data = toolData(callTool($access, 'get_application', ['organization_id' => O, 'server_id' => S, 'application_id' => A]));

    expect($data['has_git_deployment'])->toBeTrue();
    expect(json_encode($data))->not->toContain('fake-private-key')->not->toContain('git@example.test');
});

it('lists the domains of an application', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/application-domains*' => 'domains_paginated']);

    $data = toolData(callTool($access, 'list_domains', ['organization_id' => O, 'server_id' => S, 'application_id' => A]));

    expect($data['items'][0])->toHaveKeys(['id', 'application_id', 'domain', 'autossl', 'temp_domain']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/applications/'.A.'/application-domains'));
});

it('shows the SSL state', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/ssl' => 'ssl_show']);

    $data = toolData(callTool($access, 'get_ssl', ['organization_id' => O, 'server_id' => S, 'application_id' => A]));

    expect($data['ssl'])->toHaveKeys(['installed', 'force_https', 'certificate'])
        ->and($data['ssl']['certificate'])->toHaveKeys(['issuer', 'expires_on', 'is_expired', 'validity_left']);
});

it('lists databases without logins', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/databases*' => 'databases_paginated']);

    $data = toolData(callTool($access, 'list_databases', ['organization_id' => O, 'server_id' => S]));

    expect($data['items'][0])->toHaveKeys(['id', 'server_id', 'name', 'database_type', 'remote_access'])
        ->and(array_keys($data['items'][0]))->not->toContain('pma_auto_login');
});

it('lists the users of a database and who may connect, without their passwords', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/database-users*' => 'database_users_paginated']);

    $data = toolData(callTool($access, 'list_database_users', ['organization_id' => O, 'server_id' => S, 'database_id' => 2002]));

    expect($data['items'][0])->toBe(['id' => 1001, 'database_id' => 2002, 'username' => 'shop_user', 'connection_preference' => 'specific_ip_addresses', 'created_at' => '2026-01-01 00:00:00', 'hostname' => ['203.0.113.5', '198.51.100.7'], 'remote_access' => true])
        ->and($data['items'][1]['hostname'])->toBe(['localhost'])->and($data['items'][1]['remote_access'])->toBeFalse()
        ->and($data['total'])->toBe(2)->and($data['next_page'])->toBeNull()
        ->and(json_encode($data))->not->toContain('FAKE-not-a-real-password')->not->toContain('password');
});

it('lists cron jobs and removes secrets from their commands', function () {
    $access = connectedAccess();
    $page = fixture('cronjobs_paginated');
    $page['cronjobs']['data'][0]['command'] = 'mysqldump -u app -pHUNTER2 db > /backup.sql && curl -H "Authorization: Bearer abc123" https://user:s3cret@example.test/x?token=ZZZ';
    Http::fake(['*' => Http::response($page)]);

    $data = toolData(callTool($access, 'list_cronjobs', ['organization_id' => O, 'server_id' => S]));
    $job = $data['items'][0];

    expect($job)->toHaveKeys(['id', 'name', 'schedule', 'command', 'enabled']);
    expect($job['command'])->toContain('mysqldump')->toContain('[redacted]')
        ->not->toContain('HUNTER2')->not->toContain('abc123')->not->toContain('s3cret')->not->toContain('ZZZ');
});

it('lists backups when the role allows it, whatever key the page is under', function () {
    $access = connectedAccess();
    Http::fake(['*' => Http::response(['backupItems' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'data' => [
        ['id' => 9, 'name' => 'nightly', 'size' => '1 MB', 'storage_key' => 'fake-secret', 'config' => ['x' => 1]],
    ]]])]);

    $data = toolData(callTool($access, 'list_backups', ['organization_id' => O]));

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0])->toBe(['id' => 9, 'name' => 'nightly', 'size' => '1 MB']);
});

it('explains a missing backup permission instead of failing', function () {
    $access = connectedAccess();
    fakeServerAvatar(['*/backups*' => 'error_forbidden']);

    $text = toolError(callTool($access, 'list_backups', ['organization_id' => O]));

    expect($text)->toContain('does not have permission');
});

it('lists supervisor processes and keeps secrets out of records whose shape is not known', function () {
    $access = connectedAccess();
    $page = fixture('supervisors_assumed_shape');
    $page['supervisors']['data'][0] += ['api_token' => 'fake-token', 'env' => ['SECRET' => 'x']];
    $page['supervisors']['data'][0]['command'] = 'run --password=hunter2';
    Http::fake(['*' => Http::response($page)]);

    $data = toolData(callTool($access, 'list_supervisors', ['organization_id' => O, 'server_id' => S, 'application_id' => A]));
    $item = $data['items'][0];

    expect($item)->toHaveKeys(['id', 'name', 'status'])->not->toHaveKeys(['api_token', 'env']);
    expect($item['command'])->toContain('[redacted]')->not->toContain('hunter2');
});

// ---------------------------------------------------------------- logs

it('lists the log files first, then reads one', function () {
    $access = connectedAccess();
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? fixture('log_output') : fixture('logs_files'))]);

    $list = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S]));
    expect($list['log_files'])->toHaveKey('example.log')->and($list['next'])->toContain('"log"');

    $read = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log']));
    expect($read)->toHaveKeys(['log', 'path', 'total_lines', 'returned_lines', 'truncated', 'output'])
        ->and($read['output'])->toContain('line three')->and($read['truncated'])->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['log' => 'example.log']);
});

it('returns only the end of a long log, with how much there was', function () {
    $access = connectedAccess();
    $lines = collect(range(1, 3000))->map(fn ($i) => "line {$i}")->implode("\n");
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? ['output' => $lines, 'path' => '/var/log/example.log'] : fixture('logs_files'))]);

    $default = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log']));
    expect($default['total_lines'])->toBe(3000)->and($default['returned_lines'])->toBe(100)->and($default['truncated'])->toBeTrue()
        ->and($default['output'])->toEndWith('line 3000')->and($default['output'])->toStartWith('line 2901');

    $few = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log', 'lines' => 5]));
    expect($few['returned_lines'])->toBe(5)->and($few['output'])->toBe("line 2996\nline 2997\nline 2998\nline 2999\nline 3000");
});

it('caps a log by size even when the lines are long, cutting at a whole line', function () {
    $access = connectedAccess();
    $lines = collect(range(1, 400))->map(fn ($i) => "row {$i} ".str_repeat('x', 400))->implode("\n");
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? ['output' => $lines, 'path' => '/p'] : fixture('logs_files'))]);

    $data = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log', 'lines' => 400]));

    expect(strlen($data['output']))->toBeLessThanOrEqual(20_000)
        ->and($data['truncated'])->toBeTrue()
        ->and($data['output'])->toStartWith('row ')
        ->and($data['returned_lines'])->toBeLessThan(400);
});

it('removes secret-looking values from log lines', function () {
    $access = connectedAccess();
    $text = "ok line\nGET /?api_key=KEYVALUE&x=1\nlogin password=hunter2 failed\nAuthorization: Bearer TOKENVALUE";
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? ['output' => $text, 'path' => '/p'] : fixture('logs_files'))]);

    $out = toolData(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log']))['output'];

    expect($out)->toContain('ok line')->toContain('[redacted]')
        ->not->toContain('KEYVALUE')->not->toContain('hunter2')->not->toContain('TOKENVALUE');
});

it('only asks for a log the server itself lists', function () {
    $access = connectedAccess();
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? fixture('log_output') : fixture('logs_files'))]);

    $text = toolError(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'secret.log']));

    expect($text)->toContain('not available')->toContain('example.log');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('refuses odd log names and line counts before calling ServerAvatar', function (array $extra) {
    $access = connectedAccess();
    Http::fake();

    toolError(callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S] + $extra));

    Http::assertNothingSent();
})->with([
    'parent directory' => [['log' => '../../etc/passwd']],
    'parent directory inside' => [['log' => 'nginx/../auth.log']],
    'absolute path' => [['log' => '/etc/passwd']],
    'a space' => [['log' => 'a b']],
    'a url' => [['log' => 'https://x.test/a']],
    'too long' => [['log' => str_repeat('a', 121)]],
    'too many lines' => [['log' => 'example.log', 'lines' => 501]],
    'no lines' => [['log' => 'example.log', 'lines' => 0]],
]);

it('keeps the log text out of the activity log, only the arguments', function () {
    $access = connectedAccess();
    Http::fake(['*/logs' => fn (Request $r) => Http::response($r->method() === 'POST' ? ['output' => "UNIQUE-LOG-MARKER-42\nmore", 'path' => '/p'] : fixture('logs_files'))]);

    callTool($access, 'get_server_logs', ['organization_id' => O, 'server_id' => S, 'log' => 'example.log']);

    $row = ActivityLog::where('tool', 'get_server_logs')->latest('id')->first();
    expect($row->args_redacted)->toEqual(['organization_id' => O, 'server_id' => S, 'log' => 'example.log'])
        ->and(ActivityLog::all()->toJson())->not->toContain('UNIQUE-LOG-MARKER-42');
});

// ---------------------------------------------------------------- input

it('refuses ids that are not whole positive numbers, before calling ServerAvatar', function (mixed $bad) {
    $access = connectedAccess();
    Http::fake();

    foreach (['list_servers', 'get_application', 'list_domains', 'get_server_logs'] as $tool) {
        $arguments = ['organization_id' => $bad, 'server_id' => S, 'application_id' => A];
        toolError(callTool($access, $tool, $arguments));
    }

    Http::assertNothingSent();
})->with([
    'text' => 'abc',
    'path traversal' => '1/../x',
    'negative' => -3,
    'zero' => 0,
    'decimal' => 1.5,
    'array' => [[1]],
]);

it('refuses a call with a required id missing', function (string $tool, array $arguments) {
    $access = connectedAccess();
    Http::fake();

    foreach (array_keys($arguments) as $missing) {
        $partial = $arguments;
        unset($partial[$missing]);
        toolError(callTool($access, $tool, $partial));
    }

    Http::assertNothingSent();
})->with(fn () => collect(toolCases())->filter(fn ($c) => $c[1] !== [])->map(fn ($c) => [$c[0], $c[1]])->all());

it('refuses a page number that is not a positive whole number', function () {
    $access = connectedAccess();
    Http::fake();

    foreach ([0, -1, 'x'] as $page) {
        toolError(callTool($access, 'list_servers', ['organization_id' => O, 'page' => $page]));
    }

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- what ServerAvatar answers

it('turns a refused token into a clear message, records it on the token page, and logs the call as failed', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);
    Http::fake(['*' => Http::response(fixture('error_unauthenticated'), 401)]);

    $text = toolError(callTool($access, 'list_organizations'));

    expect($text)->toContain('rejected the saved API token')->toContain('ServerAvatar token page');
    expect($user->saToken->fresh()->last_error)->toContain('rejected the saved API token');
    expect(ActivityLog::where('tool', 'list_organizations')->sole()->status)->toBe('failed');

    $this->actingAs($user)->get('/token')->assertInertia(fn ($page) => $page->where('token.last_error', fn ($e) => str_contains($e, 'rejected')));
});

it('explains each ServerAvatar failure in words the model can act on', function (int $status, string $contains) {
    $access = connectedAccess();
    Sleep::fake();
    Http::fake(['*' => Http::response(['message' => 'No'], $status)]);

    expect(toolError(callTool($access, 'list_servers', ['organization_id' => O])))->toContain($contains);
})->with([
    '403' => [403, 'permission'],
    '404' => [404, 'Check the organization, server or application id'],
    '422' => [422, 'rejected the request'],
    '429' => [429, 'rate limit'],
    '503' => [503, 'Try again shortly'],
]);

it('retries a rate limit and then answers normally', function () {
    $access = connectedAccess();
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push(fixture('organizations'))]);

    expect(toolData(callTool($access, 'list_organizations'))['organizations'])->toHaveCount(2);
});

it('refuses a result that is too large instead of returning part of it', function () {
    $access = connectedAccess();
    $page = fixture('databases_paginated');
    $page['databases']['data'][0]['name'] = str_repeat('x', 120_000);
    Http::fake(['*' => Http::response($page)]);

    expect(toolError(callTool($access, 'list_databases', ['organization_id' => O, 'server_id' => S])))->toContain('too large');
});

// ---------------------------------------------------------------- isolation and secrets

it('uses each user\'s own token and nobody else\'s', function () {
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    $samAccess = connectedAccess($sam);
    $priyaAccess = connectedAccess($priya);
    $sent = [];
    Http::fake(['*' => function (Request $r) use (&$sent) {
        $sent[] = $r->header('Authorization')[0] ?? '';

        return Http::response(fixture('organizations'));
    }]);

    callTool($samAccess, 'list_organizations');
    callTool($priyaAccess, 'list_organizations');
    callTool($samAccess, 'list_organizations');

    expect($sent)->toBe([
        'Bearer '.$sam->saToken->revealToken(),
        'Bearer '.$priya->saToken->revealToken(),
        'Bearer '.$sam->saToken->revealToken(),
    ]);
});

it('never returns an access-granting field from any tool', function () {
    $access = connectedAccess();
    $forbiddenKeys = ['members', 'email', 'key', 'extra_conf', 'object_cache_pro_license_key', 'phpmyadmin_url', 'filemanager_url', 'phpmyadmin_slug', 'sa_phpmyadmin_slug', 'filemanager_slug', 'sa_filemanager_slug', 'open_basedir', 'disabled_functions', 'root_password_authentication', 'permit_root_login', 'pma_auto_login', 'gitDeployment'];
    $forbiddenValues = ['fake-extra_conf-value', 'fake-object_cache_pro_license_key-value', 'fake-phpmyadmin_slug-value', 'fake-sa_filemanager_slug-value', 'member@example.test'];

    foreach (toolCases() as [$name, $arguments, $stubs]) {
        if ($name === 'list_backups') {
            continue;
        }
        Http::fake(array_map(fn ($f) => Http::response(fixture($f)), $stubs));
        $data = toolData(callTool($access, $name, $arguments));
        $encoded = json_encode($data);

        foreach (allKeys($data) as $key) {
            // The setup checklist legitimately has a "key" per item.
            if ($key === 'key' && $name === 'get_server') {
                continue;
            }
            expect($forbiddenKeys)->not->toContain($key, "{$name} returned the field {$key}");
            // Nothing that looks like a credential, whatever its exact name.
            expect(preg_match('/password|passwd|secret|token|credential|private/i', $key))->toBe(0, "{$name} returned the field {$key}");
        }
        foreach ($forbiddenValues as $value) {
            expect($encoded)->not->toContain($value, "{$name} returned {$value}");
        }
    }
});

it('works for every permission level, since reading is the lowest', function (string $level) {
    $access = connectedAccess(level: $level);
    fakeServerAvatar(['*/organizations' => 'organizations']);

    expect(toolData(callTool($access, 'list_organizations'))['organizations'])->toHaveCount(2);
})->with(['read', 'action', 'full']);

it('keeps working for a user whose token was just replaced', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);
    $user->saToken->update(['token' => 'a-brand-new-token-0001']);
    $sent = null;
    Http::fake(['*' => function (Request $r) use (&$sent) {
        $sent = $r->header('Authorization')[0];

        return Http::response(fixture('organizations'));
    }]);

    callTool($access, 'list_organizations');

    expect($sent)->toBe('Bearer a-brand-new-token-0001')->and(SaToken::count())->toBe(1);
});

it('lists system users with their applications and never their password or key', function () {
    fakeServerAvatar(['*/system-users*' => 'system_users_paginated']);

    $data = toolData(callTool(connectedAccess(), 'list_system_users', ['organization_id' => O, 'server_id' => S]));

    expect($data['items'])->toHaveCount(2)
        ->and($data['items'][0])->toMatchArray(['id' => 2001, 'username' => 'exampleuser', 'group' => 'system_users', 'ssh_access' => false, 'root_access' => false])
        ->and($data['items'][0]['applications'])->toBe([['id' => 3001, 'name' => 'example-app']])
        ->and($data['items'][1]['applications'])->toBe([])
        ->and(json_encode($data))->not->toContain('password-sample')->not->toContain('ssh-rsa')->not->toContain('license-sample');
    expect(Http::recorded()->first()[0]->url())->toContain('/organizations/'.O.'/servers/'.S.'/system-users')->toContain('pagination=1');
});
