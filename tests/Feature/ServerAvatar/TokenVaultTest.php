<?php

use App\Models\SaToken;
use App\Models\User;
use App\Services\ServerAvatar\InvalidToken;
use App\Services\ServerAvatar\TokenVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const GOOD_TOKEN = 'good-token-9999WXYZ';

function vault(): TokenVault
{
    return app(TokenVault::class);
}

function fakeOrganizations(): void
{
    Http::fake(['*' => Http::response(fixture('organizations'))]);
}

// ---------------------------------------------------------------- saving

it('checks the token with ServerAvatar before saving, and stores it encrypted', function () {
    fakeOrganizations();
    $user = User::factory()->create();

    ['token' => $saved, 'replaced' => $replaced] = vault()->store($user, GOOD_TOKEN);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.serveravatar.test/organizations'
        && $r->header('Authorization') === ['Bearer '.GOOD_TOKEN]);

    expect($replaced)->toBeFalse()
        ->and($saved->last4)->toBe('WXYZ')
        ->and($saved->verified_at)->not->toBeNull()
        ->and($saved->last_error)->toBeNull()
        ->and($saved->organizations)->toHaveCount(2)
        ->and(array_keys($saved->organizations[0]))->toBe(['id', 'name']);

    // At rest, the column is ciphertext: the plain token is nowhere in it, and it needs the app key to read.
    $raw = DB::table('sa_tokens')->where('user_id', $user->id)->value('token');
    expect($raw)->not->toContain(GOOD_TOKEN)
        ->and(Crypt::decryptString($raw))->toBe(GOOD_TOKEN)
        ->and($saved->fresh()->revealToken())->toBe(GOOD_TOKEN);
});

it('strips Bearer and whitespace before checking and saving', function () {
    fakeOrganizations();
    $user = User::factory()->create();

    vault()->store($user, '  Bearer '.GOOD_TOKEN.'  ');

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer '.GOOD_TOKEN]);
    expect($user->saToken->revealToken())->toBe(GOOD_TOKEN);
});

it('keeps the token out of every serialization of the model', function () {
    fakeOrganizations();
    $user = User::factory()->create();
    vault()->store($user, GOOD_TOKEN);

    $saved = $user->fresh()->load('saToken')->saToken;

    expect($saved->toArray())->not->toHaveKey('token')
        ->and($saved->toJson())->not->toContain(GOOD_TOKEN)
        ->and($user->fresh()->load('saToken')->toJson())->not->toContain(GOOD_TOKEN)
        ->and(json_encode($saved->summary()))->not->toContain(GOOD_TOKEN);
});

it('stores nothing when ServerAvatar rejects the token', function () {
    Http::fake(['*' => Http::response(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();

    expect(fn () => vault()->store($user, 'wrong-token-1234'))
        ->toThrow(InvalidToken::class, 'ServerAvatar rejected this token');

    expect(SaToken::count())->toBe(0);
});

it('refuses a blank token or one with spaces inside, without calling ServerAvatar', function (string $bad) {
    $user = User::factory()->create();

    expect(fn () => vault()->store($user, $bad))->toThrow(InvalidToken::class);

    Http::assertNothingSent();
    expect(SaToken::count())->toBe(0);
})->with(['', '   ', 'Bearer ', 'two words here']);

it('says so plainly when ServerAvatar cannot be reached', function () {
    Sleep::fake();
    Http::fake(['*' => fn () => throw new ConnectionException('boom')]);
    $user = User::factory()->create();

    expect(fn () => vault()->store($user, GOOD_TOKEN))->toThrow(InvalidToken::class, 'Could not reach ServerAvatar');
    expect(SaToken::count())->toBe(0);
});

it('replaces the token in place, keeping one row per user', function () {
    fakeOrganizations();
    $user = User::factory()->create();

    vault()->store($user, GOOD_TOKEN);
    ['replaced' => $replaced] = vault()->store($user, 'second-token-5555ABCD');

    expect($replaced)->toBeTrue()
        ->and(SaToken::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->saToken->revealToken())->toBe('second-token-5555ABCD')
        ->and($user->fresh()->saToken->last4)->toBe('ABCD');
});

it('keeps the old token when a replacement is rejected', function () {
    // First request accepted (the original save), the next one rejected (the typo).
    Http::fake(['*' => Http::sequence()->push(fixture('organizations'))->push(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();
    vault()->store($user, GOOD_TOKEN);

    expect(fn () => vault()->store($user, 'typo-token-0000'))->toThrow(InvalidToken::class);

    expect($user->fresh()->saToken->revealToken())->toBe(GOOD_TOKEN);
});

it('keeps replacing a token from disconnecting anything', function () {
    fakeOrganizations();
    $user = User::factory()->create();
    $access = flow()->connect($user)['access_token'];

    vault()->store($user, GOOD_TOKEN);

    flow()->rpc($access, 'tools/list')->assertOk();
});

// ---------------------------------------------------------------- checking again

it('re-checks the token and clears an earlier error', function () {
    fakeOrganizations();
    $user = User::factory()->create();
    vault()->store($user, GOOD_TOKEN);
    $user->saToken()->update(['last_error' => 'old problem', 'verified_at' => now()->subDays(3)]);

    $saved = vault()->test($user->fresh());

    expect($saved->last_error)->toBeNull()
        ->and($saved->verified_at->isToday())->toBeTrue();
});

it('remembers why a token failed its re-check, and keeps the token', function () {
    Http::fake(['*' => Http::sequence()->push(fixture('organizations'))->push(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();
    vault()->store($user, GOOD_TOKEN);

    expect(fn () => vault()->test($user->fresh()))->toThrow(InvalidToken::class);

    $saved = $user->fresh()->saToken;
    expect($saved->last_error)->toContain('ServerAvatar rejected this token')
        ->and($saved->revealToken())->toBe(GOOD_TOKEN);
});

it('records a rejection noticed while a tool was using the token', function () {
    fakeOrganizations();
    $user = User::factory()->create();
    vault()->store($user, GOOD_TOKEN);

    vault()->markRejected($user, str_repeat('x', 400));

    expect(mb_strlen($user->fresh()->saToken->last_error))->toBe(250);
});

// ---------------------------------------------------------------- deleting

it('deletes the token and disconnects every AI client of the user', function () {
    fakeOrganizations();
    $user = User::factory()->create();
    $first = flow()->connect($user, 'read', 'First')['access_token'];
    $second = flow()->connect($user, 'full', 'Second')['access_token'];

    $revoked = vault()->delete($user);

    expect($revoked)->toBe(2)->and(SaToken::count())->toBe(0);
    flow()->rpc($first, 'tools/list')->assertStatus(401);
    flow()->rpc($second, 'tools/list')->assertStatus(401);
});

it('removes the token when the user is deleted', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    SaToken::factory()->for($user)->create();

    $this->actingAs($admin)->delete("/admin/users/{$user->id}");

    expect(SaToken::count())->toBe(0);
});

// ---------------------------------------------------------------- isolation (NFR-2)

it('uses only the token of the user whose action it is', function () {
    fakeOrganizations();
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    vault()->store($sam, 'sams-token-1111AAAA');
    vault()->store($priya, 'priyas-token-2222BBBB');

    vault()->test($priya->fresh());

    // The request made for Priya's re-check carries Priya's token, not Sam's.
    [$lastRequest] = Http::recorded()->last();
    expect($lastRequest->header('Authorization'))->toBe(['Bearer priyas-token-2222BBBB']);

    expect($sam->fresh()->saToken->revealToken())->toBe('sams-token-1111AAAA')
        ->and(SaToken::where('user_id', $priya->id)->sole()->revealToken())->toBe('priyas-token-2222BBBB');
});
