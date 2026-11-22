<?php

namespace App\Mcp\Tools;

use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use App\Support\Redactor;
use App\Support\Tail;
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
class GetServerLogsTool extends ServerAvatarTool
{
    private const DEFAULT_LINES = 100;

    private const MAX_LINES = 500;

    /** The most text returned from one log. A system log can be megabytes. */
    private const MAX_LOG_BYTES = 20_000;

    protected string $name = 'get_server_logs';

    protected string $title = 'Read a server log';

    protected string $description = 'Reads a server log in two steps. Call it without "log" to list the log files the server has (system, authentication, web server, PHP and so on). Then call it again with "log" set to one of those file names to read the last lines of that file (100 by default, at most 500). Logs can be very large, so only the end is returned, with the total line count and whether it was cut. Secret-looking values are replaced with [redacted], but logs can still contain sensitive details.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->idSchema($schema, ['organization_id', 'server_id']) + [
            'log' => $schema->string()->description('A log file name exactly as listed by this tool when called without "log". Leave it out to get the list.'),
            'lines' => $schema->integer()->min(1)->max(self::MAX_LINES)->description('How many of the last lines to return (default '.self::DEFAULT_LINES.', at most '.self::MAX_LINES.').'),
        ];
    }

    public function handle(Request $request, McpConnection $connection): ResponseFactory|Response
    {
        $v = $request->validate($this->idRules(['organization_id', 'server_id']) + [
            // Plain file names only. No parent directories and no absolute paths, as a second guard on top of
            // the check that the server itself lists the file.
            'log' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/-]+$/', 'not_regex:/(^\/|\.\.)/'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LINES],
        ]);

        return $this->call($connection, function (Client $c) use ($v): array {
            $files = $c->logFiles($v['organization_id'], $v['server_id']);

            if (empty($v['log'])) {
                return ['log_files' => $files, 'next' => 'Call again with "log" set to one of these file names to read its last lines.'];
            }

            // Only files the server itself lists are asked for: never a path that was typed in.
            if (! array_key_exists($v['log'], $files)) {
                throw new ServerAvatarException('That log file is not available on this server. Available: '.implode(', ', array_keys($files)).'.', 422);
            }

            $log = $c->logOutput($v['organization_id'], $v['server_id'], $v['log']);
            $tail = Tail::lines($log['output'], $v['lines'] ?? self::DEFAULT_LINES, self::MAX_LOG_BYTES);

            return [
                'log' => $v['log'],
                'path' => $log['path'],
                'total_lines' => $tail['total_lines'],
                'returned_lines' => $tail['returned_lines'],
                'truncated' => $tail['truncated'],
                'output' => Redactor::redactText($tail['text']),
            ];
        });
    }
}
