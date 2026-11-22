<?php

namespace App\Services\ServerAvatar;

use Illuminate\Support\Str;

/**
 * The application types `create_application` can create, and the one place that knows what each needs.
 * The tool's schema, its validation, the request body, the answer of `list_application_types` and the
 * generated secrets all read from this map, so they cannot drift apart.
 *
 * Built from ServerAvatar's API documentation. Only the plain PHP application has been sent to the real
 * API (it passed validation), so every other body is **not verified live**.
 *
 * Left out on purpose: Mautic (it needs the user's real SMTP password, which would pass through the
 * model) and the Git-based applications (they need SSH keys and connected git accounts).
 *
 * Rules that keep secrets away from the model: admin passwords and database names are always generated
 * here and returned once in the result. The model can supply only the non-secret inputs listed under `inputs`.
 */
final class ApplicationTypes
{
    private const PHP_ALL = ['7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /** Characters allowed in the single "special" character of a generated admin password (all accepted by Craft CMS's rule). */
    private const SPECIALS = '@-_';

    /**
     * Model-supplied, non-secret inputs. `api` is ServerAvatar's field name. `rule` is a Laravel validation rule list.
     *
     * @var array<string, array{api: string, description: string, rule: list<string>}>
     */
    private const PARAMS = [
        'admin_username' => ['api' => 'username', 'description' => 'Login name of the admin account to create: 3 to 32 letters, digits, dots, hyphens or underscores.', 'rule' => ['string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{2,31}$/']],
        'admin_email' => ['api' => 'email', 'description' => 'Email address of the admin account.', 'rule' => ['string', 'max:190', 'email:rfc']],
        'site_title' => ['api' => 'title', 'description' => 'The title of the new site (up to 100 characters, no angle brackets).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,100}$/u']],
        'first_name' => ['api' => 'firstname', 'description' => 'First name of the admin user (up to 50 characters).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,50}$/u']],
        'last_name' => ['api' => 'lastname', 'description' => 'Last name of the admin user (up to 50 characters).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,50}$/u']],
        'full_name' => ['api' => 'fullname', 'description' => 'The full name of the Moodle site (up to 100 characters).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,100}$/u']],
        'short_name' => ['api' => 'shortname', 'description' => 'The short name of the Moodle site (up to 50 characters).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,50}$/u']],
        'summary' => ['api' => 'summary', 'description' => 'A one-line summary of the Moodle site (up to 255 characters).', 'rule' => ['string', 'regex:/^[^\x00-\x1F\x7F<>]{1,255}$/u']],
        'timezone' => ['api' => 'timezone', 'description' => 'Time zone of the site, for example Asia/Kolkata or UTC.', 'rule' => ['string', 'regex:/^[A-Za-z0-9_+\/-]{2,64}$/']],
    ];

    /**
     * @return array<string, array{
     *     label: string, description: string, method: string, runtime: string,
     *     php: list<string>|null, php_as: string,
     *     inputs: array<string, bool>, generated: list<string>, fixed: array<string, mixed>, notes: string|null
     * }>
     */
    public static function all(): array
    {
        $php = fn (string $from): array => array_values(array_filter(self::PHP_ALL, fn ($v) => version_compare($v, $from, '>=')));

        return [
            'custom' => [
                'label' => 'Custom PHP application', 'description' => 'An empty PHP application you deploy code into yourself.',
                'method' => 'custom', 'runtime' => 'php', 'php' => $php('7.2'), 'php_as' => 'number',
                'inputs' => [], 'generated' => [], 'fixed' => [], 'notes' => null,
            ],
            'wordpress' => [
                'label' => 'WordPress', 'description' => 'A WordPress site, installed in one click.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('7.2'), 'php_as' => 'number',
                'inputs' => ['site_title' => true, 'admin_username' => true, 'admin_email' => true, 'timezone' => false],
                'generated' => ['admin_password', 'database_name'], 'fixed' => [], 'notes' => 'The LiteSpeed Cache plugin is installed only when the server runs OpenLiteSpeed.',
            ],
            'joomla' => [
                'label' => 'Joomla', 'description' => 'A Joomla site, installed in one click.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.1'), 'php_as' => 'string',
                'inputs' => [], 'generated' => [], 'fixed' => [], 'notes' => 'ServerAvatar documents no admin fields for Joomla: finish the setup in the Joomla installer.',
            ],
            'phpmyadmin' => [
                'label' => 'phpMyAdmin', 'description' => 'A phpMyAdmin application for managing databases.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('7.4'), 'php_as' => 'string',
                'inputs' => [], 'generated' => [], 'fixed' => [], 'notes' => null,
            ],
            'akaunting' => [
                'label' => 'Akaunting', 'description' => 'An Akaunting accounting application.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.1'), 'php_as' => 'number',
                'inputs' => ['admin_email' => false], 'generated' => ['admin_password'], 'fixed' => [], 'notes' => null,
            ],
            'nextcloud' => [
                'label' => 'Nextcloud', 'description' => 'A Nextcloud file-sharing server.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => ['8.2', '8.3', '8.4'], 'php_as' => 'number',
                'inputs' => ['admin_username' => true, 'admin_email' => false], 'generated' => ['admin_password', 'database_name'], 'fixed' => [], 'notes' => null,
            ],
            'moodle' => [
                'label' => 'Moodle', 'description' => 'A Moodle learning platform.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.0'), 'php_as' => 'string',
                'inputs' => ['full_name' => true, 'short_name' => true, 'summary' => true, 'admin_username' => true, 'admin_email' => false],
                'generated' => ['admin_password', 'database_name'], 'fixed' => [], 'notes' => null,
            ],
            'prestashop' => [
                'label' => 'PrestaShop', 'description' => 'A PrestaShop online store.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.1'), 'php_as' => 'string',
                'inputs' => ['first_name' => true, 'last_name' => true, 'admin_email' => false],
                'generated' => ['admin_password', 'database_name'], 'fixed' => [], 'notes' => null,
            ],
            'statamic' => [
                'label' => 'Statamic', 'description' => 'A Statamic flat-file CMS.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.2'), 'php_as' => 'number',
                'inputs' => ['admin_email' => true], 'generated' => ['admin_password'], 'fixed' => ['webroot' => 'public'], 'notes' => 'The web root is set to "public".',
            ],
            'craftcms' => [
                'label' => 'Craft CMS', 'description' => 'A Craft CMS site.',
                'method' => 'one_click', 'runtime' => 'php', 'php' => $php('8.2'), 'php_as' => 'number',
                'inputs' => ['admin_username' => true, 'admin_email' => true], 'generated' => ['admin_password', 'database_name'], 'fixed' => ['webroot' => 'web'], 'notes' => 'The web root is set to "web".',
            ],
            'uptimekuma' => [
                'label' => 'Uptime Kuma', 'description' => 'An Uptime Kuma monitoring dashboard (Node application).',
                'method' => 'one_click', 'runtime' => 'node', 'php' => null, 'php_as' => 'number',
                'inputs' => [], 'generated' => [], 'fixed' => ['package_manager' => 'npm', 'process_mode' => 'fork'], 'notes' => null,
            ],
            'n8n' => [
                'label' => 'n8n', 'description' => 'An n8n workflow automation application (Node application).',
                'method' => 'one_click', 'runtime' => 'node', 'php' => null, 'php_as' => 'number',
                'inputs' => [], 'generated' => [], 'fixed' => ['package_manager' => 'npm', 'process_mode' => 'fork'], 'notes' => null,
            ],
            'nodered' => [
                'label' => 'Node-RED', 'description' => 'A Node-RED flow editor (Node application).',
                'method' => 'one_click', 'runtime' => 'node', 'php' => null, 'php_as' => 'number',
                'inputs' => ['admin_username' => true], 'generated' => ['admin_password'], 'fixed' => ['package_manager' => 'npm', 'process_mode' => 'fork'], 'notes' => null,
            ],
            'nodebb' => [
                'label' => 'NodeBB', 'description' => 'A NodeBB forum (Node application).',
                'method' => 'one_click', 'runtime' => 'node', 'php' => null, 'php_as' => 'number',
                'inputs' => ['admin_username' => true, 'admin_email' => true], 'generated' => ['admin_password'], 'fixed' => ['package_manager' => 'npm', 'process_mode' => 'fork'], 'notes' => null,
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $framework): ?array
    {
        return self::all()[$framework] ?? null;
    }

    /** Every PHP version any PHP type accepts (the schema's list; the per-type list is checked on call). @return list<string> */
    public static function allPhpVersions(): array
    {
        return self::PHP_ALL;
    }

    /** @return array<string, array{api: string, description: string, rule: list<string>}> */
    public static function params(): array
    {
        return self::PARAMS;
    }

    /**
     * Validation rules for the type-specific inputs of one type. An input another type owns gets no rule,
     * so the validator drops it (the tool reports it as ignored).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, list<string>>
     */
    public static function inputRules(array $spec): array
    {
        $rules = [];

        foreach ($spec['inputs'] as $param => $required) {
            $rules[$param] = [$required ? 'required' : 'nullable', ...self::PARAMS[$param]['rule']];
        }

        return $rules;
    }

    /** @param  array<string, mixed>  $spec */
    public static function phpRule(array $spec): array
    {
        return $spec['php'] === null ? ['nullable'] : ['required', 'string', 'in:'.implode(',', $spec['php'])];
    }

    /**
     * What `list_application_types` returns: one entry per type, in words a model can read to ask the user.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];

        foreach (self::all() as $key => $spec) {
            $inputs = [];

            foreach ($spec['inputs'] as $param => $required) {
                $inputs[] = ['param' => $param, 'required' => $required, 'description' => self::PARAMS[$param]['description']];
            }

            $out[] = array_filter([
                'framework' => $key,
                'label' => $spec['label'],
                'description' => $spec['description'],
                'needs_server_with' => $spec['runtime'] === 'node' ? 'Node.js (for example a MERN server)' : 'PHP',
                'php_versions' => $spec['php'],
                'ask_the_user_for' => $inputs,
                'generated_and_returned_once' => array_map(fn ($g) => $g === 'admin_password' ? 'admin_password (for the admin account)' : 'database_name', $spec['generated']),
                'notes' => $spec['notes'],
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * The type choice as questions of at most four options each, the size a client's own question popup
     * (AskUserQuestion, ask_user_input) accepts. Pick a runtime first, then page through its types.
     *
     * @return list<array{question: string, header: string, options: list<array{label: string, framework?: string, next?: string}>}>
     */
    public static function questionFlow(): array
    {
        $labelOf = fn (string $key): array => ['label' => self::all()[$key]['label'], 'framework' => $key];
        $pages = fn (array $keys, string $header): array => array_map(
            fn (array $chunk, int $i) => ['question' => 'Which application?', 'header' => $header.($i ? ' '.($i + 1) : ''), 'options' => array_map($labelOf, $chunk)],
            $chunks = array_chunk($keys, 4),
            array_keys($chunks),
        );

        $other = array_values(array_diff(array_keys(array_filter(self::all(), fn ($s) => $s['runtime'] === 'php')), ['custom', 'wordpress']));
        $node = array_keys(array_filter(self::all(), fn ($s) => $s['runtime'] === 'node'));

        return [
            ['question' => 'What do you want to create?', 'header' => 'App kind', 'options' => [
                $labelOf('custom'),
                $labelOf('wordpress'),
                ['label' => 'Another PHP app (Joomla, phpMyAdmin, Nextcloud ...)', 'next' => 'other_php'],
                ['label' => 'A Node.js app (n8n, Uptime Kuma ...)', 'next' => 'node'],
            ]],
            ...array_map(fn ($page) => $page + ['shown_when' => 'other_php'], $pages($other, 'PHP app')),
            ...array_map(fn ($page) => $page + ['shown_when' => 'node'], $pages($node, 'Node app')),
        ];
    }

    /**
     * Secrets and names generated here for a type. The model never supplies or sees these before the call.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    public static function generate(string $framework, array $spec, string $applicationName): array
    {
        $out = [];

        if (in_array('admin_password', $spec['generated'], true)) {
            // Craft CMS accepts 6 to 15 characters only. Everything else gets a long password.
            $out['admin_password'] = self::password($framework === 'craftcms' ? 14 : 24);
        }

        if (in_array('database_name', $spec['generated'], true)) {
            $prefix = substr(preg_replace('/[^a-z0-9]/', '', Str::lower($applicationName)) ?? '', 0, 10);
            $out['database_name'] = 'db'.$prefix.Str::lower(Str::random(6));
        }

        return $out;
    }

    /**
     * A random password with an upper-case letter, a lower-case letter, a digit and one special character
     * (@, - or _), never starting with the special one. Uses random_int, so it is safe for credentials.
     */
    public static function password(int $length = 24): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', self::SPECIALS];
        $pool = $sets[0].$sets[1].$sets[2];
        $chars = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], $sets);

        while (count($chars) < $length) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        if (str_contains(self::SPECIALS, $chars[0])) {
            [$chars[0], $chars[count($chars) - 1]] = [$chars[count($chars) - 1], $chars[0]];
        }

        return implode('', $chars);
    }

    /**
     * The body ServerAvatar expects for a new application. One place builds it, so the tool, its tests and
     * a live check all send the same thing.
     *
     * @param  array<string, mixed>  $args  name, hostname, php_version, www, system_user_id, username, password (the new system user's), plus the type's inputs by their tool names
     * @param  array<string, string>  $generated  from generate()
     * @param  bool  $litespeed  whether the server runs OpenLiteSpeed (WordPress only)
     * @return array<string, mixed>
     */
    public static function body(string $framework, array $args, array $generated, bool $litespeed = false): array
    {
        $spec = self::all()[$framework] ?? throw new \InvalidArgumentException("Unknown application type {$framework}");

        // Always the user's own domain: ServerAvatar's free temporary domains failed with HTTP 500 on every server tried.
        $body = [
            'name' => $args['name'],
            'method' => $spec['method'],
            'framework' => $framework,
            'www' => (bool) ($args['www'] ?? false),
            'temp_domain' => false,
            'hostname' => $args['hostname'],
        ];

        $body += ! empty($args['system_user_id'])
            ? ['systemUser' => 'existing', 'systemUserId' => (int) $args['system_user_id']]
            : ['systemUser' => 'new', 'systemUserInfo' => ['username' => $args['username'], 'password' => $args['password']]];

        if ($spec['php'] !== null) {
            $body['php_version'] = $spec['php_as'] === 'string' ? (string) $args['php_version'] : (float) $args['php_version'];
        }

        foreach ($spec['inputs'] as $param => $required) {
            if (isset($args[$param]) && $args[$param] !== '') {
                $body[self::PARAMS[$param]['api']] = $args[$param];
            }
        }

        if (isset($generated['admin_password'])) {
            $body['password'] = $generated['admin_password'];
        }

        if (isset($generated['database_name'])) {
            $body['database_name'] = $generated['database_name'];
        }

        if ($framework === 'wordpress') {
            $body['install_litespeed_cache_plugin'] = $litespeed;
        }

        return $body + $spec['fixed'];
    }
}
