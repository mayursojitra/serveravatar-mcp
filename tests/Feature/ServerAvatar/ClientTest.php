<?php

use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\AuthenticationFailed;
use App\Services\ServerAvatar\Exceptions\ConnectionFailed;
use App\Services\ServerAvatar\Exceptions\NotFound;
use App\Services\ServerAvatar\Exceptions\PermissionDenied;
use App\Services\ServerAvatar\Exceptions\RateLimited;
use App\Services\ServerAvatar\Exceptions\RequestTimedOut;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use App\Services\ServerAvatar\Exceptions\ServerError;
use App\Services\ServerAvatar\Exceptions\ValidationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SA_TOKEN = 'tok-ABCDEFGHIJ1234567890';

beforeEach(fn () => Sleep::fake());

function sa(): Client
{
    return new Client(SA_TOKEN);
}

// ---------------------------------------------------------------- requests

it('sends the token as a Bearer header to the configured address', function () {
    Http::fake(['*' => Http::response(fixture('organizations'))]);

    sa()->organizations();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.serveravatar.test/organizations'
        && $r->header('Authorization') === ['Bearer '.SA_TOKEN]
        && $r->header('Accept') === ['application/json']
        && $r->method() === 'GET');
});

it('uses another API address when one is given or configured', function () {
    Http::fake(['*' => Http::response(fixture('organizations'))]);

    (new Client(SA_TOKEN, 'https://other.example.test'))->organizations();
    config(['serveravatar.api.base_url' => 'https://configured.example.test']);
    sa()->organizations();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://other.example.test/'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://configured.example.test/'));
});

it('keeps only id and name of each organization, so no member name or email is ever held', function () {
    Http::fake(['*' => Http::response(fixture('organizations'))]);

    $organizations = sa()->organizations();

    expect($organizations)->toHaveCount(2)
        ->and($organizations[0])->toHaveKeys(['id', 'name'])->and(array_keys($organizations[0]))->toBe(['id', 'name']);
    expect(json_encode($organizations))->not->toContain('member@example.test')->not->toContain('members');
});

it('lists servers one page at a time, trimmed, asking for pagination and the page', function () {
    Http::fake(['*' => Http::response(fixture('servers_paginated'))]);

    $page = sa()->servers(7001, 2);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/organizations/7001/servers')
        && str_contains($r->url(), 'pagination=1')
        && str_contains($r->url(), 'page=2'));

    expect($page)->toHaveKeys(['items', 'page', 'last_page', 'total'])
        ->and($page['items'])->toHaveCount(2);

    foreach ($page['items'] as $server) {
        expect($server)->toHaveKeys(['id', 'name', 'ip'])
            ->not->toHaveKeys(['phpmyadmin_url', 'filemanager_url', 'phpmyadmin_slug', 'root_password_authentication']);
    }
});

it('also understands the plain list ServerAvatar returns when pagination is not asked for', function () {
    Http::fake(['*' => Http::response(fixture('servers_list'))]);

    $page = sa()->servers(7001);

    expect($page['items'])->toHaveCount(1)
        ->and($page['page'])->toBe(1)
        ->and($page['last_page'])->toBe(1)
        ->and($page['total'])->toBe(1);
});

it('reads a page under whichever key the resource uses', function () {
    Http::fake(['*' => Http::response(fixture('applications_paginated'))]);

    $page = sa()->page('/organizations/1/servers/2/applications', 'applications');

    expect($page['items'])->toHaveCount(2)->and($page['items'][0])->toHaveKey('id');
});

it('sends a read-only POST with a JSON body (the log fetch)', function () {
    Http::fake(['*' => Http::response(fixture('log_output'))]);

    $result = sa()->postRead('/organizations/1/servers/2/logs', ['log' => 'syslog']);

    expect($result)->toHaveKeys(['output', 'path']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['log' => 'syslog']);
});

// ---------------------------------------------------------------- errors

it('turns each failure into a clear exception that never contains the token', function (int $status, string $class) {
    Http::fake(['*' => Http::response(['message' => 'Server said no'], $status)]);

    try {
        sa()->get('/organizations/1/servers');
        $this->fail('expected an exception');
    } catch (ServerAvatarException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->status)->toBe($status)
            ->and($e->getMessage())->not->toBe('')->not->toContain(SA_TOKEN);
    }
})->with([
    '401' => [401, AuthenticationFailed::class],
    '403' => [403, PermissionDenied::class],
    '404' => [404, NotFound::class],
    '422' => [422, ValidationFailed::class],
    '429' => [429, RateLimited::class],
    '500' => [500, ServerError::class],
    '503' => [503, ServerError::class],
]);

it('tells the user what to do about a rejected token', function () {
    Http::fake(['*' => Http::response(fixture('error_unauthenticated'), 401)]);

    expect(fn () => sa()->organizations())
        ->toThrow(AuthenticationFailed::class, 'Replace it on the ServerAvatar token page');
});

it('explains a permission error and keeps ServerAvatar\'s own words', function () {
    Http::fake(['*' => Http::response(fixture('error_forbidden'), 403)]);

    expect(fn () => sa()->get('/organizations/1/backups'))
        ->toThrow(PermissionDenied::class, 'does not have permission');
});

it('reports the first validation message', function () {
    Http::fake(['*' => Http::response(fixture('error_validation_log'), 422)]);

    try {
        sa()->postRead('/organizations/1/servers/2/logs', []);
    } catch (ValidationFailed $e) {
        expect($e->getMessage())->toContain('The log field is required.')
            ->and($e->errors)->toHaveKey('log');
    }
});

it('does not accept a success that is not JSON', function () {
    Http::fake(['*' => Http::response('<html>maintenance</html>', 200)]);

    expect(fn () => sa()->organizations())->toThrow(ServerError::class);
});

// ---------------------------------------------------------------- retries (FR-15)

it('waits for Retry-After on a rate limit and then succeeds', function () {
    Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '2'])->push(fixture('organizations'))]);

    expect(sa()->organizations())->toHaveCount(2);

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('caps how long it will wait on a rate limit', function () {
    Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '600'])->push(fixture('organizations'))]);

    sa()->organizations();

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('gives up on a persistent rate limit after the configured retries', function () {
    Http::fake(['*' => Http::response([], 429)]);

    expect(fn () => sa()->organizations())->toThrow(RateLimited::class);

    Http::assertSentCount(3);
});

it('retries a read that hit a server error, then succeeds', function () {
    Http::fake(['*' => Http::sequence()->push([], 502)->push([], 503)->push(fixture('organizations'))]);

    expect(sa()->organizations())->toHaveCount(2);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('retries a read after a network failure, then succeeds', function () {
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(fixture('organizations'))]);

    expect(sa()->organizations())->toHaveCount(2);

    Http::assertSentCount(2);
});

it('reports a network failure plainly once retries are used up', function () {
    $attempts = 0;
    Http::fake(['*' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 6: Could not resolve host');
    }]);

    try {
        sa()->organizations();
        $this->fail('expected an exception');
    } catch (ConnectionFailed $e) {
        // The raw network error can mention internals; the message the user sees does not.
        expect($e->getMessage())->toBe('Could not reach ServerAvatar. Try again shortly.');
    }

    expect($attempts)->toBe(3);
});

it('never repeats a request that changes something after a server error or a network failure', function () {
    Http::fake(['*' => Http::response([], 500)]);
    expect(fn () => sa()->post('/organizations/1/servers/2/cronjobs', ['x' => 1]))->toThrow(ServerError::class);
    Http::assertSentCount(1);

    $attempts = 0;
    Http::fake(['*' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('boom');
    }]);
    expect(fn () => sa()->post('/organizations/1/servers/2/cronjobs', ['x' => 1]))->toThrow(ConnectionFailed::class);
    expect($attempts)->toBe(1);

    Sleep::assertNeverSlept();
});

it('says a change that timed out may still have been made', function () {
    Http::fake(['*' => function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds');
    }]);

    try {
        sa()->post('/organizations/1/servers/2/applications', ['x' => 1]);
        $this->fail('expected an exception');
    } catch (RequestTimedOut $e) {
        expect($e->getMessage())->toContain('may still have been made')->toContain('before trying again');
    }

    // A read that times out is simply retried and gets the plain message.
    expect(fn () => sa()->organizations())->toThrow(ConnectionFailed::class, 'Could not reach ServerAvatar');
});

it('does retry a rate-limited change, because a 429 means it was not processed', function () {
    Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push(['ok' => true])]);

    expect(sa()->post('/organizations/1/servers/2/cronjobs', ['x' => 1]))->toBe(['ok' => true]);

    Http::assertSentCount(2);
});

it('does not retry at all when retries are switched off', function () {
    config(['serveravatar.api.max_retries' => 0]);
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => sa()->organizations())->toThrow(ServerError::class);

    Http::assertSentCount(1);
});

it('does not retry a client error such as 404', function () {
    Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);

    expect(fn () => sa()->get('/organizations/1/servers/9'))->toThrow(NotFound::class);

    Http::assertSentCount(1);
});
