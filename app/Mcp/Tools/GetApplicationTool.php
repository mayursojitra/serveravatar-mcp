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
class GetApplicationTool extends ServerAvatarTool
{
    protected string $name = 'get_application';

    protected string $title = 'Show an application';

    protected string $description = 'Shows one application in detail: domain, PHP and web server settings (memory limit, execution time, upload sizes, process manager), backup and security flags, and whether git deployment is set up. Keys and sensitive settings are never returned.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id'], false);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id'], false));

        return $this->call($connection, fn (Client $c) => $c->application($v['organization_id'], $v['server_id'], $v['application_id']));
    }
}
