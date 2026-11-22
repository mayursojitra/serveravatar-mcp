<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive]
#[IsOpenWorld]
class RestartServerTool extends ServerAvatarTool
{
    protected string $name = 'restart_server';

    protected string $title = 'Restart a server';

    protected string $description = 'Restarts a server. Every site and service on it is unavailable until it is back, usually a minute or two. Only use it when the user has asked for a restart, and say which server will be restarted. ServerAvatar carries the restart out in the background: this tool reports that the request was sent, not that the server is back. Use get_server afterwards to check.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Action;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id']));

        return $this->call($connection, fn (Client $c) => $this->changed('Restart requested. The server will be unavailable for a short time.', $c->restartServer($v['organization_id'], $v['server_id']), ['server_id' => $v['server_id']]));
    }
}
