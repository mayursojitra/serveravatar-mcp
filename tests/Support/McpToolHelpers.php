<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

// Helpers shared by the MCP tool tests: a connected AI client, tool calls and their answers.

function connectedAccess(?User $user = null, string $level = 'read'): string
{
    return flow()->connect($user ?? User::factory()->create(), $level)['access_token'];
}

/** @param  array<string, mixed>  $arguments */
function callTool(string $access, string $name, array $arguments = []): TestResponse
{
    return flow()->rpc($access, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
}

/** @return array<string, mixed> */
function toolData(TestResponse $response): array
{
    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    return json_decode($response->json('result.content.0.text'), true);
}

function toolError(TestResponse $response): string
{
    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();

    return (string) $response->json('result.content.0.text');
}

/** Every key name anywhere in a structure. */
function allKeys(mixed $value): array
{
    $keys = [];
    $walk = function ($v) use (&$walk, &$keys) {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_string($k)) {
                    $keys[] = $k;
                }
                $walk($x);
            }
        }
    };
    $walk($value);

    return array_unique($keys);
}
