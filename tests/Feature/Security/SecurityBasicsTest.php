<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Hash;

afterEach(fn () => TrustProxies::at([]));

// ---------------------------------------------------------------- trusted proxies (NFR-7)

it('trusts no proxy by default, so a forged X-Forwarded-For cannot change the logged IP', function () {
    expect(config('serveravatar.trusted_proxies'))->toBe([]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeader('X-Forwarded-For', '198.51.100.7')
        ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-guess']);

    expect(ActivityLog::where('kind', 'auth.login_failed')->sole()->ip)->toBe('203.0.113.5');
});

it('uses X-Forwarded-For only when the caller is a configured trusted proxy', function () {
    TrustProxies::at(['203.0.113.5']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeader('X-Forwarded-For', '198.51.100.7')
        ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-guess']);

    // From an address that is not the trusted proxy, the header is still ignored.
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->withHeader('X-Forwarded-For', '198.51.100.8')
        ->post('/login', ['email' => 'other@example.test', 'password' => 'wrong-guess']);

    expect(ActivityLog::orderBy('id')->pluck('ip')->all())->toBe(['198.51.100.7', '192.0.2.99']);
});

it('parses the TRUSTED_PROXIES setting', function () {
    expect(TrustedProxies::parse(null))->toBe([])
        ->and(TrustedProxies::parse(''))->toBe([])
        ->and(TrustedProxies::parse('  '))->toBe([])
        ->and(TrustedProxies::parse('*'))->toBe('*')
        ->and(TrustedProxies::parse('10.0.0.1, 10.0.0.2/24 ,'))->toBe(['10.0.0.1', '10.0.0.2/24']);
});

// ---------------------------------------------------------------- hashing and passwords

it('hashes passwords with argon2id by default', function () {
    expect(config('hashing.driver'))->toBe('argon2id')
        ->and(Hash::make('Some-Passw0rd-123'))->toStartWith('$argon2id$');

    $user = User::factory()->create(['password' => 'Some-Passw0rd-123']);
    expect($user->password)->toStartWith('$argon2id$');
});

it('never exposes the password or role-changing fields in shared page data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

// ---------------------------------------------------------------- settings

it('lets a user rename themselves but not change their email', function () {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'keep@example.test']);

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'New', 'email' => 'changed@example.test'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New')
        ->and($user->fresh()->email)->toBe('keep@example.test');
});

it('does not let a user delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);

    expect(User::find($user->id))->not->toBeNull();
});

it('changes a password from settings only with the current one, and logs it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'wrong', 'password' => 'Fresh-Passw0rd-12', 'password_confirmation' => 'Fresh-Passw0rd-12',
    ])->assertSessionHasErrors('current_password');

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password', 'password' => 'Fresh-Passw0rd-12', 'password_confirmation' => 'Fresh-Passw0rd-12',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('Fresh-Passw0rd-12', $user->fresh()->password))->toBeTrue();
    expect(ActivityLog::where('kind', 'auth.password_changed')->sole()->meta['forced'])->toBeFalse();
});
