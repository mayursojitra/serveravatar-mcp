<?php

use App\Models\ActivityLog;
use App\Models\User;

function failedLogins(): int
{
    return ActivityLog::where('kind', 'auth.login_failed')->count();
}

it('renders the login page', function () {
    $this->get('/login')->assertOk();
});

it('signs a user in and records it', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $row = ActivityLog::where('kind', 'auth.login')->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->status)->toBe('ok')
        ->and($row->user_role)->toBe('user')
        ->and($row->ip)->not->toBeNull();
});

it('accepts the email in any letter case', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);

    $this->post('/login', ['email' => '  SAM@Example.TEST ', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password and records the failure against the user', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    $row = ActivityLog::where('kind', 'auth.login_failed')->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->status)->toBe('failed')
        ->and($row->meta['reason'])->toBe('invalid_credentials');
});

it('records a failure for an unknown email without a user', function () {
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-it-is']);

    $row = ActivityLog::where('kind', 'auth.login_failed')->sole();
    expect($row->user_id)->toBeNull()
        ->and($row->user_email)->toBe('nobody@example.test')
        ->and($row->meta['reason'])->toBe('invalid_credentials');
});

it('never stores the typed password in the activity log', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'super-secret-guess-123']);

    $stored = ActivityLog::all()->toJson();
    expect($stored)->not->toContain('super-secret-guess-123');
});

it('refuses a disabled account at sign-in with a clear message', function () {
    $user = User::factory()->disabled()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been disabled.']);

    $this->assertGuest();

    $row = ActivityLog::where('kind', 'auth.login_failed')->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->meta['reason'])->toBe('disabled');
    expect(ActivityLog::where('kind', 'auth.login')->count())->toBe(0);
});

it('signs out a user who is disabled while signed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->get('/dashboard')->assertOk();

    // forceFill: `disabled` is deliberately not mass assignable.
    $user->forceFill(['disabled' => true])->save();

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('locks an email and IP pair out after five failures and logs the lockout once', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$i]);
    }

    // The sixth and seventh attempts are blocked, even with the right password.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(ActivityLog::where('meta->reason', 'throttled')->count())->toBe(1)
        ->and(ActivityLog::where('meta->reason', 'invalid_credentials')->count())->toBe(5);
});

it('also throttles by IP across different emails', function () {
    foreach (range(1, 20) as $i) {
        $this->post('/login', ['email' => "person{$i}@example.test", 'password' => 'wrong-guess']);
    }

    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('redirects the home page by sign-in state', function () {
    $this->get('/')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
});

it('signs out', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('keeps guests away from protected pages', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/dashboard', '/activity', '/admin/users', '/admin/activity', '/settings/profile', '/set-password']);
