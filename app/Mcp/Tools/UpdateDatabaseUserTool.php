<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\DatabaseAccess;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class UpdateDatabaseUserTool extends ServerAvatarTool
{
    protected string $name = 'update_database_user';

    protected string $title = 'Change who may connect to a database user';

    protected string $description = 'Changes who may connect as an existing database user. BEFORE calling it you MUST ask the user, and wait for the answers, which database and user to change and who may connect from now on (one popup of separate questions if you have a question or choice popup: which database, which user, who may connect; do not ask whether to proceed and do not offer a "cancel" option), and never choose it yourself; call list_database_users first so you can show the current setting. The choices: only the server itself ("localhost"), only certain IPv4 addresses ("specific_ip_addresses", with the addresses in "hostname": the list REPLACES the old one, so include the addresses that must keep access), or any computer on the internet ("everywhere", which exposes the database to the whole internet: offer it as a choice but say that, and recommend one of the other two). Get "database_id" from list_databases and give the database user\'s name. ServerAvatar requires a password on every update, so this RESETS the user\'s password to a new strong one, generated here and returned once in this result: anything that connects with the old password (an application, a script) stops working until it is changed. Warn the user before you call this, and tell them to store the new password now, because it cannot be shown again. Never write it into files, tickets or messages other than to the user.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'database_id' => $schema->integer()->min(1)->required()->description('The database id, from list_databases.'),
            'username' => $schema->string()->required()->description('The name of the database user to change: letters, digits and underscores, 5 to 32.'),
            'connection_preference' => $schema->string()->enum(DatabaseAccess::PREFERENCES)->required()->description('Who may connect from now on: "localhost" (the server itself only), "specific_ip_addresses" (only the IPv4 addresses in hostname) or "everywhere" (any computer: exposes the database to the internet).'),
            'hostname' => $schema->array()->items($schema->string())->description('The complete list of IPv4 addresses allowed to connect, for example ["203.0.113.5", "198.51.100.7"]. It replaces the current list. Required when connection_preference is "specific_ip_addresses", and not used otherwise.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        // A single address is accepted as a one-item list.
        if (is_string($request->get('hostname'))) {
            $request->merge(['hostname' => [trim($request->get('hostname'))]]);
        }

        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'database_id']) + [
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{5,32}$/'],
            'connection_preference' => ['required'] + DatabaseAccess::rules()['connection_preference'],
        ] + array_diff_key(DatabaseAccess::rules(), ['connection_preference' => 1]));

        $preference = $v['connection_preference'];
        $addresses = DatabaseAccess::addresses($preference, $v['hostname'] ?? null);
        $password = Str::password(24, symbols: false);

        $extra = ['database_id' => $v['database_id'], 'username' => $v['username'], 'allowed_from' => DatabaseAccess::describe($preference, $addresses), 'password' => $password];

        if ($preference !== 'specific_ip_addresses' && ! empty($v['hostname'])) {
            $extra['ignored_inputs'] = ['hostname'];
        }

        return $this->call($connection, function (Client $c) use ($v, $preference, $addresses, $password, $extra) {
            $user = $c->findDatabaseUser($v['organization_id'], $v['server_id'], $v['database_id'], $v['username']);

            if (! $user || empty($user['id'])) {
                throw new ServerAvatarException("No database user named {$v['username']} was found on that database. Check database_id (list_databases) and the user name. Nothing was changed.");
            }

            return $this->changed("Database user {$v['username']} updated and its password reset. The new password below is shown only now.", $c->updateDatabaseUser($v['organization_id'], $v['server_id'], $v['database_id'], (int) $user['id'], $v['username'], $password, $preference, $addresses), $extra);
        });
    }
}
