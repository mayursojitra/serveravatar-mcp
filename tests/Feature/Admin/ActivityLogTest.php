<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Console\Scheduling\Schedule;

it('shows a user only their own activity', function () {
    $mine = User::factory()->create(['email' => 'mine@example.test']);
    $theirs = User::factory()->create(['email' => 'theirs@example.test']);
    ActivityLogger::log('auth.login', 'ok', $mine);
    ActivityLogger::log('auth.login', 'ok', $theirs);

    $this->actingAs($mine)->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('activity/index')
            ->where('scope', 'mine')
            ->has('rows', 1)
            ->where('rows.0.user_email', 'mine@example.test'));
});

it('shows an admin everyone on the admin page, and keeps non-admins out', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    ActivityLogger::log('auth.login', 'ok', $admin);
    ActivityLogger::log('auth.login', 'ok', $user);

    $this->actingAs($admin)->get('/admin/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('activity/index')->where('scope', 'all')->has('rows', 2));

    $this->actingAs($user)->get('/admin/activity')->assertForbidden();
});

it('filters by kind group and status', function () {
    $admin = User::factory()->admin()->create();
    ActivityLogger::log('auth.login', 'ok', $admin);
    ActivityLogger::log('auth.login_failed', 'failed', $admin);
    ActivityLogger::log('admin.user_created', 'ok', $admin);
    ActivityLogger::log('mcp.tool_call', 'denied', $admin);

    $this->actingAs($admin)->get('/admin/activity?kind=auth')
        ->assertInertia(fn ($page) => $page->has('rows', 2));
    $this->actingAs($admin)->get('/admin/activity?status=failed')
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.kind', 'auth.login_failed'));
    $this->actingAs($admin)->get('/admin/activity?kind=mcp&status=denied')
        ->assertInertia(fn ($page) => $page->has('rows', 1));
    $this->actingAs($admin)->get('/admin/activity?kind=nonsense')
        ->assertInertia(fn ($page) => $page->has('rows', 4));
});

it('pages the results newest first', function () {
    $admin = User::factory()->admin()->create();
    foreach (range(1, 30) as $i) {
        ActivityLogger::log('auth.login', 'ok', $admin, ['meta' => ['n' => $i]]);
    }

    $this->actingAs($admin)->get('/admin/activity')
        ->assertInertia(fn ($page) => $page
            ->has('rows', 25)
            ->where('rows.0.meta.n', 30)
            ->where('pagination.total', 30)
            ->where('pagination.last_page', 2));
});

it('records the caller ip, user agent and role snapshot', function () {
    $admin = User::factory()->admin()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'TestAgent/1.0')
        ->post('/login', ['email' => $admin->email, 'password' => 'password']);

    $row = ActivityLog::where('kind', 'auth.login')->sole();
    expect($row->ip)->toBe('203.0.113.9')
        ->and($row->user_agent)->toBe('TestAgent/1.0')
        ->and($row->user_role)->toBe('admin');
});

it('shows the dashboard with recent own activity and admin stats', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->disabled()->create();
    ActivityLogger::log('auth.login', 'ok', $admin);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('recent', 1)
            ->where('stats.users', 2)
            ->where('stats.disabled', 1)
            ->where('stats.admins', 1));

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('stats', null));
});

// ---------------------------------------------------------------- retention (90 days)

it('prunes rows older than the retention period and keeps newer ones', function () {
    $user = User::factory()->create();
    $old = ActivityLogger::log('auth.login', 'ok', $user);
    $old->forceFill(['created_at' => now()->subDays(91)])->save();
    $edge = ActivityLogger::log('auth.login', 'ok', $user);
    $edge->forceFill(['created_at' => now()->subDays(89)])->save();
    $new = ActivityLogger::log('auth.login', 'ok', $user);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(ActivityLog::pluck('id')->all())->toBe([$edge->id, $new->id]);
});

it('defaults to 90 days and accepts a custom number', function () {
    expect(config('serveravatar.activity_retention_days'))->toBe(90);

    $user = User::factory()->create();
    ActivityLogger::log('auth.login', 'ok', $user)->forceFill(['created_at' => now()->subDays(10)])->save();

    $this->artisan('activity:prune', ['--days' => 30])->assertSuccessful();
    expect(ActivityLog::count())->toBe(1);

    $this->artisan('activity:prune', ['--days' => 5])->assertSuccessful();
    expect(ActivityLog::count())->toBe(0);
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'activity:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

it('filters by tool, and only accepts a tool the server offers', function () {
    $user = User::factory()->create();
    ActivityLogger::log('mcp.tool_call', 'ok', $user, ['tool' => 'restart_server']);
    ActivityLogger::log('mcp.tool_call', 'ok', $user, ['tool' => 'list_servers']);

    $this->actingAs($user)->get('/activity?tool=restart_server')
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.tool', 'restart_server')->where('filters.tool', 'restart_server')
            ->where('tools', fn ($tools) => in_array('restart_server', $tools->all(), true) && in_array('create_cronjob', $tools->all(), true)));

    // Anything else is ignored rather than put into a query.
    $this->actingAs($user)->get("/activity?tool=x'%20OR%201=1")
        ->assertInertia(fn ($page) => $page->has('rows', 2)->where('filters.tool', null));
});

it('lets an admin filter by user e-mail, treating wildcards as plain text, and ignores it for a normal user', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.test']);
    $sam = User::factory()->create(['email' => 'sam_one@example.test']);
    $other = User::factory()->create(['email' => 'samXone@example.test']);
    ActivityLogger::log('auth.login', 'ok', $sam);
    ActivityLogger::log('auth.login', 'ok', $other);

    $this->actingAs($admin)->get('/admin/activity?user=sam_one')
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.user_email', 'sam_one@example.test'));
    $this->actingAs($admin)->get('/admin/activity?user=%25')
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    // The user's own page has no such filter: it cannot be used to look at other people.
    $this->actingAs($sam)->get('/activity?user=samXone')
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.user_email', 'sam_one@example.test')->where('filters.user', null));
});

it('counts the last 24 hours of tool calls for the user, and for everyone on the admin dashboard', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    ActivityLogger::log('mcp.tool_call', 'ok', $user, ['tool' => 'list_servers']);
    ActivityLogger::log('mcp.tool_call', 'failed', $user, ['tool' => 'restart_server']);
    ActivityLogger::log('mcp.tool_call', 'denied', $user, ['tool' => 'create_cronjob']);
    ActivityLogger::log('mcp.tool_call', 'ok', $admin, ['tool' => 'list_servers']);
    ActivityLogger::log('mcp.ping', 'ok', $user);
    ActivityLogger::log('mcp.tool_call', 'ok', $user, ['tool' => 'old'])->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('usage.calls_24h', 3)->where('usage.failed_24h', 1)->where('usage.denied_24h', 1)
        ->where('stats', null));

    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('usage.calls_24h', 1)
        ->where('stats.everyone.calls_24h', 4)->where('stats.everyone.failed_24h', 1)->where('stats.everyone.denied_24h', 1));
});
