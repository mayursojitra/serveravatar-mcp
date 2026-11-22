<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;

afterEach(fn () => putenv('ADMIN_PASSWORD'));

it('creates an admin from the password in the environment', function () {
    putenv('ADMIN_PASSWORD=Founder-Passw0rd-1');

    $this->artisan('serveravatar:create-admin', ['email' => '  Boss@Example.TEST ', '--name' => 'The Boss'])
        ->expectsOutputToContain('Admin boss@example.test created.')
        ->assertSuccessful();

    $admin = User::where('email', 'boss@example.test')->sole();
    expect($admin->role)->toBe('admin')
        ->and($admin->name)->toBe('The Boss')
        ->and($admin->disabled)->toBeFalse()
        ->and($admin->must_change_password)->toBeFalse()
        ->and(Hash::check('Founder-Passw0rd-1', $admin->password))->toBeTrue()
        ->and($admin->password)->toStartWith('$argon2id$');

    $row = ActivityLog::where('kind', 'admin.admin_created')->sole();
    expect($row->user_id)->toBe($admin->id)->and($row->meta['via'])->toBe('cli');
});

it('can then sign in on the web', function () {
    putenv('ADMIN_PASSWORD=Founder-Passw0rd-1');
    $this->artisan('serveravatar:create-admin', ['email' => 'boss@example.test'])->assertSuccessful();

    $this->post('/login', ['email' => 'boss@example.test', 'password' => 'Founder-Passw0rd-1']);

    $this->assertAuthenticated();
    $this->get('/admin/users')->assertOk();
});

it('refuses an email that already exists and never promotes that account', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);
    $hash = $user->password;
    putenv('ADMIN_PASSWORD=Founder-Passw0rd-1');

    $this->artisan('serveravatar:create-admin', ['email' => 'SAM@example.test'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect($user->fresh()->role)->toBe('user')
        ->and($user->fresh()->password)->toBe($hash);
    expect(ActivityLog::count())->toBe(0);
});

it('refuses a weak password and creates nothing', function () {
    putenv('ADMIN_PASSWORD=weak');

    $this->artisan('serveravatar:create-admin', ['email' => 'boss@example.test'])->assertFailed();

    expect(User::count())->toBe(0);
});

it('refuses an invalid email', function () {
    putenv('ADMIN_PASSWORD=Founder-Passw0rd-1');

    $this->artisan('serveravatar:create-admin', ['email' => 'not-an-email'])->assertFailed();

    expect(User::count())->toBe(0);
});

it('fails without a password when it cannot ask for one', function () {
    $this->artisan('serveravatar:create-admin', ['email' => 'boss@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('ADMIN_PASSWORD')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('does not accept the password as an argument or option', function () {
    putenv('ADMIN_PASSWORD=Founder-Passw0rd-1');

    expect(fn () => Artisan::call('serveravatar:create-admin', ['email' => 'boss@example.test', 'password' => 'Founder-Passw0rd-1']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => Artisan::call('serveravatar:create-admin', ['email' => 'boss@example.test', '--password' => 'Founder-Passw0rd-1']))
        ->toThrow(InvalidOptionException::class);

    expect(User::count())->toBe(0);
});

it('is the only code that sets the admin role', function () {
    $hits = collect(['app', 'routes', 'database'])
        ->flatMap(fn ($dir) => File::allFiles(base_path($dir)))
        ->filter(fn ($file) => str_contains($file->getContents(), 'ROLE_ADMIN') || str_contains($file->getContents(), "'admin'"))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->sort()
        ->values()
        ->all();

    // CreateAdmin assigns it; the model defines it; the rest only read it (middleware, queries, factory state).
    foreach ($hits as $path) {
        $source = File::get(base_path($path));
        expect(preg_match('/->role\s*=\s*(User::ROLE_ADMIN|\'admin\')/', $source) === 1)
            ->toBe($path === 'app/Console/Commands/CreateAdmin.php', "{$path} must not assign the admin role");
    }
});
