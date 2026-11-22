<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

const NEW_PASSWORD = 'Brand-New-Passw0rd';

it('sends a flagged user to set-password from every page', function (string $path) {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get($path)->assertRedirect(route('password.set'));
})->with(['/', '/dashboard', '/activity', '/settings/profile', '/settings/password', '/settings/appearance']);

it('also holds an admin back until the password is changed', function () {
    $admin = User::factory()->admin()->mustChangePassword()->create();

    $this->actingAs($admin)->get('/admin/users')->assertRedirect(route('password.set'));
    $this->actingAs($admin)->get('/admin/activity')->assertRedirect(route('password.set'));
});

it('blocks writes too, so the flag cannot be skipped with a direct request', function () {
    $admin = User::factory()->admin()->mustChangePassword()->create();

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => NEW_PASSWORD,
    ])->assertRedirect(route('password.set'));

    $this->actingAs($admin)->put('/settings/password', [
        'current_password' => 'password', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
    ])->assertRedirect(route('password.set'));

    expect(User::where('email', 'sneaky@example.test')->exists())->toBeFalse();
});

it('lets a flagged user reach the set-password page and log out', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get('/set-password')->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/set-password'));

    $this->actingAs($user)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('lands a user on set-password right after signing in', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertRedirect(route('password.set'));
});

it('sends an unflagged user away from set-password', function () {
    $this->actingAs(User::factory()->create())->get('/set-password')->assertRedirect(route('dashboard'));
});

it('does not touch guests or the health check', function () {
    $this->get('/login')->assertOk();
    $this->get('/up')->assertOk();
});

it('rejects a wrong current password', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->post('/set-password', [
        'current_password' => 'wrong', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rejects reusing the temporary password', function () {
    $user = User::factory()->mustChangePassword()->create(['password' => 'Temporary-Passw0rd']);

    $this->actingAs($user)->post('/set-password', [
        'current_password' => 'Temporary-Passw0rd', 'password' => 'Temporary-Passw0rd', 'password_confirmation' => 'Temporary-Passw0rd',
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rejects a weak or unconfirmed new password', function (string $new, string $confirm) {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->post('/set-password', [
        'current_password' => 'password', 'password' => $new, 'password_confirmation' => $confirm,
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
})->with([
    'too short' => ['Short-1a', 'Short-1a'],
    'no digit' => ['Long-Enough-Password', 'Long-Enough-Password'],
    'no uppercase' => ['long-enough-passw0rd', 'long-enough-passw0rd'],
    'mismatch' => [NEW_PASSWORD, 'Another-Passw0rd-x'],
]);

it('clears the flag, stores an argon2id hash, logs it, and unlocks the app', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->post('/set-password', [
        'current_password' => 'password', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
    ])->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check(NEW_PASSWORD, $user->password))->toBeTrue()
        ->and($user->password)->toStartWith('$argon2id$');

    $row = ActivityLog::where('kind', 'auth.password_changed')->sole();
    expect($row->user_id)->toBe($user->id)->and($row->meta['forced'])->toBeTrue();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('is satisfied by completing a forgot-password reset', function () {
    $user = User::factory()->mustChangePassword()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => $user->email, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
    ])->assertRedirect(route('login'));

    expect($user->fresh()->must_change_password)->toBeFalse();
    expect(ActivityLog::where('kind', 'auth.password_reset_completed')->where('user_id', $user->id)->exists())->toBeTrue();
});
