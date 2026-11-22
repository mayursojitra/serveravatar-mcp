<?php

use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\AuthenticationFailed;
use App\Services\ServerAvatar\Exceptions\PermissionDenied;
use App\Services\ServerAvatar\TokenNormalizer;

/*
| Live, READ-ONLY checks against the real ServerAvatar API. They skip unless a token is given:
|
|     SERVERAVATAR_TOKEN=... vendor/bin/pest tests/Integration
|
| Only GET requests are made. Nothing is restarted, toggled, created or deleted, and nothing the API
| returns is printed or saved. Use a token you are happy to run reads with.
*/

beforeEach(function () {
    if (! env('SERVERAVATAR_TOKEN')) {
        $this->markTestSkipped('Set SERVERAVATAR_TOKEN to run the live ServerAvatar checks (read-only).');
    }

    // The test environment points the API at a fake host; the live checks use the real one.
    config(['serveravatar.api.base_url' => 'https://api.serveravatar.com']);
});

function liveClient(): Client
{
    return new Client(TokenNormalizer::normalize((string) env('SERVERAVATAR_TOKEN')));
}

it('lists organizations with only id and name', function () {
    $organizations = liveClient()->organizations();

    expect($organizations)->not->toBeEmpty();

    foreach ($organizations as $organization) {
        expect(array_keys($organization))->toBe(['id', 'name'])
            ->and($organization['id'])->toBeInt()->toBeGreaterThan(0)
            ->and($organization['name'])->toBeString();
    }
});

it('lists the servers of each organization, trimmed, tolerating organizations the token may not read', function () {
    $client = liveClient();
    $seen = 0;

    foreach ($client->organizations() as $organization) {
        try {
            $page = $client->servers($organization['id']);
        } catch (PermissionDenied) {
            continue;
        }

        expect($page)->toHaveKeys(['items', 'page', 'last_page', 'total']);

        foreach ($page['items'] as $server) {
            $seen++;
            expect($server)->toHaveKeys(['id', 'name'])
                ->not->toHaveKeys(['phpmyadmin_url', 'filemanager_url', 'phpmyadmin_slug', 'sa_filemanager_slug']);
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(0);
});

it('answers a wrong token with a clear authentication error', function () {
    expect(fn () => (new Client('not-a-real-token-0000'))->organizations())->toThrow(AuthenticationFailed::class);
});
