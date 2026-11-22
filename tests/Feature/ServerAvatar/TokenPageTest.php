<?php

use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\SaToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const PAGE_TOKEN = 'page-token-7777QRST';

function acceptOrganizations(): void
{
    Http::fake(['*' => Http::response(fixture('organizations'))]);
}

// ---------------------------------------------------------------- access

it('keeps guests out', function () {
    $this->get('/token')->assertRedirect(route('login'));
    $this->post('/token', ['token' => PAGE_TOKEN])->assertRedirect(route('login'));
    $this->delete('/token')->assertRedirect(route('login'));
});

it('holds back a user with a temporary password', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get('/token')->assertRedirect(route('password.set'));
    $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN])->assertRedirect(route('password.set'));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- showing

it('shows an empty page for a user without a token', function () {
    $this->actingAs(User::factory()->create())->get('/token')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('token/index')->where('token', null));
});

it('shows only the last four characters, the organizations and the check date, never the token', function () {
    $user = User::factory()->create();
    $saved = SaToken::factory()->for($user)->create(['token' => 'secret-whole-token-QQQQ', 'last4' => 'QQQQ']);

    $response = $this->actingAs($user)->get('/token');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('token.last4', 'QQQQ')
        ->has('token.organizations', 1)
        ->where('token.organizations.0.name', 'Example Org')
        ->where('token.last_error', null)
        ->has('token.verified_at')
        ->missing('token.token'));

    expect($response->getContent())->not->toContain('secret-whole-token-QQQQ')->not->toContain($saved->getRawOriginal('token'));
});

it('also keeps the token off the dashboard', function () {
    $user = User::factory()->create();
    SaToken::factory()->for($user)->create(['token' => 'dash-whole-token-ZZZZ', 'last4' => 'ZZZZ']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page->where('token.last4', 'ZZZZ'));
    expect($response->getContent())->not->toContain('dash-whole-token-ZZZZ');
});

// ---------------------------------------------------------------- saving

it('verifies, saves and logs a new token', function () {
    acceptOrganizations();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/token', ['token' => 'Bearer '.PAGE_TOKEN])
        ->assertRedirect(route('token.show'))
        ->assertSessionHas('status', 'Token saved.');

    expect($user->fresh()->saToken->revealToken())->toBe(PAGE_TOKEN);

    $row = ActivityLog::where('kind', 'token.saved')->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->status)->toBe('ok')
        ->and($row->meta)->toBe(['organizations' => 2]);
});

it('logs a replacement as a replacement', function () {
    acceptOrganizations();
    $user = User::factory()->create();
    SaToken::factory()->for($user)->create();

    $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN])
        ->assertSessionHas('status', 'Token replaced. Your connected AI clients keep working.');

    expect(ActivityLog::where('kind', 'token.replaced')->count())->toBe(1)
        ->and(ActivityLog::where('kind', 'token.saved')->count())->toBe(0);
});

it('refuses a token ServerAvatar rejects, saves nothing, logs it, and does not leave the token in the session', function () {
    Http::fake(['*' => Http::response(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN]);

    $response->assertSessionHasErrors(['token' => 'ServerAvatar rejected this token. Check that you copied all of it.']);
    $response->assertSessionMissing('_old_input.token');
    expect(SaToken::count())->toBe(0)
        ->and(json_encode(session()->all()))->not->toContain(PAGE_TOKEN);

    $row = ActivityLog::where('kind', 'token.verify_failed')->sole();
    expect($row->status)->toBe('failed')->and(json_encode($row->toArray()))->not->toContain(PAGE_TOKEN);
});

it('does not put the token in the session for any other failure either', function (array $payload) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/token', $payload);

    expect(json_encode(session()->all()))->not->toContain(PAGE_TOKEN);
})->with([
    'too long' => [['token' => PAGE_TOKEN.str_repeat('x', 600)]],
    'with spaces' => [['token' => PAGE_TOKEN.' '.PAGE_TOKEN]],
]);

it('requires a token and limits its length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/token', [])->assertSessionHasErrors('token');
    $this->actingAs($user)->post('/token', ['token' => str_repeat('a', 513)])->assertSessionHasErrors('token');

    Http::assertNothingSent();
});

it('never writes the token into the activity log, whatever happens', function () {
    // In order: save ok, re-check ok, re-check rejected, replacement rejected.
    Http::fake(['*' => Http::sequence()
        ->push(fixture('organizations'))
        ->push(fixture('organizations'))
        ->push(fixture('error_unauthenticated'), 401)
        ->push(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN]);
    $this->actingAs($user)->post('/token/test');
    $this->actingAs($user)->post('/token/test');
    $this->actingAs($user)->post('/token', ['token' => 'other-token-8888MNOP']);
    $this->actingAs($user)->delete('/token');

    $logs = ActivityLog::all()->toJson();
    expect($logs)->not->toContain(PAGE_TOKEN)->not->toContain('other-token-8888MNOP');
    expect(ActivityLog::where('kind', 'like', 'token.%')->pluck('status', 'kind')->all())->toEqual([
        'token.saved' => 'ok',
        'token.tested' => 'failed',
        'token.verify_failed' => 'failed',
        'token.deleted' => 'ok',
    ]);
});

it('limits how often the token can be saved or tested', function () {
    config(['serveravatar.rate_limits.token_writes' => 2]);
    acceptOrganizations();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN])->assertRedirect();
    $this->actingAs($user)->post('/token/test')->assertRedirect();
    $this->actingAs($user)->post('/token/test')->assertStatus(429);

    // Another user has their own allowance.
    $other = User::factory()->create();
    $this->actingAs($other)->post('/token', ['token' => 'another-token-3333ZZZZ'])->assertRedirect();
});

it('sends a user who came from the consent screen back to it once the token is saved', function () {
    acceptOrganizations();
    $user = User::factory()->create();
    $flow = flow();
    $clientId = $flow->register();
    $url = '/oauth/authorize?'.http_build_query($flow->authorizeQuery($clientId));
    $remembered = Request::create(config('app.url').$url)->fullUrl();

    $flow->consent($user, $clientId, withToken: false)->assertRedirect(route('token.show'));

    $this->post('/token', ['token' => PAGE_TOKEN])->assertRedirect($remembered);
});

// ---------------------------------------------------------------- testing again

it('re-checks the saved token on request', function () {
    acceptOrganizations();
    $user = User::factory()->create();
    SaToken::factory()->for($user)->create(['last_error' => 'stale', 'verified_at' => now()->subWeek()]);

    $this->actingAs($user)->post('/token/test')->assertSessionHas('status', 'The token works.');

    expect($user->fresh()->saToken->last_error)->toBeNull();
    expect(ActivityLog::where('kind', 'token.tested')->sole()->status)->toBe('ok');
});

it('shows why a re-check failed, and keeps the token', function () {
    Http::fake(['*' => Http::response(fixture('error_unauthenticated'), 401)]);
    $user = User::factory()->create();
    SaToken::factory()->for($user)->create();

    $this->actingAs($user)->post('/token/test')->assertSessionHas('error');

    $saved = $user->fresh()->saToken;
    expect($saved->last_error)->toContain('rejected')->and($saved)->not->toBeNull();
    expect(ActivityLog::where('kind', 'token.tested')->sole()->status)->toBe('failed');

    $this->actingAs($user)->get('/token')
        ->assertInertia(fn ($page) => $page->where('token.last_error', fn ($e) => str_contains($e, 'rejected')));
});

it('has nothing to re-check without a token', function () {
    $this->actingAs(User::factory()->create())->post('/token/test')->assertSessionHas('error', 'Save a token first.');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- deleting

it('deletes the token, disconnects the AI clients, and logs how many', function () {
    $user = User::factory()->create();
    $access = flow()->connect($user, 'full', 'Claude')['access_token'];

    $this->actingAs($user)->delete('/token')->assertSessionHas('status');

    expect(SaToken::count())->toBe(0)->and(McpGrant::count())->toBe(0);
    flow()->rpc($access, 'tools/list')->assertStatus(401);

    expect(ActivityLog::where('kind', 'token.deleted')->sole()->meta)->toEqual(['connections_revoked' => 1]);
});

it('does nothing when there is no token to delete', function () {
    $this->actingAs(User::factory()->create())->delete('/token')->assertRedirect();

    expect(ActivityLog::where('kind', 'token.deleted')->count())->toBe(0);
});

it('lets a user touch only their own token', function () {
    $sam = User::factory()->create();
    $priya = User::factory()->create();
    SaToken::factory()->for($priya)->create(['last4' => 'PPPP']);

    $this->actingAs($sam)->get('/token')->assertInertia(fn ($page) => $page->where('token', null));
    $this->actingAs($sam)->delete('/token');

    expect(SaToken::where('user_id', $priya->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- what other pages may show (FR-3)

it('shows an admin only whether each user has a token, never the token or its last characters', function () {
    $admin = User::factory()->admin()->create();
    $with = User::factory()->create(['email' => 'with@example.test']);
    $without = User::factory()->create(['email' => 'without@example.test']);
    SaToken::factory()->for($with)->create(['token' => 'admin-must-not-see-ABCD', 'last4' => 'ABCD']);

    $response = $this->actingAs($admin)->get('/admin/users');

    $response->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->firstWhere('email', 'with@example.test')['has_token'] === true
        && collect($users)->firstWhere('email', 'without@example.test')['has_token'] === false));

    expect($response->getContent())->not->toContain('admin-must-not-see-ABCD')->not->toContain('ABCD');
});

it('lets the activity page filter token changes', function () {
    acceptOrganizations();
    $user = User::factory()->create();
    $this->actingAs($user)->post('/token', ['token' => PAGE_TOKEN]);

    $this->get('/activity?kind=token')->assertInertia(fn ($page) => $page
        ->has('rows', 1)
        ->where('rows.0.kind', 'token.saved'));
});

it('warns on the Connections page when there is no token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/connections')->assertInertia(fn ($page) => $page->where('hasToken', false));

    SaToken::factory()->for($user)->create();

    $this->actingAs($user)->get('/connections')->assertInertia(fn ($page) => $page->where('hasToken', true));
});
