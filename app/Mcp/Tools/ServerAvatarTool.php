<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use App\Services\ServerAvatar\Client;
use App\Services\ServerAvatar\Exceptions\AuthenticationFailed;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use App\Services\ServerAvatar\TokenVault;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * The base of every tool that calls ServerAvatar. It turns a ServerAvatar problem into a tool error
 * the model can read and act on (a JSON-RPC error or a crash would give it nothing), notes a rejected
 * token on the token page, and keeps a result from growing without limit.
 *
 * Read tools use the default level (read). A tool that changes something overrides requiredLevel() and
 * must never carry the read-only annotation.
 */
abstract class ServerAvatarTool extends LeveledTool
{
    /** The largest result, in bytes of JSON. A larger one is refused, not cut: cut data would mislead. */
    protected const MAX_RESULT_BYTES = 100_000;

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    /**
     * Run a call against ServerAvatar with this connection's token and return its result as JSON.
     *
     * @param  callable(Client): array<string, mixed>  $call
     */
    protected function call(McpConnection $connection, callable $call): ResponseFactory|Response
    {
        try {
            return $this->respond($call($connection->serverAvatar()));
        } catch (AuthenticationFailed $e) {
            // ServerAvatar no longer accepts the token: show that on the token page too.
            app(TokenVault::class)->markRejected($connection->user, $e->getMessage());

            return Response::error($e->getMessage());
        } catch (ServerAvatarException $e) {
            return Response::error($e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function respond(array $data): ResponseFactory|Response
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || strlen($json) > self::MAX_RESULT_BYTES) {
            return Response::error('The result is too large to return. Ask for something narrower (a single page, one file, fewer lines).');
        }

        return Response::json($data);
    }

    /**
     * The answer to a request that changed something: a plain statement plus whatever ServerAvatar said
     * (already trimmed). The model is told that the request was sent, not that it is certain to have
     * finished, because ServerAvatar carries out some of these in the background.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function changed(string $message, array $response, array $extra = []): array
    {
        return ['ok' => true, 'message' => $message] + $extra + ['serveravatar_response' => $response];
    }

    /**
     * The shape of a page of a list, with a hint for the next call.
     *
     * @param  array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}  $page
     * @return array<string, mixed>
     */
    protected function listing(array $page): array
    {
        return $page + ['next_page' => $page['page'] < $page['last_page'] ? $page['page'] + 1 : null];
    }

    /**
     * Input rules for ids and the page number. Ids must be whole numbers, so nothing else can end up in
     * a request path.
     *
     * @param  list<string>  $ids  names such as organization_id
     * @return array<string, list<string>>
     */
    protected function idRules(array $ids, bool $paged = false): array
    {
        $rules = [];

        foreach ($ids as $id) {
            $rules[$id] = ['required', 'integer', 'min:1'];
        }

        if ($paged) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, Type>
     */
    protected function idSchema(JsonSchema $schema, array $ids, bool $paged = false): array
    {
        $descriptions = [
            'organization_id' => 'The organization id, from list_organizations.',
            'server_id' => 'The server id, from list_servers.',
            'application_id' => 'The application id, from list_applications.',
        ];

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = $schema->integer()->min(1)->required()->description($descriptions[$id] ?? 'An id from an earlier result.');
        }

        if ($paged) {
            $out['page'] = $schema->integer()->min(1)->description('Which page of results (default 1). The result says next_page when there is more.');
        }

        return $out;
    }
}
