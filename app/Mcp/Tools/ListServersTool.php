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
class ListServersTool extends ServerAvatarTool
{
    protected string $name = 'list_servers';

    protected string $title = 'List servers';

    protected string $description = 'Lists the servers of one organization, one page at a time: id, name, IP address, operating system, web server, database, PHP versions, health and number of applications. The server id it returns is needed to look at a server\'s applications, databases, cron jobs and logs.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->servers($v['organization_id'], $v['page'] ?? 1)));
    }
}
