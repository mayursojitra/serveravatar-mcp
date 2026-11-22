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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class AddDomainTool extends ServerAvatarTool
{
    /** A bare host name: labels of letters, digits and hyphens, at least two of them, no port or path. */
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    protected string $name = 'add_domain';

    protected string $title = 'Add a domain to an application';

    protected string $description = 'Adds another domain or subdomain to an application, for example "shop.example.com": a bare host name, with no http://, path or port. The domain\'s DNS has to point at the server for it to work. SSL covers only the domains it was installed for, so run install_ssl again afterwards if the application uses HTTPS.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id']) + [
            'domain' => $schema->string()->required()->description('A host name such as shop.example.com. No http://, no path, no port.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        // Host names are not case sensitive: normalise before checking and sending.
        $request->merge(['domain' => is_string($request->get('domain')) ? strtolower(trim($request->get('domain'))) : $request->get('domain')]);

        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id']) + [
            'domain' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME],
        ]);

        return $this->call($connection, fn (Client $c) => $this->changed("Domain {$v['domain']} added. If the application uses HTTPS, run install_ssl again so the certificate covers it.", $c->addDomain($v['organization_id'], $v['server_id'], $v['application_id'], $v['domain']), ['domain' => $v['domain']]));
    }
}
