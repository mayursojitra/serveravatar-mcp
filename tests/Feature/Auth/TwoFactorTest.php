<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Account\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/** A user with two-factor sign-in on. Returns the user and the secret their "app" holds. */
function userWithTwoFactor(array $attributes = [], bool $admin = false): User
{
    $user = $admin ? User::factory()->admin()->create($attributes) : User::factory()->create($attributes);

    app(TwoFactor::class)->begin($user);
    $user->refresh();

    $codes = app(TwoFactor::class)->confirm($user, currentCode($user));
    expect($codes)->toBeArray()->toHaveCount(TwoFactor::RECOVERY_CODES);

    // Setting it up used the code of this 30-second step. Pretend that was a while ago, so tests can use the next code.
    $user->forceFill(['two_factor_last_step' => $user->two_factor_last_step - 5])->save();

    plainCodes($user, $codes);

    return $user->refresh();
}

function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->two_factor_secret);
}

/** The recovery codes a user was shown when they set it up, remembered per user id for the test. */
function plainCodes(User $user, ?array $remember = null): array
{
    static $codes = [];

    if ($remember !== null) {
        $codes[$user->id] = $remember;
    }

    return $codes[$user->id];
}

function passwordStep(User $user, array $extra = [])
{
    return test()->post('/login', ['email' => $user->email, 'password' => 'password', ...$extra]);
}

// ---------------------------------------------------------------- the sign-in flow

it('does not sign anyone in after the password when two-factor sign-in is on', function () {
    $user = userWithTwoFactor();

    passwordStep($user)->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->get('/dashboard')->assertRedirect('/login');
    expect(ActivityLog::where('kind', 'auth.login')->count())->toBe(0);
});

it('signs the user in with a valid code, and records how', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $this->post('/two-factor-challenge', ['code' => currentCode($user)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $row = ActivityLog::where('kind', 'auth.login')->sole();
    expect($row->user_id)->toBe($user->id)->and($row->meta['two_factor'])->toBe('totp');
});

it('accepts a code typed with spaces', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $code = currentCode($user);
    $this->post('/two-factor-challenge', ['code' => substr($code, 0, 3).' '.substr($code, 3)])
        ->assertRedirect(route('dashboard', absolute: false));
});

it('refuses a wrong code and records the failure', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
    $row = ActivityLog::where('kind', 'auth.two_factor_failed')->sole();
    expect($row->user_id)->toBe($user->id)->and($row->status)->toBe('failed');
});

it('refuses a code that is not six digits', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    foreach (['', 'abcdef', '12345', '1234567', '12 34'] as $bad) {
        $this->post('/two-factor-challenge', ['code' => $bad])->assertSessionHasErrors('code');
    }

    $this->assertGuest();
});

it('never accepts the same code twice', function () {
    $user = userWithTwoFactor();
    $code = currentCode($user);

    passwordStep($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('dashboard', absolute: false));

    // Someone who watched the code over the user's shoulder signs in with the same password and code.
    $this->post('/logout');
    passwordStep($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('lets only one of two racing requests use a code', function () {
    $user = userWithTwoFactor();
    $code = currentCode($user);

    // Two requests loaded the user before either saved its step: both pass the first check, one wins the UPDATE.
    $first = User::find($user->id);
    $second = User::find($user->id);

    expect(app(TwoFactor::class)->acceptCode($first, $code))->toBeTrue()
        ->and(app(TwoFactor::class)->acceptCode($second, $code))->toBeFalse();
});

it('refuses a code from before the last accepted one', function () {
    $user = userWithTwoFactor();
    $current = app(Google2FA::class)->getTimestamp();

    // The last accepted code was already for the next step, so any code for the current or an earlier step is replay.
    $user->forceFill(['two_factor_last_step' => $current + 1])->save();

    expect(app(TwoFactor::class)->acceptCode($user->refresh(), currentCode($user)))->toBeFalse();
});

it('cannot reach any page between the password and the code', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $authorize = '/oauth/authorize?'.http_build_query(flow()->authorizeQuery(flow()->register()));

    foreach (['/dashboard', '/token', '/connections', '/activity', '/settings/security', $authorize] as $path) {
        $this->get($path)->assertRedirect('/login');
    }

    $this->postJson('/mcp', [])->assertUnauthorized();
});

it('sends someone who has no pending password step back to the login page', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
});

it('lets the password step expire', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $this->travel(6)->minutes();

    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses the second step for an account an admin disabled in between', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $user->forceFill(['disabled' => true])->save();

    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses the second step once two-factor sign-in was reset in between', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    app(TwoFactor::class)->reset($user);

    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
    $this->assertGuest();
});

it('does not ask users without two-factor sign-in for a code', function () {
    $user = User::factory()->create();

    passwordStep($user)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('keeps the page the user was heading to through the second step', function () {
    $user = userWithTwoFactor();
    $url = '/oauth/authorize?'.http_build_query(flow()->authorizeQuery(flow()->register()));

    $this->get($url)->assertRedirect('/login');
    passwordStep($user);

    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertRedirectContains('/oauth/authorize');
});

it('keeps the remember-me choice made at the password step', function () {
    $user = userWithTwoFactor();
    passwordStep($user, ['remember' => true]);

    $response = $this->post('/two-factor-challenge', ['code' => currentCode($user)]);

    expect(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeTrue();
});

it('sets no remember-me cookie before the second step', function () {
    $user = userWithTwoFactor();

    $response = passwordStep($user, ['remember' => true]);

    expect(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeFalse();
});

// ---------------------------------------------------------------- brute force

it('stops guessing: after five wrong codes the user must enter the password again', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    foreach (range(1, 4) as $i) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/login');

    // The challenge is gone, so even the right code has nowhere to go.
    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertRedirect('/login');
    $this->assertGuest();
});

it('limits wrong codes per user and IP even when the password is entered again', function () {
    $user = userWithTwoFactor();

    // Five wrong codes in total, spread over several password steps.
    foreach ([2, 2, 1] as $wrong) {
        passwordStep($user);
        foreach (range(1, $wrong) as $i) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }
    }

    passwordStep($user);

    // Locked for now: the right code is refused too, with the throttle message.
    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertSessionHasErrors('code');
    $this->assertGuest();
    expect(session('errors')->first('code'))->toContain('Too many');
});

it('limits wrong codes per user across all IPs', function () {
    $user = userWithTwoFactor();

    foreach (range(1, 20) as $i) {
        RateLimiter::hit('two-factor-user|'.$user->id, 900);
    }

    passwordStep($user);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('/two-factor-challenge', ['code' => currentCode($user)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('does not keep a wrong code in the session', function () {
    $user = userWithTwoFactor();
    passwordStep($user);

    $this->post('/two-factor-challenge', ['code' => '987654']);

    expect(json_encode(session()->all()))->not->toContain('987654');
});

// ---------------------------------------------------------------- recovery codes

it('signs in with a recovery code, once', function () {
    $user = userWithTwoFactor();
    $recovery = plainCodes($user)[0];

    passwordStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    expect(ActivityLog::where('kind', 'auth.two_factor_recovery_used')->sole()->meta['remaining'])->toBe(7);

    $this->post('/logout');
    passwordStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('accepts a recovery code in any case and without the dash', function () {
    $user = userWithTwoFactor();
    $recovery = strtoupper(str_replace('-', '', plainCodes($user)[1]));

    passwordStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect(route('dashboard', absolute: false));
});

it('refuses another user\'s recovery code', function () {
    $user = userWithTwoFactor();
    $other = userWithTwoFactor();

    passwordStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => plainCodes($other)[0]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('lets two racing requests use a recovery code only once', function () {
    $user = userWithTwoFactor();
    $recovery = plainCodes($user)[0];

    expect(app(TwoFactor::class)->acceptRecoveryCode($user, $recovery))->toBeTrue()
        ->and(app(TwoFactor::class)->acceptRecoveryCode($user, $recovery))->toBeFalse();
});

it('stores the secret encrypted and the recovery codes only as hashes', function () {
    $user = userWithTwoFactor();
    $secret = (string) $user->two_factor_secret;

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toContain($secret)->and($raw)->not->toBe($secret);

    foreach (plainCodes($user) as $code) {
        expect(DB::table('two_factor_recovery_codes')->where('code_hash', $code)->exists())->toBeFalse()
            ->and(DB::table('two_factor_recovery_codes')->where('code_hash', str_replace('-', '', $code))->exists())->toBeFalse();
    }
});

// ---------------------------------------------------------------- turning it on and off

it('shows no secret before a setup was started', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('enabled', false)->where('setup', null)->where('recovery_codes', null));
});

it('starts a setup only after the password, and does not turn anything on yet', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/settings/security/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($user->refresh()->two_factor_secret)->toBeNull();

    $this->post('/settings/security/two-factor', ['current_password' => 'password'])->assertRedirect();

    expect($user->refresh()->two_factor_secret)->not->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();

    $this->get('/settings/security')->assertInertia(fn ($page) => $page
        ->where('enabled', false)
        ->where('setup.qr_code', fn ($uri) => str_starts_with($uri, 'data:image/svg+xml;base64,'))
        ->has('setup.manual_key'));
});

it('does not ask for a code at sign-in while a setup is unconfirmed', function () {
    $user = User::factory()->create();
    app(TwoFactor::class)->begin($user);

    passwordStep($user)->assertRedirect(route('dashboard', absolute: false));
});

it('turns on only with a valid code, then shows the recovery codes once', function () {
    $user = User::factory()->create();
    app(TwoFactor::class)->begin($user);
    $this->actingAs($user);

    $this->post('/settings/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->post('/settings/security/two-factor/confirm', ['code' => currentCode($user)])->assertRedirect();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and(ActivityLog::where('kind', 'auth.two_factor_enabled')->count())->toBe(1);

    $this->get('/settings/security')->assertInertia(fn ($page) => $page
        ->where('enabled', true)
        ->where('setup', null)
        ->has('recovery_codes', TwoFactor::RECOVERY_CODES));

    // A second visit no longer shows them, and the secret never appears in a page.
    $response = $this->get('/settings/security');
    $response->assertInertia(fn ($page) => $page->where('recovery_codes', null)->where('recovery_codes_left', TwoFactor::RECOVERY_CODES));
    expect($response->getContent())->not->toContain((string) $user->two_factor_secret);
});

it('signs the user out everywhere else when it is turned on', function () {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    app(TwoFactor::class)->begin($user);
    $this->actingAs($user);

    $this->post('/settings/security/two-factor/confirm', ['code' => currentCode($user)]);

    expect($user->refresh()->remember_token)->not->toBe('old-remember-token');
});

it('shares only whether two-factor sign-in is on, never the secret', function () {
    $user = userWithTwoFactor();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page->where('auth.user.two_factor_enabled', true));
    expect($response->getContent())->not->toContain((string) $user->two_factor_secret);
});

it('turns off only after the password, and forgets the secret and the codes', function () {
    $user = userWithTwoFactor();
    $this->actingAs($user);

    $this->delete('/settings/security/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->delete('/settings/security/two-factor', ['current_password' => 'password'])->assertRedirect();

    expect($user->refresh()->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->recoveryCodes()->count())->toBe(0)
        ->and(ActivityLog::where('kind', 'auth.two_factor_disabled')->count())->toBe(1);
});

it('replaces the recovery codes on request, and the old ones stop working', function () {
    $user = userWithTwoFactor();
    $old = plainCodes($user)[0];
    $this->actingAs($user);

    $this->post('/settings/security/recovery-codes', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->post('/settings/security/recovery-codes', ['current_password' => 'password'])->assertRedirect();

    expect(app(TwoFactor::class)->acceptRecoveryCode($user->refresh(), $old))->toBeFalse()
        ->and($user->recoveryCodes()->count())->toBe(TwoFactor::RECOVERY_CODES)
        ->and(ActivityLog::where('kind', 'auth.two_factor_codes_regenerated')->count())->toBe(1);
});

it('cannot make recovery codes without two-factor sign-in', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/security/recovery-codes', ['current_password' => 'password'])
        ->assertNotFound();
});

it('keeps every two-factor settings route behind sign-in', function () {
    $this->get('/settings/security')->assertRedirect('/login');
    $this->post('/settings/security/two-factor', ['current_password' => 'password'])->assertRedirect('/login');
    $this->post('/settings/security/two-factor/confirm', ['code' => '123456'])->assertRedirect('/login');
    $this->delete('/settings/security/two-factor', ['current_password' => 'password'])->assertRedirect('/login');
    $this->post('/settings/security/recovery-codes', ['current_password' => 'password'])->assertRedirect('/login');
});

// ---------------------------------------------------------------- other ways in

it('does not let a password reset skip the second step', function () {
    $user = userWithTwoFactor();
    $token = app('auth.password.broker')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'A-New-Passw0rd-1',
        'password_confirmation' => 'A-New-Passw0rd-1',
    ])->assertRedirect('/login');

    $this->assertGuest();

    // The new password still leads to the challenge.
    $this->post('/login', ['email' => $user->email, 'password' => 'A-New-Passw0rd-1'])
        ->assertRedirect(route('two-factor.challenge', absolute: false));
    $this->assertGuest();
});

it('does not skip the second step for an account that must change its password', function () {
    $user = userWithTwoFactor(['must_change_password' => true]);

    passwordStep($user)->assertRedirect(route('two-factor.challenge', absolute: false));
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => currentCode($user)]);
    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertRedirect(route('password.set'));
});

// ---------------------------------------------------------------- REQUIRE_TWO_FACTOR

it('lets users without two-factor sign-in use the app when it is not required', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
});

it('sends users without two-factor sign-in to the security page when it is required', function () {
    config(['serveravatar.require_two_factor' => true]);
    $this->actingAs(User::factory()->create());

    $authorize = '/oauth/authorize?'.http_build_query(flow()->authorizeQuery(flow()->register()));

    foreach (['/dashboard', '/token', '/connections', '/activity', '/settings/profile', $authorize] as $path) {
        $this->get($path)->assertRedirect(route('security.edit'));
    }

    $this->get('/settings/security')->assertOk();
    $this->post('/logout')->assertRedirect('/');
});

it('lets the user in once two-factor sign-in is set up', function () {
    config(['serveravatar.require_two_factor' => true]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/connections')->assertRedirect(route('security.edit'));

    $this->post('/settings/security/two-factor', ['current_password' => 'password']);
    $this->post('/settings/security/two-factor/confirm', ['code' => currentCode($user->refresh())]);

    $this->get('/connections')->assertOk();
});

it('lets users who have it on use the app when it is required', function () {
    config(['serveravatar.require_two_factor' => true]);

    $this->actingAs(userWithTwoFactor())->get('/dashboard')->assertOk();
});

it('does not let a user turn it off when it is required, but lets them cancel an unfinished setup', function () {
    config(['serveravatar.require_two_factor' => true]);
    $user = userWithTwoFactor();
    $this->actingAs($user);

    $this->delete('/settings/security/two-factor', ['current_password' => 'password'])->assertSessionHasErrors('current_password');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $other = User::factory()->create();
    app(TwoFactor::class)->begin($other);
    $this->actingAs($other)->delete('/settings/security/two-factor', ['current_password' => 'password']);
    expect($other->refresh()->two_factor_secret)->toBeNull();
});

it('still sends a new user to choose a password before the two-factor setup', function () {
    config(['serveravatar.require_two_factor' => true]);
    $this->actingAs(User::factory()->mustChangePassword()->create());

    $this->get('/dashboard')->assertRedirect(route('password.set'));
    $this->get('/set-password')->assertOk();
});

// ---------------------------------------------------------------- admin reset and the command

it('lets an admin turn off two-factor sign-in for a user, which signs them out and is logged', function () {
    $admin = User::factory()->admin()->create();
    $user = userWithTwoFactor(['remember_token' => 'old']);

    $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-two-factor")->assertRedirect();

    expect($user->refresh()->two_factor_secret)->toBeNull()
        ->and($user->recoveryCodes()->count())->toBe(0)
        ->and($user->remember_token)->not->toBe('old');

    $row = ActivityLog::where('kind', 'admin.two_factor_reset')->sole();
    expect($row->user_id)->toBe($admin->id)->and($row->meta['target_user_id'])->toBe($user->id);
});

it('keeps the two-factor reset away from non-admins', function () {
    $user = userWithTwoFactor();

    $this->actingAs(User::factory()->create())->post("/admin/users/{$user->id}/reset-two-factor")->assertForbidden();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('never resets an admin from the web interface', function () {
    $target = userWithTwoFactor(admin: true);

    $this->actingAs(User::factory()->admin()->create())->post("/admin/users/{$target->id}/reset-two-factor")->assertForbidden();
    expect($target->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('tells the admin which users have two-factor sign-in on, and nothing else about it', function () {
    $user = userWithTwoFactor();

    $response = $this->actingAs(User::factory()->admin()->create())->get('/admin/users');

    $response->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->firstWhere('id', $user->id)['two_factor_enabled'] === true));
    expect($response->getContent())->not->toContain((string) $user->two_factor_secret);
});

it('resets two-factor sign-in from the command, for an admin too', function () {
    $admin = userWithTwoFactor(admin: true);

    $this->artisan('serveravatar:reset-two-factor', ['email' => strtoupper($admin->email)])->assertSuccessful();

    expect($admin->refresh()->two_factor_secret)->toBeNull()
        ->and($admin->recoveryCodes()->count())->toBe(0);

    $row = ActivityLog::where('kind', 'admin.two_factor_reset')->sole();
    expect($row->meta['via'])->toBe('cli');

    // The way in is now the password alone.
    passwordStep($admin)->assertRedirect(route('dashboard', absolute: false));
});

it('says so when the command finds nothing to reset, or no such account', function () {
    $user = User::factory()->create();

    $this->artisan('serveravatar:reset-two-factor', ['email' => $user->email])->assertSuccessful();
    $this->artisan('serveravatar:reset-two-factor', ['email' => 'nobody@example.test'])->assertFailed();

    expect(ActivityLog::where('kind', 'admin.two_factor_reset')->count())->toBe(0);
});

it('removes the recovery codes together with the user', function () {
    $user = userWithTwoFactor();
    $id = $user->id;

    $user->delete();

    expect(DB::table('two_factor_recovery_codes')->where('user_id', $id)->count())->toBe(0);
});
