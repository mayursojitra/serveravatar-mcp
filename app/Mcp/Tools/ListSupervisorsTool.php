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
class ListSupervisorsTool extends ServerAvatarTool
{
    protected string $name = 'list_supervisors';

    protected string $title = 'List application supervisor processes';

    protected string $description = 'Lists the supervisor processes (background workers) of one application with their status. Secrets in commands are replaced with [redacted].';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->supervisors($v['organization_id'], $v['server_id'], $v['application_id'], $v['page'] ?? 1)));
    }
}
