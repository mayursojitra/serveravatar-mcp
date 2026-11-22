<?php

use App\Models\ActivityLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// create_application, called through /mcp with a really issued token and a faked ServerAvatar. The request
// shapes follow ServerAvatar's documentation (see docs/api-notes.md for what has been checked live).

function appArgs(array $override = []): array
{
    return array_merge(['organization_id' => 7001, 'server_id' => 7002, 'name' => 'demo-app', 'framework' => 'uptimekuma', 'hostname' => 'app.example.com'], $override);
}

function createApp(array $override = [], string $level = 'full', ?string $access = null): array
{
    Http::fake(['*' => Http::response(['message' => 'Application created.', 'application' => ['id' => 9, 'system_user' => ['password' => 'leak-me']]], 200)]);

    $data = toolData(callTool($access ?? connectedAccess(level: $level), 'create_application', appArgs($override)));
    // WordPress also reads the server first (to see its web server), so take the POST, not the first request.
    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($r) => $r->method() === 'POST');

    return [$data, $sent->data(), parse_url($sent->url(), PHP_URL_PATH)];
}

it('sends the one-click Node body, on the user\'s own domain and with a new system user', function () {
    [$data, $body, $path] = createApp(['framework' => 'n8n']);

    expect($path)->toEndWith('/organizations/7001/servers/7002/applications')
        ->and($body)->toMatchArray([
            'name' => 'demo-app', 'method' => 'one_click', 'framework' => 'n8n', 'temp_domain' => false, 'hostname' => 'app.example.com', 'www' => false,
            'systemUser' => 'new', 'package_manager' => 'npm', 'process_mode' => 'fork',
        ])
        ->and($body['systemUserInfo']['username'])->toMatch('/^[a-z0-9]{5,14}$/')
        ->and($body)->not->toHaveKey('php_version')->not->toHaveKey('temp_sub_domain_name');
    expect($data['ok'])->toBeTrue()->and($data['domain'])->toBe('app.example.com');
});

it('sends a custom PHP application with its php version, and no Node fields', function () {
    [, $body] = createApp(['framework' => 'custom', 'php_version' => '8.3']);

    expect($body)->toMatchArray(['method' => 'custom', 'framework' => 'custom', 'php_version' => 8.3])
        ->and($body)->not->toHaveKey('package_manager')->not->toHaveKey('process_mode');
});

it('lower-cases the hostname and can add the www version', function () {
    [, $body] = createApp(['hostname' => 'App.Example.com', 'www' => true]);

    expect($body)->toMatchArray(['temp_domain' => false, 'hostname' => 'app.example.com', 'www' => true])
        ->and($body)->not->toHaveKey('temp_sub_domain_name');
});

it('needs a hostname and never asks ServerAvatar for a temporary domain', function () {
    Http::fake();
    $access = connectedAccess(level: 'full');
    $args = appArgs();
    unset($args['hostname']);

    $response = callTool($access, 'create_application', $args);

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();

    $tool = collect(flow()->rpc($access, 'tools/list')->json('result.tools'))->firstWhere('name', 'create_application');
    expect($tool['inputSchema']['required'])->toContain('hostname');
});

it('never sends a temporary domain, whatever the type', function (string $framework) {
    [, $body] = createApp(['framework' => $framework] + typeInputs($framework));

    expect($body['temp_domain'])->toBeFalse()->and($body['hostname'])->toBe('app.example.com')->and($body)->not->toHaveKey('temp_sub_domain_name');
})->with(['custom', 'wordpress', 'n8n', 'craftcms']);

it('uses an existing system user and creates no password', function () {
    [$data, $body] = createApp(['system_user_id' => 321]);

    expect($body)->toMatchArray(['systemUser' => 'existing', 'systemUserId' => 321])
        ->and($body)->not->toHaveKey('systemUserInfo')
        ->and($data)->not->toHaveKey('system_user_password');
});

it('returns the generated system user password once, and it is the one that was sent', function () {
    [$data, $body] = createApp();

    expect($data['system_user_password'])->toBe($body['systemUserInfo']['password'])
        ->and($data['system_user'])->toBe($body['systemUserInfo']['username'])
        ->and(strlen($data['system_user_password']))->toBeGreaterThanOrEqual(20)
        ->and($data['system_user_password'])->toMatch('/^[A-Za-z0-9]+$/');
});

it('still hands over the generated passwords when ServerAvatar is slow to answer, and says to check before retrying', function () {
    $sent = null;
    Http::fake(['*' => function ($request) use (&$sent) {
        $sent = $request->data();

        throw new ConnectionException('cURL error 28: Operation timed out after 50001 milliseconds');
    }]);

    $data = toolData(callTool(connectedAccess(level: 'full'), 'create_application', appArgs(['framework' => 'akaunting', 'php_version' => '8.2', 'admin_email' => 'a@example.com'])));

    expect($data['pending'])->toBeTrue()
        ->and($data['message'])->toContain('list_applications')->toContain('do not create it again')
        ->and($data['system_user_password'])->toBe($sent['systemUserInfo']['password'])
        ->and($data['admin_password'])->toBe($sent['password']);
});

it('never returns a password ServerAvatar sends back, and keeps the generated one out of the activity log', function () {
    [$data] = createApp();

    expect(json_encode($data))->not->toContain('leak-me');
    expect(json_encode(ActivityLog::where('tool', 'create_application')->get()->map->toArray()))
        ->not->toContain($data['system_user_password'])->toContain('demo-app');
});

it('rejects bad input and sends nothing', function (array $override) {
    Http::fake();

    $response = callTool(connectedAccess(level: 'full'), 'create_application', appArgs($override));

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'unsupported framework' => [['framework' => 'wordpress']],
    'custom without a php version' => [['framework' => 'custom']],
    'unknown php version' => [['framework' => 'custom', 'php_version' => '5.6']],
    'name with a semicolon' => [['name' => 'a;b']],
    'name too short' => [['name' => 'ab']],
    'name with a space (ServerAvatar rejects it)' => [['name' => 'Demo App']],
    'name with a newline' => [['name' => "ab\ncd"]],
    'hostname with a scheme' => [['hostname' => 'https://example.com']],
    'hostname with a path' => [['hostname' => 'example.com/x']],
    'system user id not a number' => [['system_user_id' => 'root']],
]);

it('ignores a password or system user name supplied by the model', function () {
    [, $body] = createApp(['password' => 'chosen-by-model-1', 'username' => 'root', 'systemUserInfo' => ['username' => 'root', 'password' => 'chosen-by-model-1']]);

    expect($body['systemUserInfo']['password'])->not->toBe('chosen-by-model-1')
        ->and($body['systemUserInfo']['username'])->not->toBe('root');
});

it('is not sent again after a server error, and says so as an error', function () {
    Http::fake(['*' => Http::response(['message' => 'Mern stack does not support this framework.'], 500)]);

    $text = toolError(callTool(connectedAccess(level: 'full'), 'create_application', appArgs(['framework' => 'custom', 'php_version' => '8.3'])));

    Http::assertSentCount(1);
    expect($text)->not->toBeEmpty();
});

// ---------------------------------------------------------------- every application type

use App\Services\ServerAvatar\ApplicationTypes;

/** Inputs a user would give for each type, using the tool's names. */
function typeInputs(string $framework): array
{
    $all = [
        'site_title' => 'My Site', 'admin_username' => 'siteadmin', 'admin_email' => 'admin@example.com', 'timezone' => 'Asia/Kolkata',
        'first_name' => 'Asha', 'last_name' => 'Rao', 'full_name' => 'Demo Moodle', 'short_name' => 'demo', 'summary' => 'A demo site',
    ];
    $spec = ApplicationTypes::get($framework);

    return array_intersect_key($all, $spec['inputs']) + ($spec['php'] !== null ? ['php_version' => end($spec['php'])] : []);
}

/** What ServerAvatar's documentation lists as required for each type, besides the common fields. */
function documentedFields(): array
{
    return [
        'custom' => ['php_version'],
        'wordpress' => ['title', 'username', 'password', 'install_litespeed_cache_plugin', 'database_name', 'php_version'],
        'joomla' => ['php_version'],
        'phpmyadmin' => ['php_version'],
        'akaunting' => ['password', 'php_version'],
        'nextcloud' => ['username', 'password', 'database_name', 'php_version'],
        'moodle' => ['fullname', 'shortname', 'summary', 'username', 'password', 'database_name', 'php_version'],
        'prestashop' => ['firstname', 'lastname', 'password', 'database_name', 'php_version'],
        'statamic' => ['email', 'password', 'php_version', 'webroot'],
        'craftcms' => ['email', 'username', 'password', 'database_name', 'php_version', 'webroot'],
        'uptimekuma' => ['package_manager', 'process_mode'],
        'n8n' => ['package_manager', 'process_mode'],
        'nodered' => ['username', 'password', 'package_manager', 'process_mode'],
        'nodebb' => ['username', 'email', 'password', 'package_manager', 'process_mode'],
    ];
}

it('has a documented field list for every type it offers', function () {
    expect(array_keys(documentedFields()))->toEqualCanonicalizing(ApplicationTypes::keys());
});

it('sends every field the documentation requires for each type', function (string $framework) {
    [$data, $body] = createApp(['framework' => $framework] + typeInputs($framework));

    expect($data['ok'])->toBeTrue()
        ->and($body)->toMatchArray(['framework' => $framework, 'method' => ApplicationTypes::get($framework)['method'], 'systemUser' => 'new']);

    foreach (documentedFields()[$framework] as $field) {
        expect($body)->toHaveKey($field);
    }
})->with(fn () => array_combine(ApplicationTypes::keys(), array_map(fn ($k) => [$k], ApplicationTypes::keys())));

it('sends the fixed values: web roots for Statamic and Craft, npm and fork for Node', function () {
    $access = connectedAccess(level: 'full');

    expect(createApp(['framework' => 'statamic'] + typeInputs('statamic'), access: $access)[1]['webroot'])->toBe('public')
        ->and(createApp(['framework' => 'craftcms'] + typeInputs('craftcms'), access: $access)[1]['webroot'])->toBe('web')
        ->and(createApp(['framework' => 'nodered'] + typeInputs('nodered'), access: $access)[1])->toMatchArray(['package_manager' => 'npm', 'process_mode' => 'fork']);
});

it('generates the admin password and database name, returns them once, and does not take them from the model', function (string $framework) {
    $spec = ApplicationTypes::get($framework);
    [$data, $body] = createApp(['framework' => $framework, 'password' => 'chosen-by-model-1', 'database_name' => 'chosen_db'] + typeInputs($framework));

    expect(json_encode($body))->not->toContain('chosen-by-model-1')->not->toContain('chosen_db');

    if (in_array('admin_password', $spec['generated'], true)) {
        expect($data['admin_password'])->toBe($body['password'])
            ->and($body['password'])->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->toMatch('/[@_-]/');
    } else {
        expect($data)->not->toHaveKey('admin_password');
    }

    if (in_array('database_name', $spec['generated'], true)) {
        expect($data['database_name'])->toBe($body['database_name'])->and($body['database_name'])->toMatch('/^[a-z0-9]{8,18}$/');
    } else {
        expect($data)->not->toHaveKey('database_name');
    }
})->with(fn () => array_combine(ApplicationTypes::keys(), array_map(fn ($k) => [$k], ApplicationTypes::keys())));

it('keeps every generated password out of the activity log', function (string $framework) {
    [$data] = createApp(['framework' => $framework] + typeInputs($framework));
    $log = json_encode(ActivityLog::where('tool', 'create_application')->get()->map->toArray());

    foreach (['admin_password', 'system_user_password'] as $secret) {
        if (isset($data[$secret])) {
            expect($log)->not->toContain($data[$secret]);
        }
    }
})->with(['wordpress', 'craftcms', 'nodebb', 'moodle']);

it('refuses a type whose required input is missing, and sends nothing', function (string $framework, string $missing) {
    Http::fake();
    $args = ['framework' => $framework] + typeInputs($framework);
    unset($args[$missing]);

    $text = toolError(callTool(connectedAccess(level: 'full'), 'create_application', appArgs($args)));

    expect($text)->toContain("needs: {$missing}")->toContain('Nothing was sent');
    Http::assertNothingSent();
})->with([
    'wordpress without a title' => ['wordpress', 'site_title'],
    'wordpress without an admin username' => ['wordpress', 'admin_username'],
    'wordpress without an email' => ['wordpress', 'admin_email'],
    'moodle without a summary' => ['moodle', 'summary'],
    'statamic without an email' => ['statamic', 'admin_email'],
    'craft without an email' => ['craftcms', 'admin_email'],
    'nodebb without an email' => ['nodebb', 'admin_email'],
    'prestashop without a last name' => ['prestashop', 'last_name'],
]);

it('checks the PHP version against the type', function (string $framework, string $version, bool $accepted) {
    Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

    $response = callTool(connectedAccess(level: 'full'), 'create_application', appArgs(['framework' => $framework, 'php_version' => $version] + typeInputs($framework)));

    $accepted ? expect(toolData($response)['ok'])->toBeTrue() : toolError($response);
    $accepted ? Http::assertSentCount(1) : Http::assertNothingSent();
})->with([
    'nextcloud 8.2' => ['nextcloud', '8.2', true],
    'nextcloud 8.5 is too new' => ['nextcloud', '8.5', false],
    'joomla 8.0 is too old' => ['joomla', '8.0', false],
    'moodle 8.0' => ['moodle', '8.0', true],
    'craft 8.1 is too old' => ['craftcms', '8.1', false],
    'custom 7.2' => ['custom', '7.2', true],
]);

it('sends php_version as a string where the documentation says String and as a number otherwise', function () {
    $access = connectedAccess(level: 'full');

    expect(createApp(['framework' => 'joomla', 'php_version' => '8.2'], access: $access)[1]['php_version'])->toBe('8.2')
        ->and(createApp(['framework' => 'wordpress', 'php_version' => '8.2'] + typeInputs('wordpress'), access: $access)[1]['php_version'])->toBe(8.2);
});

it('does not send a PHP version for a Node type, and says an input that belongs to another type was ignored', function () {
    [$data, $body] = createApp(['framework' => 'n8n', 'php_version' => '8.3', 'site_title' => 'Not for n8n']);

    expect($body)->not->toHaveKey('php_version')->not->toHaveKey('title')
        ->and($data['ignored_inputs'])->toBe(['site_title']);
});

it('turns the LiteSpeed cache plugin on only when the server runs OpenLiteSpeed', function (string $webServer, bool $expected) {
    Http::fake([
        '*/applications' => Http::response(['message' => 'ok'], 200),
        '*' => Http::response(['server' => ['id' => 7002, 'name' => 's', 'web_server' => $webServer]], 200),
    ]);

    callTool(connectedAccess(level: 'full'), 'create_application', appArgs(['framework' => 'wordpress'] + typeInputs('wordpress')));

    $post = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($r) => $r->method() === 'POST');
    expect($post->data()['install_litespeed_cache_plugin'])->toBe($expected);
})->with([
    'nginx' => ['nginx', false],
    'openlitespeed' => ['openlitespeed', true],
]);

it('generates passwords that satisfy the strictest documented rule (Craft CMS: 6 to 15 characters, digit, lower case, one of $!@~^*_-.)', function () {
    foreach (range(1, 200) as $i) {
        $p = ApplicationTypes::password(14);

        expect(strlen($p))->toBe(14)
            ->and($p)->toMatch('/[0-9]/')->toMatch('/[a-z]/')->toMatch('/[A-Z]/')->toMatch('/^[A-Za-z0-9][A-Za-z0-9@_-]*$/');
    }

    expect(ApplicationTypes::password())->toHaveLength(24)->not->toBe(ApplicationTypes::password());
});

// ---------------------------------------------------------------- list_application_types

it('lists every type with what to ask the user, at the read level, without any secret', function () {
    $data = toolData(callTool(connectedAccess(level: 'read'), 'list_application_types'));
    $types = collect($data['types'])->keyBy('framework');

    expect($types->keys()->all())->toEqual(ApplicationTypes::keys())
        ->and($types['wordpress']['ask_the_user_for'])->toContain(['param' => 'site_title', 'required' => true, 'description' => 'The title of the new site (up to 100 characters, no angle brackets).'])
        ->and($types['wordpress']['generated_and_returned_once'])->toContain('database_name')
        ->and($types['nextcloud']['php_versions'])->toBe(['8.2', '8.3', '8.4'])
        ->and($types['n8n'])->not->toHaveKey('php_versions')
        ->and($types['n8n']['needs_server_with'])->toContain('Node')
        ->and(json_encode($data))->not->toContain('Mautic"')->not->toContain('mailer');
    expect(array_keys($types->all()))->not->toContain('mautic');
});

it('offers the type choice as questions of at most four options that cover every type', function () {
    $questions = toolData(callTool(connectedAccess(level: 'read'), 'list_application_types'))['type_questions'];
    $frameworks = collect($questions)->flatMap(fn ($q) => collect($q['options'])->pluck('framework'))->filter()->sort()->values()->all();

    expect($frameworks)->toEqual(collect(ApplicationTypes::keys())->sort()->values()->all());

    foreach ($questions as $q) {
        expect(count($q['options']))->toBeLessThanOrEqual(4)->toBeGreaterThanOrEqual(2);
    }
});

it('only asks for inputs that create_application accepts', function () {
    $access = connectedAccess(level: 'full');
    $tool = collect(flow()->rpc($access, 'tools/list')->json('result.tools'))->firstWhere('name', 'create_application');
    $properties = array_keys($tool['inputSchema']['properties']);

    foreach (toolData(callTool($access, 'list_application_types'))['types'] as $type) {
        foreach ($type['ask_the_user_for'] ?? [] as $input) {
            expect($properties)->toContain($input['param']);
        }
    }

    expect($tool['inputSchema']['properties']['framework']['enum'])->toEqual(ApplicationTypes::keys());
});

it('shows list_application_types from the read level and create_application only at full', function (string $level, bool $canCreate) {
    $names = flow()->toolNames(connectedAccess(level: $level));

    expect($names)->toContain('list_application_types');
    $canCreate ? expect($names)->toContain('create_application') : expect($names)->not->toContain('create_application');
})->with([
    'read' => ['read', false],
    'action' => ['action', false],
    'full' => ['full', true],
]);

// ---------------------------------------------------------------- the system user

it('uses the system user name and password the user gave for a new system user, and does not echo the password back', function () {
    [$data, $body] = createApp(['framework' => 'custom', 'php_version' => '8.2', 'system_user_username' => 'shopowner', 'system_user_password' => 'MyOwn-Passw0rd']);

    expect($body)->toMatchArray(['systemUser' => 'new', 'systemUserInfo' => ['username' => 'shopowner', 'password' => 'MyOwn-Passw0rd']])
        ->and($data['system_user'])->toBe('shopowner')
        ->and($data)->not->toHaveKey('system_user_password')
        ->and($data['message'])->not->toContain('system user password');
});

it('generates whichever of the system user name and password the user left out', function () {
    $access = connectedAccess(level: 'full');

    [$onlyName, $b1] = createApp(['system_user_username' => 'shopowner'], access: $access);
    [$onlyPass, $b2] = createApp(['system_user_password' => 'MyOwn-Passw0rd'], access: $access);

    expect($b1['systemUserInfo']['username'])->toBe('shopowner')
        ->and($b1['systemUserInfo']['password'])->toBe($onlyName['system_user_password'])->and(strlen($b1['systemUserInfo']['password']))->toBeGreaterThanOrEqual(20)
        ->and($b2['systemUserInfo']['password'])->toBe('MyOwn-Passw0rd')->and($b2['systemUserInfo']['username'])->toBe($onlyPass['system_user'])
        ->and($onlyPass['system_user'])->toMatch('/^u[a-z0-9]{5,14}$/');
});

it('keeps a system user password the user chose out of the activity log', function () {
    createApp(['system_user_username' => 'shopowner', 'system_user_password' => 'MyOwn-Passw0rd']);

    $log = json_encode(ActivityLog::where('tool', 'create_application')->get()->map->toArray());

    expect($log)->not->toContain('MyOwn-Passw0rd')->toContain('shopowner');
});

it('runs as an existing system user, and says the name and password it was given were not used', function () {
    [$data, $body] = createApp(['system_user_id' => 132471, 'system_user_username' => 'shopowner', 'system_user_password' => 'MyOwn-Passw0rd']);

    expect($body)->toMatchArray(['systemUser' => 'existing', 'systemUserId' => 132471])
        ->and($body)->not->toHaveKey('systemUserInfo')
        ->and($data['ignored_inputs'])->toContain('system_user_username', 'system_user_password')
        ->and($data)->not->toHaveKey('system_user')->not->toHaveKey('system_user_password')
        ->and(json_encode($body))->not->toContain('MyOwn-Passw0rd');
});

it('rejects an unusable system user name or password and sends nothing', function (array $override) {
    Http::fake();

    $response = callTool(connectedAccess(level: 'full'), 'create_application', appArgs($override));

    expect($response->json('error') !== null || $response->json('result.isError') === true)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'name is root' => [['system_user_username' => 'root']],
    'name starts with a digit' => [['system_user_username' => '1shop']],
    'name has upper case' => [['system_user_username' => 'ShopOwner']],
    'name too short' => [['system_user_username' => 'ab']],
    'name with a space' => [['system_user_username' => 'shop owner']],
    'password too short' => [['system_user_password' => 'short1']],
    'password with a space' => [['system_user_password' => 'has a space 123']],
]);

it('offers the system user inputs in the schema', function () {
    $tool = collect(flow()->rpc(connectedAccess(level: 'full'), 'tools/list')->json('result.tools'))->firstWhere('name', 'create_application');

    expect(array_keys($tool['inputSchema']['properties']))->toContain('system_user_id', 'system_user_username', 'system_user_password')
        ->and($tool['inputSchema']['required'])->not->toContain('system_user_username')->not->toContain('system_user_password')
        ->and($tool['description'])->toContain('list_system_users');
});
