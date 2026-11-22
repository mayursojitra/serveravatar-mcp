<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Stand-in tool for milestone M2: proves that the connection works end to end and shows who it is
 * and what level it has. It touches no ServerAvatar account and returns nothing secret.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class PingTool extends LeveledTool
{
    protected string $name = 'ping';

    protected string $title = 'Check the connection';

    protected string $description = 'Checks that this connection works. Returns which user and AI client it belongs to and its permission level (read, action or full). Use it first if other tools fail.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        return Response::json([
            'ok' => true,
            'user' => $connection->user->email,
            'client' => $connection->clientName,
            'level' => $connection->level()->value,
        ]);
    }
}
