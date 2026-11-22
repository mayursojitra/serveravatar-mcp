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
class ListDomainsTool extends ServerAvatarTool
{
    protected string $name = 'list_domains';

    protected string $title = 'List application domains';

    protected string $description = 'Lists the domains attached to one application, with DNS propagation, auto SSL, and whether each is the temporary domain.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id'], true);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id'], true));

        return $this->call($connection, fn (Client $c) => $this->listing($c->domains($v['organization_id'], $v['server_id'], $v['application_id'], $v['page'] ?? 1)));
    }
}
