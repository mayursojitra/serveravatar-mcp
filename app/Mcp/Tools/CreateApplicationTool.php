<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\ApplicationTypes;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\RequestTimedOut;
use App\Services\ServerAvatar\Trim;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class CreateApplicationTool extends ServerAvatarTool
{
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    protected string $name = 'create_application';

    protected string $title = 'Create an application';

    protected string $description = 'Creates an application on a server. FIRST call list_application_types and ask the user which type they want, with your question or choice popup (AskUserQuestion, ask_user_input) when you have one, using "type_questions" from that result (plain PHP, WordPress, Joomla, phpMyAdmin, Akaunting, Nextcloud, Moodle, PrestaShop, Statamic, Craft CMS, Uptime Kuma, n8n, Node-RED or NodeBB) and for the inputs that type lists; then pass the chosen "framework" and those inputs here. Never ask the user for an admin password or database name: those are generated here. Whatever is generated (admin password, database name, system user password) is returned once in this result, so tell the user to store it now, because it cannot be shown again. Give it a short "name" (letters, digits, hyphens and underscores only, no spaces). ALWAYS ask the user for the domain ("hostname", for example app.example.com): the application is created on their own domain, and its DNS must point at the server. Free ServerAvatar temporary domains are not used because ServerAvatar fails to create them. More domains can be added later with add_domain. System user: ask the user (a choice popup if you have one) whether the application should run as a NEW system user or an EXISTING one. NEW (the default): do nothing more and a random username and strong password are generated and returned once; the user may instead give their own with "system_user_username" and "system_user_password". EXISTING: call list_system_users for that server, show the user the list, let them choose, and pass the chosen id as "system_user_id" (then the username and password inputs are not used). ServerAvatar finishes the installation in the background, so check with list_applications afterwards. Only create an application the user asked for.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        $fields = $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'name' => $schema->string()->required()->description('A short name: letters, digits, hyphens and underscores only (no spaces), 3 to 40 characters, for example my-app.'),
            'framework' => $schema->string()->enum(ApplicationTypes::keys())->required()->description('The application type the user chose, from list_application_types.'),
            'php_version' => $schema->string()->enum(ApplicationTypes::allPhpVersions())->description('Required for the PHP types, for example "8.3"; list_application_types shows the versions each type accepts. Not used for the Node types.'),
            'hostname' => $schema->string()->required()->description('The domain for the application, asked from the user, for example app.example.com. Its DNS must point at the server. Bare host name only: no https://, no path.'),
            'www' => $schema->boolean()->description('Also add the www. version of the domain (default false).'),
            'system_user_id' => $schema->integer()->min(1)->description('Run the application as an existing system user: an id from list_system_users, chosen by the user. Leave it out to create a new system user.'),
            'system_user_username' => $schema->string()->description('Only for a NEW system user: its login name, 3 to 32 lowercase letters, digits, hyphens or underscores, starting with a letter. Leave it out and one is generated. Not used with system_user_id.'),
            'system_user_password' => $schema->string()->description('Only for a NEW system user: a password of 8 to 64 characters with no spaces. Leave it out and a strong one is generated and returned once. Not used with system_user_id.'),
        ];

        foreach (ApplicationTypes::params() as $param => $meta) {
            $fields[$param] = $schema->string()->description($meta['description'].' Only for the types that list it in list_application_types.');
        }

        return $fields;
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $request->merge(['hostname' => is_string($request->get('hostname')) ? strtolower(trim($request->get('hostname'))) : $request->get('hostname')]);

        $spec = is_string($request->get('framework')) ? ApplicationTypes::get($request->get('framework')) : null;

        // Name what is missing exactly as the schema does, so the model can ask the user for it.
        $missing = $spec ? array_keys(array_filter($spec['inputs'], fn ($required, $param) => $required && trim((string) $request->get($param)) === '', ARRAY_FILTER_USE_BOTH)) : [];

        if ($missing) {
            return Response::error("{$request->get('framework')} needs: ".implode(', ', $missing).'. Ask the user for them (list_application_types shows what each type needs) and call again. Nothing was sent to ServerAvatar.');
        }

        $v = $request->validate($this->idRules(['organization_id', 'server_id']) + [
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,38}[A-Za-z0-9]$/'],
            'framework' => ['required', 'string', 'in:'.implode(',', ApplicationTypes::keys())],
            'php_version' => $spec ? ApplicationTypes::phpRule($spec) : ['nullable'],
            'hostname' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME],
            'www' => ['nullable', 'boolean'],
            'system_user_id' => ['nullable', 'integer', 'min:1'],
            'system_user_username' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_-]{2,31}$/', 'not_in:root,admin,administrator,daemon,nobody,mysql,postgres,sshd,www-data,serveravatar'],
            'system_user_password' => ['nullable', 'string', 'regex:/^\S{8,64}$/'],
        ] + ($spec ? ApplicationTypes::inputRules($spec) : []));

        $framework = $v['framework'];
        $spec = ApplicationTypes::get($framework);

        // Inputs that belong to another type are not sent. Say so, so the model does not believe they applied.
        $ignored = array_values(array_diff(
            array_intersect(array_keys(ApplicationTypes::params()), array_keys($request->all())),
            array_keys($spec['inputs']),
        ));
        $creating = empty($v['system_user_id']);

        // The name and password are for a new system user only. With an existing one they are not used.
        if (! $creating) {
            $ignored = [...$ignored, ...array_values(array_filter(['system_user_username', 'system_user_password'], fn ($k) => ! empty($v[$k])))];
        }

        // Names the system user and the database are derived from the application name. The random tail keeps them unique.
        $tail = Str::lower(Str::random(4));
        $username = ($creating ? $v['system_user_username'] ?? null : null) ?: substr('u'.preg_replace('/[^a-z0-9]/', '', Str::lower($v['name'])), 0, 10).$tail;
        $suppliedPassword = $creating ? ($v['system_user_password'] ?? null) : null;
        $password = $suppliedPassword ?: Str::password(24, symbols: false);
        $generated = ApplicationTypes::generate($framework, $spec, $v['name']);

        $args = $v + ['username' => $username, 'password' => $password];

        $extra = [
            'name' => $v['name'],
            'framework' => $framework,
            'domain' => $v['hostname'],
        ];

        if ($creating) {
            // A password the user chose is not echoed back: they already have it.
            $extra += ['system_user' => $username] + ($suppliedPassword ? [] : ['system_user_password' => $password]);
        }

        if (isset($v['admin_username']) && isset($spec['inputs']['admin_username'])) {
            $extra['admin_username'] = $v['admin_username'];
        }

        foreach ($generated as $key => $value) {
            $extra[$key] = $value;
        }

        if ($ignored) {
            $extra['ignored_inputs'] = $ignored;
        }

        $secrets = array_filter([$creating && ! $suppliedPassword ? 'The system user password' : null, isset($generated['admin_password']) ? 'the admin password' : null, isset($generated['database_name']) ? 'the database name' : null]);

        return $this->call($connection, function (Client $c) use ($v, $framework, $args, $generated, $extra, $secrets) {
            // WordPress needs to know whether the server runs OpenLiteSpeed (its cache plugin is required there).
            $litespeed = $framework === 'wordpress' && str_contains(Str::lower((string) (Trim::server($c->get("/organizations/{$v['organization_id']}/servers/{$v['server_id']}")['server'] ?? [])['web_server'] ?? '')), 'litespeed');

            $body = ApplicationTypes::body($framework, $args, $generated, $litespeed);

            $message = "Application {$v['name']} requested.";
            if ($secrets) {
                $message .= ' '.ucfirst(implode(', ', $secrets)).' below '.(count($secrets) > 1 ? 'are' : 'is').' shown only now.';
            }

            try {
                $response = $c->createApplication($v['organization_id'], $v['server_id'], $body);
            } catch (RequestTimedOut) {
                // Slow installs (Akaunting, for one) outlast the wait but are usually carried out anyway. Hand over the
                // generated passwords now, because there is no other chance to show them.
                return ['pending' => true, 'message' => "ServerAvatar had not answered for application {$v['name']} when the wait ended. It is probably still installing: call list_applications to check before doing anything else, and do not create it again. If it is listed, the passwords below belong to it and are shown only now; tell the user to store them. If it is not listed after a minute or two, nothing was created and the passwords are void."] + $this->changed($message, [], $extra);
            }

            return $this->changed($message, $response, $extra);
        });
    }
}
