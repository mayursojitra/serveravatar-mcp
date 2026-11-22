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
class GetServerUsageTool extends ServerAvatarTool
{
    protected string $name = 'get_server_usage';

    protected string $title = 'Show server resource use';

    protected string $description = 'Shows the live resource use of one server: disk, memory, swap, CPU load, cores, uptime, and whether a restart is required. Values are as ServerAvatar reports them.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id'], false);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id'], false));

        return $this->call($connection, fn (Client $c) => ['usage' => $c->serverUsage($v['organization_id'], $v['server_id'])]);
    }
}
