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
class CreateCronjobTool extends ServerAvatarTool
{
    private const SCHEDULES = ['everyMinute', 'every5Minutes', 'every10Minutes', 'every15Minutes', 'every30Minutes', 'everyHour', 'every3Hours', 'every6Hours', 'every12Hours', 'everyDay', 'everyWeek', 'everyMonth'];

    protected string $name = 'create_cronjob';

    protected string $title = 'Create a cron job';

    protected string $description = 'Creates a cron job on a server. The command runs on the server, as the given system user, on the schedule you pick, so it can do anything that user can. Only create a job the user asked for, with the command they asked for. The system user is an application\'s system user (see get_application or list_cronjobs). Only the preset schedules are available.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'name' => $schema->string()->required()->description('A short name: letters, digits, spaces, hyphens and underscores.'),
            'command' => $schema->string()->required()->description('The one-line command to run, up to 1000 characters.'),
            'schedule' => $schema->string()->enum(self::SCHEDULES)->required()->description('How often to run it.'),
            'system_user' => $schema->string()->required()->description('The system user that runs the command.'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id']) + [
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9 _-]{1,60}$/'],
            // One line only: a newline would let a second command ride along in the crontab entry.
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n\0]/'],
            'schedule' => ['required', 'string', 'in:'.implode(',', self::SCHEDULES)],
            'system_user' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
        ]);

        return $this->call($connection, fn (Client $c) => $this->changed("Cron job {$v['name']} creation requested.", $c->createCronjob($v['organization_id'], $v['server_id'], $v['name'], $v['command'], $v['schedule'], $v['system_user']), ['name' => $v['name'], 'schedule' => $v['schedule'], 'system_user' => $v['system_user']]));
    }
}
