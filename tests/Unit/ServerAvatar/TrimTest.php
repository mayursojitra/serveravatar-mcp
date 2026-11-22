<?php

use App\Services\ServerAvatar\Trim;

// Uses the synthetic fixtures: real shapes, invented values.

it('keeps only the id and name of an organization, and never its members', function () {
    $org = fixture('organizations')['organizations'][0];

    expect($org)->toHaveKey('members');
    expect(Trim::organization($org))->toBe(['id' => $org['id'], 'name' => $org['name']]);
});

it('drops every field of a server that grants access or is internal', function () {
    $server = fixture('server_show')['server'];
    $trimmed = Trim::server($server);

    // The record really does carry these (that is why the allow-list exists).
    foreach (['phpmyadmin_url', 'filemanager_url', 'phpmyadmin_slug', 'sa_filemanager_slug', 'root_password_authentication', 'permit_root_login', 'instance_id'] as $risky) {
        expect($server)->toHaveKey($risky);
        expect($trimmed)->not->toHaveKey($risky);
    }

    expect($trimmed)->toHaveKeys(['id', 'name', 'ip', 'hostname', 'operating_system', 'web_server']);
});

it('drops a field that ServerAvatar adds later, until someone chooses to include it', function () {
    $server = fixture('server_show')['server'] + ['brand_new_secret' => 'x'];

    expect(Trim::server($server))->not->toHaveKey('brand_new_secret');
});

it('keeps only the listed keys', function () {
    expect(Trim::only(['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c']))->toBe(['a' => 1, 'c' => 3]);
});

it('turns PHP versions into a list, whether ServerAvatar sends a list or a JSON string', function () {
    expect(Trim::server(['id' => 1, 'php_versions' => '["8.2","8.3"]'])['php_versions'])->toBe(['8.2', '8.3'])
        ->and(Trim::server(['id' => 1, 'php_versions' => ['8.2']])['php_versions'])->toBe(['8.2'])
        ->and(Trim::server(['id' => 1, 'php_versions' => 'not json'])['php_versions'])->toBe([]);
});

// ---------------------------------------------------------------- the other resources

it('keeps useful application fields and drops keys, raw configuration and license keys', function () {
    $app = fixture('application_show')['application'];
    $detail = Trim::applicationDetail($app);

    foreach (['key', 'extra_conf', 'object_cache_pro_license_key', 'open_basedir', 'disabled_functions', 'dnsimple_record_id', 'auto_prepend_file'] as $risky) {
        expect($app)->toHaveKey($risky);
        expect($detail)->not->toHaveKey($risky);
    }

    expect($detail)->toHaveKeys(['id', 'name', 'framework', 'primary_domain', 'php_version', 'ssl', 'memory_limit', 'system_user']);
});

it('keeps who the application system user is, never its password', function () {
    $app = fixture('application_show')['application'];
    expect($app['system_user'])->toHaveKey('password');

    $detail = Trim::applicationDetail($app);

    expect($detail['system_user'])->toHaveKeys(['id', 'username', 'group'])->not->toHaveKey('password')
        ->and(json_encode($detail))->not->toContain($app['system_user']['password']);
});

it('turns 0/1 flags into booleans', function () {
    $trimmed = Trim::application(['id' => 1, 'ssl' => 1, 'force_ssl' => 0, 'active' => '1', 'name' => 'x']);

    expect($trimmed['ssl'])->toBeTrue()->and($trimmed['force_ssl'])->toBeFalse()->and($trimmed['active'])->toBeTrue();
});

it('reshapes SSL into installed, force_https and certificate', function () {
    $ssl = Trim::ssl(['installed' => true, 'forceHttps' => 1, 'certificateInfo' => ['issuer' => 'CA', 'expires_on' => '2030-01-01', 'internal' => 'x']]);

    expect($ssl)->toBe(['installed' => true, 'force_https' => true, 'certificate' => ['issuer' => 'CA', 'expires_on' => '2030-01-01']]);
    expect(Trim::ssl(['installed' => false]))->toBe(['installed' => false, 'force_https' => false, 'certificate' => null]);
});

it('keeps database facts and drops login helpers', function () {
    $db = Trim::database(fixture('databases_paginated')['databases']['data'][0]);

    expect($db)->toHaveKeys(['id', 'server_id', 'name', 'database_type', 'remote_access'])->not->toHaveKey('pma_auto_login');
});

it('keeps a cron job but redacts and shortens its command', function () {
    $job = Trim::cronjob(['id' => 1, 'name' => 'n', 'schedule' => '* * * * *', 'command' => 'run --password=hunter2 '.str_repeat('x', 800), 'enabled' => 1, 'secret_field' => 'x']);

    expect($job['command'])->toContain('[redacted]')->not->toContain('hunter2')->and(mb_strlen($job['command']))->toBeLessThanOrEqual(501)
        ->and($job['enabled'])->toBeTrue()->and($job)->not->toHaveKey('secret_field');
});

it('keeps only plain values of a record whose shape is unknown', function () {
    $out = Trim::loose(['id' => 1, 'name' => 'worker', 'api_token' => 'x', 'private_key' => 'y', 'secret' => 'z', 'extra_conf' => 'c', 'nested' => ['a' => 1], 'cmd' => 'go --token=abc']);

    expect($out)->toBe(['id' => 1, 'name' => 'worker', 'cmd' => 'go --token=[redacted]']);
});

it('reshapes usage and the setup status', function () {
    $usage = Trim::usage(fixture('server_usage'));
    expect($usage)->toHaveKeys(['disk', 'memory', 'swap', 'load', 'load_five_minute', 'cores', 'uptime'])->not->toHaveKey('serverInfo');

    $status = Trim::status(fixture('server_status'));
    expect($status)->toHaveKey('items')->and($status['items'][0])->toHaveKeys(['key', 'status', 'label', 'percentage']);
});
