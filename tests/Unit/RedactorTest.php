<?php

use App\Enums\PermissionLevel;
use App\Support\Redactor;

it('replaces the value of any key that looks secret, at any depth', function () {
    $out = Redactor::redact([
        'api_token' => 'a', 'API-KEY' => 'b', 'password' => 'c', 'client_secret' => 'd', 'Authorization' => 'e',
        'cookie' => 'f', 'private_key' => 'g', 'bearer' => 'h',
        'nested' => ['deeper' => ['access_token' => 'i', 'keep' => 'visible']],
    ]);

    expect($out)->toBe([
        'api_token' => '[redacted]', 'API-KEY' => '[redacted]', 'password' => '[redacted]', 'client_secret' => '[redacted]',
        'Authorization' => '[redacted]', 'cookie' => '[redacted]', 'private_key' => '[redacted]', 'bearer' => '[redacted]',
        'nested' => ['deeper' => ['access_token' => '[redacted]', 'keep' => 'visible']],
    ]);
});

it('leaves ordinary values alone', function () {
    expect(Redactor::redact(['server_id' => 42, 'name' => 'web-1', 'flag' => true, 'none' => null, 'list' => [1, 2]]))
        ->toBe(['server_id' => 42, 'name' => 'web-1', 'flag' => true, 'none' => null, 'list' => [1, 2]]);
});

it('cuts long text and says how much was cut', function () {
    $out = Redactor::redact(['log' => str_repeat('x', 600)]);

    expect(mb_strlen($out['log']))->toBeLessThan(560)
        ->and($out['log'])->toContain('[cut 100 characters]');
});

it('bounds large lists and deep nesting', function () {
    $out = Redactor::redact(range(1, 80));
    expect($out)->toHaveCount(51)->and($out['…'])->toBe('30 more items');

    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 'bottom']]]]]]]];
    expect(json_encode(Redactor::redact($deep)))->toContain('[too deep]')->not->toContain('bottom');
});

it('handles objects and non-arrays', function () {
    expect(Redactor::redact((object) ['password' => 'x', 'a' => 1]))->toBe(['password' => '[redacted]', 'a' => 1])
        ->and(Redactor::redact('plain'))->toBe('plain')
        ->and(Redactor::redact(null))->toBeNull();
});

it('orders permission levels so that each includes the ones below', function () {
    expect(PermissionLevel::Read->allows(PermissionLevel::Read))->toBeTrue()
        ->and(PermissionLevel::Read->allows(PermissionLevel::Action))->toBeFalse()
        ->and(PermissionLevel::Read->allows(PermissionLevel::Full))->toBeFalse()
        ->and(PermissionLevel::Action->allows(PermissionLevel::Read))->toBeTrue()
        ->and(PermissionLevel::Action->allows(PermissionLevel::Action))->toBeTrue()
        ->and(PermissionLevel::Action->allows(PermissionLevel::Full))->toBeFalse()
        ->and(PermissionLevel::Full->allows(PermissionLevel::Read))->toBeTrue()
        ->and(PermissionLevel::Full->allows(PermissionLevel::Action))->toBeTrue()
        ->and(PermissionLevel::Full->allows(PermissionLevel::Full))->toBeTrue();
});

it('offers exactly the three levels, read first, for the consent screen', function () {
    expect(collect(PermissionLevel::options())->pluck('value')->all())->toBe(['read', 'action', 'full']);
});

// ---------------------------------------------------------------- free text (commands and log lines)

it('removes secrets from commands and log lines', function (string $input, string $secret) {
    $out = Redactor::redactText($input);

    expect($out)->toContain('[redacted]')->not->toContain($secret);
})->with([
    'long flag with equals' => ['backup --password=hunter2 --db=app', 'hunter2'],
    'long flag with space' => ['run --token abc123XYZ now', 'abc123XYZ'],
    'quoted value' => ['tool --secret "two words here" end', 'two words'],
    'single flag' => ['mysql -password s3cr3t db', 's3cr3t'],
    'mysql attached password' => ['mysqldump -u root -pS3CRET db', 'S3CRET'],
    'bearer header' => ['curl -H "Authorization: Bearer eyJhbGciOi.payload.sig" https://x.test', 'eyJhbGciOi'],
    'basic header' => ['Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz'],
    'credentials in a url' => ['git clone https://deploy:ghp_abcdef@github.example/x.git', 'ghp_abcdef'],
    'env style' => ['DB_PASSWORD=topsecret php artisan migrate', 'topsecret'],
    'query string' => ['GET /hook?api_key=KEY123&x=1 200', 'KEY123'],
    'api key flag' => ['deploy --api-key=AKIA1234 --env=prod', 'AKIA1234'],
]);

it('leaves ordinary commands and log lines alone', function (string $input) {
    expect(Redactor::redactText($input))->toBe($input);
})->with([
    'a cron command' => ['php /home/app/artisan schedule:run >> /dev/null 2>&1'],
    'a log line' => ['Nov 17 10:00:01 web-1 CRON[123]: (www-data) CMD (run-parts /etc/cron.hourly)'],
    'a url without credentials' => ['curl https://example.test/health?check=1'],
    'mkdir -p' => ['mkdir -p /var/www/app'],
    'plain words' => ['the token was refreshed successfully'],
]);

it('keeps the shape of the line around a redacted value', function () {
    expect(Redactor::redactText('backup --password=hunter2 --db=app'))->toBe('backup --password=[redacted] --db=app');
});

it('removes a secret from inside a string value, whatever the key is called', function () {
    $out = Redactor::redact(['command' => 'mysqldump --password=hunter2 db', 'nested' => ['note' => 'Authorization: Bearer abc123']]);

    expect(json_encode($out))->not->toContain('hunter2')->not->toContain('abc123')
        ->and($out['command'])->toBe('mysqldump --password=[redacted] db');
});
