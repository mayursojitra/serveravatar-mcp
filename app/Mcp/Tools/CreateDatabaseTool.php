<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\DatabaseAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class CreateDatabaseTool extends ServerAvatarTool
{
    protected string $name = 'create_database';

    protected string $title = 'Create a database';

    protected string $description = 'Creates a database and a database user on a server. BEFORE calling it you MUST ask the user, and wait for the answers, for: (1) the database NAME, (2) the database USER name (you may suggest "<name>_user" and let them accept or change it), and (3) who may connect. Never invent the name or the user name, and never pick who may connect yourself, not even when a name seems obvious from an application. Put all three in ONE popup of separate questions if you have a question or choice popup (AskUserQuestion, ask_user_input), one question each, not one question that mixes them: question 1, the database name (offer one or two suggested names, for example based on the application, and let the user type their own); question 2, the database user (suggest "<name>_user", and let them type their own); question 3, who may connect, with the three choices below. The user already asked for a database, so do not ask whether to create one, do not offer a "do not create" or an "auto-name it" choice, and do not list or mention the databases or users that already exist (only if the chosen name is already taken). Without a popup, ask the three as plain questions in one message. Who may connect: the server itself only ("localhost", the default and the safe choice), only certain IP addresses ("specific_ip_addresses", with their IPv4 addresses in "hostname"), or any computer on the internet ("everywhere", which exposes the database to the whole internet: offer it as a choice but say that, and recommend one of the other two). A strong password is generated here and returned once in this result: tell the user to store it now, because it cannot be shown again. Never write it into files, tickets or messages other than to the user.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'name' => $schema->string()->required()->description('The database name, chosen by the user (ask; do not invent it): letters, digits and underscores, up to 64.'),
            'username' => $schema->string()->required()->description('The database user, chosen by the user (ask; you may suggest <name>_user): letters, digits and underscores, 5 to 32.'),
            'connection_preference' => $schema->string()->enum(DatabaseAccess::PREFERENCES)->description('Ask the user; do not choose it yourself. Who may connect: "localhost" (the server itself only, the default), "specific_ip_addresses" (only the IPv4 addresses in hostname) or "everywhere" (any computer: exposes the database to the internet).'),
            'hostname' => $schema->array()->items($schema->string())->description('IPv4 addresses allowed to connect, for example ["203.0.113.5"]. Required when connection_preference is "specific_ip_addresses", and not used otherwise.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        // A single address is accepted as a one-item list.
        if (is_string($request->get('hostname'))) {
            $request->merge(['hostname' => [trim($request->get('hostname'))]]);
        }

        $v = $request->validate($this->idRules(['organization_id', 'server_id']) + [
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{5,32}$/'],
        ] + DatabaseAccess::rules());
        $preference = $v['connection_preference'] ?? 'localhost';
        // Addresses only mean something for specific_ip_addresses; they are dropped otherwise, and the result says so.
        $addresses = DatabaseAccess::addresses($preference, $v['hostname'] ?? null);
        $password = Str::password(24, symbols: false);

        $extra = ['database' => $v['name'], 'username' => $v['username']] + ['allowed_from' => DatabaseAccess::describe($preference, $addresses)] + ($preference === 'localhost' ? ['host' => 'localhost'] : []) + ['password' => $password];

        if ($preference !== 'specific_ip_addresses' && ! empty($v['hostname'])) {
            $extra['ignored_inputs'] = ['hostname'];
        }

        return $this->call($connection, fn (Client $c) => $this->changed("Database {$v['name']} created. The password below is shown only now.", $c->createDatabase($v['organization_id'], $v['server_id'], $v['name'], $v['username'], $password, $preference, $addresses), $extra));
    }
}
