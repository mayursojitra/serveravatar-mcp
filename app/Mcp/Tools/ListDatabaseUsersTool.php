<?php

namespace App\Mcp\Tools;

use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class ListDatabaseUsersTool extends ServerAvatarTool
{
    protected string $name = 'list_database_users';

    protected string $title = 'List the users of a database';

    protected string $description = 'Lists the users of one database and who may connect as each: the user name, whether the connection is limited to the server itself ("localhost"), to certain IPv4 addresses ("specific_ip_addresses", with the addresses in "hostname") or open to everyone ("everywhere"), and whether remote access is on. Get "database_id" from list_databases. Passwords are never returned (and cannot be read back). Use it before update_database_user to see the current addresses.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'database_id' => $schema->integer()->min(1)->required()->description('The database id, from list_databases.'),
            'page' => $schema->integer()->min(1)->description('Which page of results (default 1). The result says next_page when there is more.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'database_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->databaseUsers($v['organization_id'], $v['server_id'], $v['database_id'], $v['page'] ?? 1)));
    }
}
