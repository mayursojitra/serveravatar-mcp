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
class ListCronjobsTool extends ServerAvatarTool
{
    protected string $name = 'list_cronjobs';

    protected string $title = 'List cron jobs';

    protected string $description = 'Lists the cron jobs on one server: name, schedule, command, whether enabled, and the system user. Passwords and tokens inside commands are replaced with [redacted].';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->cronjobs($v['organization_id'], $v['server_id'], $v['page'] ?? 1)));
    }
}
