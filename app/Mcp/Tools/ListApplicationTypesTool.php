<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\ApplicationTypes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Tells the model which application types create_application can create and what each one needs, so it can
 * ask the user to choose and then collect the right inputs. It touches no ServerAvatar account.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListApplicationTypesTool extends LeveledTool
{
    protected string $name = 'list_application_types';

    protected string $title = 'List application types';

    protected string $description = 'Lists the application types create_application can create (plain PHP, WordPress, Joomla, phpMyAdmin, Akaunting, Nextcloud, Moodle, PrestaShop, Statamic, Craft CMS, Uptime Kuma, n8n, Node-RED, NodeBB). For each one it shows the PHP versions it accepts, the server it needs, what to ask the user for, and what is generated and returned once after creation (passwords, database name). Call it before create_application, show the user the choices and ask which type they want. It changes nothing.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        return Response::json([
            'how_to_use' => 'Ask with your question or choice popup (AskUserQuestion, ask_user_input or the like) when you have one, not as a long typed list: one popup question for the type (use "type_questions", whose options are already at most four per question), one for the system user (new, or an existing one from list_system_users) and one for whether to also add the www. version; only the free-text inputs (domain, name, site title, admin username) are typed. If the first answer points at another PHP or Node.js app ("next"), show the matching "shown_when" question next. Skip any question the user already answered. Ask the user for the inputs listed under ask_the_user_for. Then call create_application with framework set to that type\'s "framework" value. Also ask for their domain and whether the application should run as a new or an existing system user. Do not ask the user for admin passwords or database names: they are generated and returned once after creation. Creating needs the full permission level.',
            'always_needed' => ['organization_id', 'server_id', 'name (letters, digits, hyphens and underscores only)', 'hostname (ask the user: their own domain, DNS pointing at the server)', 'php_version (PHP types only)'],
            'optional_for_every_type' => ['www (also add the www. version)', 'system_user_id (run as an existing system user: call list_system_users and let the user choose; otherwise a new system user is created with a generated username and password)', 'system_user_username and system_user_password (a new system user with the user\'s own name and password)'],
            'not_offered' => 'Mautic and the Git-based applications are not offered.',
            'type_questions' => ApplicationTypes::questionFlow(),
            'types' => ApplicationTypes::describe(),
        ]);
    }
}
