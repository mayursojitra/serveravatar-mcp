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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld]
class InstallSslTool extends ServerAvatarTool
{
    protected string $name = 'install_ssl';

    protected string $title = 'Install a free SSL certificate';

    protected string $description = 'Installs (or renews) a free Let\'s Encrypt certificate on an application, covering all of its domains, and optionally forces HTTPS. The domains\' DNS must already point at the server, or issuing fails. Run it again after add_domain. Uploading a custom certificate is not supported here.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id']) + [
            'force_https' => $schema->boolean()->required()->description('true to redirect all HTTP traffic to HTTPS.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id']) + [
            'force_https' => ['required', 'boolean'],
        ]);

        return $this->call($connection, fn (Client $c) => $this->changed('SSL install requested. Check progress with get_ssl.', $c->installAutomaticSsl($v['organization_id'], $v['server_id'], $v['application_id'], (bool) $v['force_https']), ['force_https' => (bool) $v['force_https']]));
    }
}
