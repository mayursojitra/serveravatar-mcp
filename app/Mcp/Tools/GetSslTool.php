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
class GetSslTool extends ServerAvatarTool
{
    protected string $name = 'get_ssl';

    protected string $title = 'Show application SSL';

    protected string $description = 'Shows the SSL state of one application: whether a certificate is installed, whether HTTPS is forced, and the certificate\'s issuer, domains, issue and expiry dates and days left.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id'], false);
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id'], false));

        return $this->call($connection, fn (Client $c) => ['ssl' => $c->ssl($v['organization_id'], $v['server_id'], $v['application_id'])]);
    }
}
