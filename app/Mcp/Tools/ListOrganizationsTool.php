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
class ListOrganizationsTool extends ServerAvatarTool
{
    protected string $name = 'list_organizations';

    protected string $title = 'List organizations';

    protected string $description = 'Lists the ServerAvatar organizations this account can see: id and name only. Start here: the organization_id it returns is needed by every other tool.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {

        return $this->call($connection, fn (Client $c) => ['organizations' => $c->organizations()]);
    }
}
