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
class ListApplicationsTool extends ServerAvatarTool
{
    protected string $name = 'list_applications';

    protected string $title = 'List applications';

    protected string $description = 'Lists the applications on one server: id, name, framework, primary domain, PHP version, SSL status and temporary domain. The application id is needed for get_application, list_domains, get_ssl and list_supervisors.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->applications($v['organization_id'], $v['server_id'], $v['page'] ?? 1)));
    }
}
