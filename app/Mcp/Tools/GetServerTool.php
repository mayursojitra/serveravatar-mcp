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
class GetServerTool extends ServerAvatarTool
{
    protected string $name = 'get_server';

    protected string $title = 'Show a server';

    protected string $description = 'Shows one server in detail: its record, its setup status checklist, and counts of system users, applications, databases and cron jobs. Get the ids from list_servers first.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id'], false);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id'], false));

        return $this->call($connection, fn (Client $c) => $c->serverDetail($v['organization_id'], $v['server_id']));
    }
}
