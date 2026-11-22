<?php

namespace App\Mcp\Methods;

use App\Mcp\McpConnection;
use App\Mcp\ToolNotPermittedException;
use App\Mcp\Tools\LeveledTool;
use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/call with a clear refusal. Without this, a tool hidden by the level would answer "not
 * found"; here a known tool above the connection's level says so, and the refusal can be logged.
 */
class LeveledCallTool extends CallTool
{
    public function __construct(private readonly McpConnection $connection) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->params['name'] ?? null;

        if (is_string($name)) {
            $visible = $context->tools()->contains(fn ($tool): bool => $tool->name() === $name);

            if (! $visible) {
                foreach (config('serveravatar.mcp_tools', []) as $class) {
                    $tool = app($class);

                    if ($tool instanceof LeveledTool && $tool->name() === $name) {
                        throw new ToolNotPermittedException(
                            "Tool [{$name}] is not permitted: it needs the \"{$tool->requiredLevel()->value}\" level and this connection has \"{$this->connection->level()->value}\".",
                            -32602,
                            $request->id,
                        );
                    }
                }
            }
        }

        return parent::handle($request, $context);
    }
}
