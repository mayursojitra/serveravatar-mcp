<?php

namespace App\Services\ServerAvatar;

use App\Support\Redactor;

/**
 * Cuts ServerAvatar records down to what is useful to a person or an AI model (FR-14). A record
 * from the API carries many internal fields and some that grant access (admin-tool links, slugs,
 * license keys, raw configuration, member emails). Only an allow-list of fields is kept, so a field
 * added by ServerAvatar later is dropped until someone chooses to include it.
 */
final class Trim
{
    /** @return array{id: int, name: string} */
    public static function organization(array $org): array
    {
        return [
            'id' => (int) ($org['id'] ?? 0),
            'name' => (string) ($org['name'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public static function server(array $server): array
    {
        // Some records send the PHP versions as a list, others as a JSON-encoded string.
        if (isset($server['php_versions']) && is_string($server['php_versions'])) {
            $decoded = json_decode($server['php_versions'], true);
            $server['php_versions'] = is_array($decoded) ? $decoded : [];
        }

        return self::only($server, [
            'id', 'name', 'hostname', 'ip', 'provider_name', 'operating_system', 'version', 'arch', 'cores',
            'web_server', 'database_type', 'php_versions', 'timezone', 'agent_status', 'health_status',
            'health_message', 'restart_required', 'applications_count', 'created_at',
        ]);
    }

    /** One application in a list. Carries server_id, which the other tools need. */
    public static function application(array $app): array
    {
        $trimmed = self::only($app, [
            'id', 'server_id', 'server_name', 'name', 'framework', 'primary_domain', 'php_version', 'web_server',
            'ssl', 'force_ssl', 'active', 'temp_domain_url', 'size', 'tags', 'created_at',
        ]);

        return self::booleans($trimmed, ['ssl', 'force_ssl', 'active']);
    }

    /**
     * One application in full. Never includes `key`, `extra_conf` (raw web-server configuration), the
     * object-cache license key, `open_basedir`, `disabled_functions` or the DNS record ids.
     *
     * @return array<string, mixed>
     */
    public static function applicationDetail(array $app): array
    {
        $trimmed = self::application($app) + self::only($app, [
            'webroot', 'wildcard', 'automatic_backup', 'fail2ban_enabled', 'block_ai_bot',
            'memory_limit', 'max_execution_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size',
            'pm_type', 'pm_max_children', 'php_timezone', 'dns_propagation', 'updated_at',
        ]);

        // The system user record carries its login password: keep who it is, never the password.
        if (is_array($app['system_user'] ?? null)) {
            $trimmed['system_user'] = self::only($app['system_user'], ['id', 'username', 'group']);
        }

        return self::booleans($trimmed, ['wildcard', 'automatic_backup', 'fail2ban_enabled', 'block_ai_bot']);
    }

    /** @return array<string, mixed> */
    public static function domain(array $domain): array
    {
        return self::booleans(self::only($domain, [
            'id', 'application_id', 'domain', 'dns_propagation', 'autossl', 'temp_domain', 'created_at',
        ]), ['dns_propagation', 'autossl', 'temp_domain']);
    }

    /** @return array<string, mixed> */
    public static function ssl(array $ssl): array
    {
        $certificate = is_array($ssl['certificateInfo'] ?? null)
            ? self::only($ssl['certificateInfo'], ['issuer', 'primary_domain', 'domains', 'issued_on', 'expires_on', 'is_expired', 'validity_left', 'type', 'temp_domain'])
            : null;

        return [
            'installed' => (bool) ($ssl['installed'] ?? false),
            'force_https' => (bool) ($ssl['forceHttps'] ?? false),
            'certificate' => $certificate,
        ];
    }

    /** @return array<string, mixed> */
    public static function database(array $db): array
    {
        $trimmed = self::only($db, ['id', 'server_id', 'name', 'size', 'database_type', 'automatic_backup', 'host', 'created_at']);

        if (array_key_exists('remoteAccess', $db)) {
            $trimmed['remote_access'] = (bool) $db['remoteAccess'];
        }

        return self::booleans($trimmed, ['automatic_backup']);
    }

    /**
     * A database user: who may connect to a database. The user's password is never kept.
     *
     * @return array<string, mixed>
     */
    public static function databaseUser(array $user): array
    {
        $trimmed = self::only($user, ['id', 'database_id', 'username', 'connection_preference', 'created_at']);

        if (isset($user['hostname']) && is_array($user['hostname'])) {
            $trimmed['hostname'] = array_values(array_filter($user['hostname'], 'is_string'));
        }

        if (array_key_exists('remoteAccess', $user)) {
            $trimmed['remote_access'] = (bool) $user['remoteAccess'];
        }

        return $trimmed;
    }

    /**
     * A system user (an "application user") of a server. ServerAvatar's record carries the user's login
     * password and public key, which are dropped; the applications that run as it are kept as id and name.
     *
     * @return array<string, mixed>
     */
    public static function systemUser(array $user): array
    {
        $trimmed = self::booleans(self::only($user, ['id', 'server_id', 'username', 'group', 'root_access', 'ssh_access', 'created_at']), ['root_access', 'ssh_access']);

        $apps = [];
        foreach (is_array($user['applications'] ?? null) ? $user['applications'] : [] as $app) {
            if (is_array($app) && isset($app['id'])) {
                $apps[] = self::only($app, ['id', 'name']);
            }
        }
        $trimmed['applications'] = $apps;

        return $trimmed;
    }

    /**
     * A cron job. The command is kept (it is what a person wants to see) but secret-looking values in it
     * (passwords, tokens, credentials in URLs) are removed.
     *
     * @return array<string, mixed>
     */
    public static function cronjob(array $job): array
    {
        $trimmed = self::only($job, ['id', 'server_id', 'name', 'schedule', 'command', 'enabled', 'system_user', 'custom_scheduling', 'created_at']);

        if (isset($trimmed['command']) && is_string($trimmed['command'])) {
            $trimmed['command'] = self::text($trimmed['command'], 500);
        }

        return self::booleans($trimmed, ['enabled', 'custom_scheduling']);
    }

    /**
     * For records whose shape has not been seen (supervisors, backups): keep plain values, drop anything
     * that looks like a secret, redact and shorten text, and leave out nested structures.
     *
     * @return array<string, mixed>
     */
    public static function loose(array $record): array
    {
        $out = [];

        foreach ($record as $key => $value) {
            if (! is_string($key) || preg_match('/(password|passwd|secret|token|key|license|credential|private|conf)/i', $key)) {
                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::text($value, 300);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function usage(array $usage): array
    {
        $info = is_array($usage['serverInfo'] ?? null) ? $usage['serverInfo'] : [];

        return array_filter([
            'disk' => $usage['disk'] ?? null,
            'memory' => $usage['memory'] ?? null,
            'swap' => $usage['swapMemory'] ?? null,
            'load' => $usage['serverLoad'] ?? null,
            'load_five_minute' => $usage['fiveMinServerLoad'] ?? null,
            'cores' => $usage['cores'] ?? null,
            'uptime' => $usage['serverUptime'] ?? null,
            'restart_required' => $info['restartRequired'] ?? null,
            'timezone' => $info['timezone'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The setup checklist ServerAvatar keeps for a server.
     *
     * @return array<string, mixed>
     */
    public static function status(array $status): array
    {
        $records = array_map(
            fn ($r) => is_array($r) ? self::only($r, ['key', 'status', 'label', 'percentage']) : [],
            is_array($status['record'] ?? null) ? $status['record'] : [],
        );

        return array_filter([
            'status' => $status['status'] ?? null,
            'configuration_percentage' => $status['configurationInPercentage'] ?? null,
            'items' => $records,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function only(array $record, array $keys): array
    {
        return array_intersect_key($record, array_flip($keys));
    }

    /** Redacted and shortened free text. */
    public static function text(string $text, int $max): string
    {
        $text = Redactor::redactText($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /**
     * ServerAvatar sends many flags as 0/1. Make them real booleans.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function booleans(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record) && $record[$key] !== null) {
                $record[$key] = (bool) $record[$key];
            }
        }

        return $record;
    }
}
