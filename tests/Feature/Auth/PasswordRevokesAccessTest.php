<?php

use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// A changed or reset password ends what the old one opened: other browser sessions and every AI client.

const REVOKE_NEW = 'Fresh-Passw0rd-Long-9';

function pingStatus(string $access): int
{
    return flow()->rpc($access, 'tools/call', ['name' => 'ping', 'arguments' => (object) []])->status();
}

/** A row in the sessions table, as if another browser were signed in. */
function otherSession(User $user, string $id): void
{
    config(['session.driver' => 'database']);

    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'other browser', 'payload' => '', 'last_activity' => time()]);
}

function resetByEmail(User $user, string $password = REVOKE_NEW): void
{
    // Connecting an AI client signed this test browser in; recovery is for someone who is signed out.
    // An /mcp call makes the API guard the default for the rest of the test; go back to the web guard.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    test()->flushSession();

    Notification::fake();
    test()->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $password) {
        test()->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasNoErrors();

        return true;
    });
}

// ---------------------------------------------------------------- recovery by email

it('disconnects every AI client when the password is reset by email', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);
    expect(pingStatus($access))->toBe(200);

    resetByEmail($user);

    expect(pingStatus($access))->toBe(401)
        ->and(McpGrant::where('user_id', $user->id)->count())->toBe(0)
        ->and(ActivityLog::where('kind', 'auth.password_reset_completed')->sole()->meta['connections_revoked'])->toBe(1);
});

it('revokes the refresh token and an unused authorization code on a reset by email', function () {
    $user = User::factory()->create();
    $connection = flow()->connect($user, 'read');
    $refresh = $connection['refresh_token'] ?? null;

    resetByEmail($user);

    if ($refresh) {
        app('auth')->forgetGuards();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $connection['client_id'] ?? ''])->assertStatus(400);
    }

    expect(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->count())->toBe(0);
});

it('ends the browser sessions of the user on a reset by email, and not those of anyone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    otherSession($user, 'victim-session');
    otherSession($other, 'bystander-session');

    resetByEmail($user);

    expect(DB::table('sessions')->where('id', 'victim-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'bystander-session')->exists())->toBeTrue();
});

it('signs a stale session out once the password changed, with any session driver', function () {
    $user = User::factory()->create();
    $oldHash = $user->getAuthPassword();

    $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])->get('/dashboard')->assertOk();

    $user->forceFill(['password' => REVOKE_NEW])->save();

    $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/dashboard')->assertRedirect(route('login'));
});

// ---------------------------------------------------------------- change in settings

it('disconnects AI clients and other browsers when the password is changed in settings, and keeps this session', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);
    otherSession($user, 'old-browser');

    $this->actingAs($user)->put('/settings/password', ['current_password' => 'password', 'password' => REVOKE_NEW, 'password_confirmation' => REVOKE_NEW])
        ->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(pingStatus($access))->toBe(401)
        ->and(McpGrant::where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'old-browser')->exists())->toBeFalse()
        ->and(ActivityLog::where('kind', 'auth.password_changed')->sole()->meta)->toMatchArray(['forced' => false, 'connections_revoked' => 1]);

    $this->actingAs($user->fresh())->get('/settings/password')->assertOk();
});

it('leaves AI clients alone when the settings change is refused', function () {
    $user = User::factory()->create();
    $access = connectedAccess($user);

    $this->actingAs($user)->put('/settings/password', ['current_password' => 'wrong-password', 'password' => REVOKE_NEW, 'password_confirmation' => REVOKE_NEW])
        ->assertSessionHasErrors('current_password');

    expect(pingStatus($access))->toBe(200);
});

// ---------------------------------------------------------------- forced change

it('also ends other sessions after a forced change, and keeps this one', function () {
    $user = User::factory()->mustChangePassword()->create();
    otherSession($user, 'old-browser');

    $this->actingAs($user)->post('/set-password', ['current_password' => 'password', 'password' => REVOKE_NEW, 'password_confirmation' => REVOKE_NEW])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'old-browser')->exists())->toBeFalse();

    $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
});
