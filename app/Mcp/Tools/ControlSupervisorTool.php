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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive]
#[IsOpenWorld]
class ControlSupervisorTool extends ServerAvatarTool
{
    protected string $name = 'control_supervisor';

    protected string $title = 'Start, stop or restart a supervisor process';

    protected string $description = 'Starts, stops or restarts one supervisor process (a background worker, such as a queue worker) of an application. Stopping or restarting interrupts that process. Find supervisor ids with list_supervisors.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Action;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id', 'application_id']) + [
            'supervisor_id' => $schema->integer()->min(1)->required()->description('The supervisor id, from list_supervisors.'),
            'action' => $schema->string()->enum(['start', 'stop', 'restart'])->required()->description('What to do with the process.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id', 'application_id']) + [
            'supervisor_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:start,stop,restart'],
        ]);

        return $this->call($connection, fn (Client $c) => $this->changed("Supervisor {$v['action']} requested.", $c->controlSupervisor($v['organization_id'], $v['server_id'], $v['application_id'], $v['supervisor_id'], $v['action']), ['supervisor_id' => $v['supervisor_id'], 'action' => $v['action']]));
    }
}
