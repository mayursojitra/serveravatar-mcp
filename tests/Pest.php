<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

require_once __DIR__.'/Support/Tools/StubTools.php';
require_once __DIR__.'/Support/McpToolHelpers.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run on the dedicated `serveravatar_mcp_test` MySQL database (see phpunit.xml).
| Vite is faked, so backend tests do not need `npm run build`.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();

        // No test may ever reach the real ServerAvatar API: every call must be faked.
        Http::preventStrayRequests();
    })
    ->in('Feature');

// Live checks against the real ServerAvatar API. They skip unless SERVERAVATAR_TOKEN is set (see the test).
pest()->extend(TestCase::class)->in('Integration');

/** A client that plays the AI client's part of the OAuth flow. */
function flow(): OAuthFlow
{
    return new OAuthFlow(test());
}

/**
 * A synthetic ServerAvatar response from tests/fixtures/serveravatar. They hold the real shapes of
 * the API with invented values, so nothing here identifies anyone.
 *
 * @return array<string, mixed>
 */
function fixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/fixtures/serveravatar/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}
