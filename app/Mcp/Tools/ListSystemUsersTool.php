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
class ListSystemUsersTool extends ServerAvatarTool
{
    protected string $name = 'list_system_users';

    protected string $title = 'List system users';

    protected string $description = 'Lists the system users ("application users") on one server: id, username, whether SSH access is on, and the applications that run as each. Use it when the user wants a new application to run as an existing system user: show them the list, let them choose, and pass the chosen id as "system_user_id" to create_application. Passwords and keys are never returned.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->systemUsers($v['organization_id'], $v['server_id'], $v['page'] ?? 1)));
    }
}
