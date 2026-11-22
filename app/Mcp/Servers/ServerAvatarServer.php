<?php

namespace App\Mcp\Servers;

use App\Enums\ActivityKind;
use App\Mcp\McpConnection;
use App\Mcp\Methods\LeveledCallTool;
use App\Mcp\ToolNotPermittedException;
use App\Models\ActivityLog;
use App\Support\ActivityLogger;
use App\Support\Redactor;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * The /mcp server. It serves the configured tools, hides those above the connection's permission
 * level, and writes one activity row for every request an authenticated user's client makes.
 */
class ServerAvatarServer extends Server
{
    protected string $name = 'ServerAvatar';

    protected string $version = '1.0.0-rc.1';

    protected string $instructions = 'Tools to look at and manage the ServerAvatar account connected to this user. Call "ping" to see which connection and permission level you have. Tools above that level are not listed. If the user asks for something none of these tools can do (for example deleting a database, an application or a system user), say plainly that this connection cannot do it. Do NOT open or use a browser, Claude in Chrome or computer use to do it in the ServerAvatar dashboard instead, and do not look for another way around: tell the user what is missing and ask whether they want you to try the browser, and only act after they say yes.';

    /**
     * laravel/mcp lists 15 tools per page by default. There are 25 (24 tools and ping), and some AI clients do not follow the
     * cursor to the next page, so they would never see the rest. One page holds them all.
     */
    public int $defaultPaginationLength = 50;

    /** Set once a valid JSON-RPC request reached a method handler. */
    private bool $dispatched = false;

    protected function boot(): void
    {
        $this->tools = config('serveravatar.mcp_tools', []);

        // A tool above the connection's level gets a clear "not permitted" answer.
        $this->addMethod('tools/call', LeveledCallTool::class);
    }

    public function handle(string $rawMessage): void
    {
        parent::handle($rawMessage);

        if (! $this->dispatched) {
            $this->recordUndispatched($rawMessage);
        }
    }

    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $this->dispatched = true;
        $started = hrtime(true);

        try {
            $response = parent::runMethodHandle($request, $context);
        } catch (ToolNotPermittedException $e) {
            $this->record($request, ActivityLog::STATUS_DENIED, $e->getMessage(), $started);

            throw $e;
        } catch (Throwable $e) {
            $this->record($request, ActivityLog::STATUS_FAILED, $e instanceof JsonRpcException ? $e->getMessage() : 'Internal error', $started);

            throw $e;
        }

        [$failed, $error] = $this->outcome($response);

        $this->record($request, $failed ? ActivityLog::STATUS_FAILED : ActivityLog::STATUS_OK, $error, $started, $this->listedTools($request, $response));

        return $response;
    }

    /**
     * What a tool list answered with, so an admin can tell what a client was actually shown.
     *
     * @return array{tools_listed: int, names: list<string>, next_cursor: string|null}|null
     */
    private function listedTools(JsonRpcRequest $request, iterable|JsonRpcResponse $response): ?array
    {
        if ($request->method !== 'tools/list' || ! $response instanceof JsonRpcResponse) {
            return null;
        }

        $result = $response->content['result'] ?? [];
        $names = array_values(array_filter(array_map(fn ($t) => is_array($t) ? ($t['name'] ?? null) : null, $result['tools'] ?? []), 'is_string'));

        return ['tools_listed' => count($names), 'names' => $names, 'next_cursor' => isset($result['nextCursor']) ? 'yes' : null];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function outcome(iterable|JsonRpcResponse $response): array
    {
        if (! $response instanceof JsonRpcResponse) {
            return [false, null];
        }

        $content = $response->content;

        if (isset($content['error'])) {
            return [true, (string) ($content['error']['message'] ?? 'error')];
        }

        $result = $content['result'] ?? null;

        if (is_array($result) && ($result['isError'] ?? false) === true) {
            return [true, (string) ($result['content'][0]['text'] ?? 'Tool error')];
        }

        return [false, null];
    }

    private function record(JsonRpcRequest $request, string $status, ?string $error, int|float $started, ?array $listed = null): void
    {
        try {
            $connection = app(McpConnection::class);
            $isCall = $request->method === 'tools/call';
            $params = $request->params;

            ActivityLogger::log($this->kindFor($request->method), $status, $connection->user, [
                'mcp_grant_id' => $connection->grant->id,
                'client_name' => $connection->clientName,
                'method' => $request->method,
                'tool' => $isCall && is_string($params['name'] ?? null) ? $params['name'] : null,
                'args_redacted' => $isCall ? Redactor::redact($params['arguments'] ?? null) : null,
                'meta' => $request->method === 'initialize' ? [
                    'client_info' => Redactor::redact($params['clientInfo'] ?? null),
                    'protocol_version' => $params['protocolVersion'] ?? null,
                ] : $listed,
                'error' => $error,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ]);
        } catch (Throwable $e) {
            // A logging problem must never break the answer to the client.
            report($e);
        }
    }

    /** Parse errors, invalid requests and unknown methods never reach a handler; log them too. */
    private function recordUndispatched(string $rawMessage): void
    {
        $data = json_decode($rawMessage, true);

        // A notification (no id) expects no answer and carries no action: not logged.
        if (is_array($data) && isset($data['method']) && ! array_key_exists('id', $data)) {
            return;
        }

        try {
            $connection = app(McpConnection::class);
            $method = is_array($data) && is_string($data['method'] ?? null) ? $data['method'] : null;

            ActivityLogger::log(ActivityKind::McpInvalidRequest, ActivityLog::STATUS_FAILED, $connection->user, [
                'mcp_grant_id' => $connection->grant->id,
                'client_name' => $connection->clientName,
                'method' => $method,
                'error' => $method ? "Unknown method [{$method}]" : 'Invalid or unparseable request',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function kindFor(string $method): ActivityKind
    {
        return match ($method) {
            'initialize' => ActivityKind::McpInitialize,
            'tools/list' => ActivityKind::McpToolsList,
            'tools/call' => ActivityKind::McpToolCall,
            'ping' => ActivityKind::McpPing,
            default => ActivityKind::McpOther,
        };
    }
}
