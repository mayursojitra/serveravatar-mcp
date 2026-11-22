<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

const STRONG = 'Initial-Passw0rd-1';

function newUserPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Sam Rivera', 'email' => 'sam@example.test', 'password' => STRONG], $overrides);
}

// ---------------------------------------------------------------- access

it('keeps non-admins out of every admin route', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $this->actingAs($user);

    $this->get('/admin/users')->assertForbidden();
    $this->get('/admin/activity')->assertForbidden();
    $this->post('/admin/users', newUserPayload())->assertForbidden();
    $this->patch("/admin/users/{$target->id}", ['name' => 'X', 'disabled' => true])->assertForbidden();
    $this->post("/admin/users/{$target->id}/reset-password", ['password' => STRONG])->assertForbidden();
    $this->delete("/admin/users/{$target->id}")->assertForbidden();

    expect(User::where('email', 'sam@example.test')->exists())->toBeFalse()
        ->and($target->fresh()->disabled)->toBeFalse();
});

it('lists users for an admin', function () {
    User::factory()->count(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/users')->has('users', 3));
});

// ---------------------------------------------------------------- create: normal users only

it('creates a normal user who must set a new password at first sign-in', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', newUserPayload(['email' => 'Sam@Example.TEST']))
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'sam@example.test')->sole();
    expect($user->role)->toBe('user')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->disabled)->toBeFalse()
        ->and(Hash::check(STRONG, $user->password))->toBeTrue()
        ->and($user->password)->toStartWith('$argon2id$');

    $row = ActivityLog::where('kind', 'admin.user_created')->sole();
    expect($row->user_id)->toBe($admin->id)
        ->and($row->user_role)->toBe('admin')
        ->and($row->meta['target_email'])->toBe('sam@example.test');
});

it('can never create an admin from the dashboard, whatever the request says', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/users', newUserPayload([
            'role' => 'admin',
            'is_admin' => true,
            'disabled' => true,
            'must_change_password' => false,
        ]));

    $user = User::where('email', 'sam@example.test')->sole();
    expect($user->role)->toBe('user')
        ->and($user->disabled)->toBeFalse()
        ->and($user->must_change_password)->toBeTrue();
});

it('rejects a duplicate email, a weak password and bad input', function (array $payload, string $field) {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/users', newUserPayload($payload))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate email' => [['email' => 'Taken@Example.test'], 'email'],
    'weak password' => [['password' => 'weakpass'], 'password'],
    'missing name' => [['name' => ''], 'name'],
    'not an email' => [['email' => 'nope'], 'email'],
]);

// ---------------------------------------------------------------- update

it('renames a user and ignores any role in the request', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($admin)->patch("/admin/users/{$user->id}", ['name' => 'New Name', 'disabled' => false, 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->role)->toBe('user');
    expect(ActivityLog::where('kind', 'admin.user_updated')->count())->toBe(1);
});

it('disables a user, logs it and ends their sessions at once', function () {
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-of-user', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time(),
    ]);

    $this->actingAs($admin)->patch("/admin/users/{$user->id}", ['name' => $user->name, 'disabled' => true]);

    expect($user->fresh()->disabled)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect(ActivityLog::where('kind', 'admin.user_disabled')->sole()->meta['target_email'])->toBe($user->email);
});

it('enables a disabled user again and logs it', function () {
    $user = User::factory()->disabled()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->patch("/admin/users/{$user->id}", ['name' => $user->name, 'disabled' => false]);

    expect($user->fresh()->disabled)->toBeFalse();
    expect(ActivityLog::where('kind', 'admin.user_enabled')->count())->toBe(1);
});

// ---------------------------------------------------------------- reset password

it('resets a password, forces a change at next sign-in, ends sessions and logs it', function () {
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-of-user', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time(),
    ]);

    $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-password", ['password' => STRONG])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check(STRONG, $user->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect(ActivityLog::where('kind', 'admin.password_reset')->sole()->meta['target_user_id'])->toBe($user->id);
});

it('refuses a weak password on reset', function () {
    $user = User::factory()->create();
    $before = $user->password;

    $this->actingAs(User::factory()->admin()->create())
        ->post("/admin/users/{$user->id}/reset-password", ['password' => 'weak'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBe($before);
});

// ---------------------------------------------------------------- delete

it('deletes a user but keeps their history, still attributed by email', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'gone@example.test']);
    ActivityLogger::log('auth.login', 'ok', $user);

    $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertSessionHasNoErrors();

    expect(User::find($user->id))->toBeNull();

    $old = ActivityLog::where('kind', 'auth.login')->sole();
    expect($old->user_id)->toBeNull()->and($old->user_email)->toBe('gone@example.test');

    $row = ActivityLog::where('kind', 'admin.user_deleted')->sole();
    expect($row->meta['target_email'])->toBe('gone@example.test');
});

// ---------------------------------------------------------------- admins are command-managed

it('refuses every write against any admin account, including the actor', function (string $method, string $path, array $data) {
    $actor = User::factory()->admin()->create();
    $other = User::factory()->admin()->create(['name' => 'Other Admin']);
    $beforeHash = $other->password;

    foreach ([$actor, $other] as $target) {
        $uri = str_replace('{id}', (string) $target->id, $path);
        $this->actingAs($actor)->{$method}($uri, $data)->assertForbidden();
    }

    expect($other->fresh()->name)->toBe('Other Admin')
        ->and($other->fresh()->password)->toBe($beforeHash)
        ->and($other->fresh()->disabled)->toBeFalse()
        ->and(User::whereIn('id', [$actor->id, $other->id])->count())->toBe(2);
})->with([
    'rename or disable' => ['patch', '/admin/users/{id}', ['name' => 'Changed', 'disabled' => true]],
    'demote attempt' => ['patch', '/admin/users/{id}', ['name' => 'Changed', 'disabled' => false, 'role' => 'user']],
    'reset password' => ['post', '/admin/users/{id}/reset-password', ['password' => STRONG]],
    'delete' => ['delete', '/admin/users/{id}', []],
]);

it('shows admins as command-managed in the list', function () {
    User::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertInertia(fn ($page) => $page->has('users', 2)->where('users.0.role', 'admin'));
});

it('has no route that sets a role', function () {
    $routes = collect(Route::getRoutes())->map->uri()->implode(' ');

    expect($routes)->not->toContain('promote')->not->toContain('demote')->not->toContain('role');
});

it('does not allow mass assigning role, disabled or must_change_password', function () {
    $user = User::create([
        'name' => 'Mass', 'email' => 'mass@example.test', 'password' => STRONG,
        'role' => 'admin', 'disabled' => true, 'must_change_password' => true,
    ])->fresh();

    expect($user->role)->toBe('user')
        ->and($user->disabled)->toBeFalse()
        ->and($user->must_change_password)->toBeFalse();
});
