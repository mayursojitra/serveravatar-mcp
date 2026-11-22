<?php

use App\Mcp\Tools\AddDomainTool;
use App\Mcp\Tools\ControlSupervisorTool;
use App\Mcp\Tools\CreateApplicationTool;
use App\Mcp\Tools\CreateCronjobTool;
use App\Mcp\Tools\CreateDatabaseTool;
use App\Mcp\Tools\GetApplicationTool;
use App\Mcp\Tools\GetServerLogsTool;
use App\Mcp\Tools\GetServerTool;
use App\Mcp\Tools\GetServerUsageTool;
use App\Mcp\Tools\GetSslTool;
use App\Mcp\Tools\InstallSslTool;
use App\Mcp\Tools\ListApplicationsTool;
use App\Mcp\Tools\ListApplicationTypesTool;
use App\Mcp\Tools\ListBackupsTool;
use App\Mcp\Tools\ListCronjobsTool;
use App\Mcp\Tools\ListDatabasesTool;
use App\Mcp\Tools\ListDatabaseUsersTool;
use App\Mcp\Tools\ListDomainsTool;
use App\Mcp\Tools\ListOrganizationsTool;
use App\Mcp\Tools\ListServersTool;
use App\Mcp\Tools\ListSupervisorsTool;
use App\Mcp\Tools\ListSystemUsersTool;
use App\Mcp\Tools\PingTool;
use App\Mcp\Tools\RestartServerTool;
use App\Mcp\Tools\UpdateDatabaseUserTool;
use App\Support\TrustedProxies;

return [
    /*
    | Proxies whose X-Forwarded-* headers are trusted (NFR-7). Empty means trust none, so a caller
    | cannot fake the IP recorded in the activity log. Use "*" only when the app is reachable
    | exclusively through your own reverse proxy.
    */
    'trusted_proxies' => TrustedProxies::parse(env('TRUSTED_PROXIES', '')),

    /*
    | Hosts the app answers to, besides the host of APP_URL and loopback names. A request naming any
    | other host (a forged Host or X-Forwarded-Host) is refused with 400, so absolute URLs such as
    | password-reset links can never point at an attacker's domain. Comma-separated, hostnames only.
    */
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    // Activity log rows older than this many days are pruned by `activity:prune` (FR-18).
    'activity_retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 90),

    /*
    | OAuth token lifetimes for connected AI clients. A short access token limits a leak; clients
    | renew it silently with the refresh token, which lasts longer.
    */
    'oauth' => [
        'access_token_minutes' => (int) env('OAUTH_ACCESS_TOKEN_MINUTES', 60),
        'refresh_token_days' => (int) env('OAUTH_REFRESH_TOKEN_DAYS', 30),
    ],

    // Requests per minute per user on /mcp, per IP on OAuth client registration, and per user on the
    // ServerAvatar token page (each save or test calls ServerAvatar).
    'rate_limits' => [
        'mcp' => (int) env('MCP_RATE_LIMIT', 120),
        'oauth_register' => (int) env('OAUTH_REGISTER_RATE_LIMIT', 20),
        'token_writes' => (int) env('TOKEN_WRITE_RATE_LIMIT', 10),
    ],

    // The ServerAvatar API the saved tokens are used against (FR-7).
    'api' => [
        'base_url' => env('SERVERAVATAR_API_URL', 'https://api.serveravatar.com'),
        'connect_timeout' => (int) env('SERVERAVATAR_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('SERVERAVATAR_TIMEOUT', 20),
        // Creating an application can take well over 20 seconds on ServerAvatar's side (Statamic took 28, Akaunting more
        // than 90), and ServerAvatar sometimes answers slowly when it is limiting requests.
        'slow_timeout' => (int) env('SERVERAVATAR_SLOW_TIMEOUT', 120),
        // Extra attempts after a failure that is safe to repeat (rate limit; server or network error on a read).
        'max_retries' => (int) env('SERVERAVATAR_MAX_RETRIES', 2),
    ],

    /*
    | Tool classes served on /mcp. Each is shown only to connections whose permission level allows
    | it (see App\Mcp\Tools\LeveledTool). M2 ships a stand-in tool; the real tools arrive in M4/M5.
    */
    'mcp_tools' => [
        PingTool::class,
        // Read tools (level: read). Start with list_organizations: its ids feed the others.
        ListOrganizationsTool::class,
        ListServersTool::class,
        GetServerTool::class,
        GetServerUsageTool::class,
        GetServerLogsTool::class,
        ListApplicationsTool::class,
        ListApplicationTypesTool::class,
        ListSystemUsersTool::class,
        GetApplicationTool::class,
        ListDomainsTool::class,
        GetSslTool::class,
        ListDatabasesTool::class,
        ListDatabaseUsersTool::class,
        ListCronjobsTool::class,
        ListBackupsTool::class,
        ListSupervisorsTool::class,
        // Tools that change things. Action level: restart_server, control_supervisor. Full level: the rest.
        RestartServerTool::class,
        ControlSupervisorTool::class,
        AddDomainTool::class,
        InstallSslTool::class,
        CreateDatabaseTool::class,
        UpdateDatabaseUserTool::class,
        CreateCronjobTool::class,
        CreateApplicationTool::class,
    ],
];
