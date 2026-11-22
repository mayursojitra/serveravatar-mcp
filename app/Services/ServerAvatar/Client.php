<?php

namespace App\Services\ServerAvatar;

use App\Services\ServerAvatar\Exceptions\ConnectionFailed;
use App\Services\ServerAvatar\Exceptions\RequestTimedOut;
use App\Services\ServerAvatar\Exceptions\ServerAvatarException;
use App\Services\ServerAvatar\Exceptions\ServerError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A client for the ServerAvatar API, built for one user's token and used for one piece of work.
 * It is created when a call is made and never kept, so the decrypted token lives only as long as
 * the call (FR-5). It sends the token as `Authorization: Bearer <token>` (a bare token is refused).
 *
 * Retries (FR-15), kept short because they happen inside an MCP request:
 *  - 429: wait as long as `Retry-After` says (at most a few seconds) and try again. The request was
 *    not processed, so this is safe for any request.
 *  - 5xx and network errors: retried only for requests that only read.
 */
final class Client
{
    private const MAX_RETRY_AFTER_SECONDS = 5;

    public function __construct(
        private readonly string $token,
        private readonly ?string $baseUrl = null,
    ) {}

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query], reads: true);
    }

    /**
     * A GET that changes something (ServerAvatar restarts a server with a GET). It is never repeated after
     * a server error or a dropped connection, because the first request may already have been carried out.
     * Only a 429, which means the request was not processed, is retried.
     *
     * @return array<string, mixed>
     */
    public function act(string $path): array
    {
        return $this->send('GET', $path, ['query' => []], reads: false);
    }

    /**
     * A POST that only reads (for example fetching a log file), so it is safe to repeat.
     *
     * @return array<string, mixed>
     */
    public function postRead(string $path, array $body = []): array
    {
        return $this->send('POST', $path, ['json' => $body], reads: true);
    }

    /**
     * A request that changes something. It is not repeated after an error that may have been processed.
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?int $timeout = null): array
    {
        return $this->send('POST', $path, ['json' => $body], reads: false, timeout: $timeout);
    }

    /**
     * A PATCH that changes something. Not repeated after an error that may have been processed.
     *
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->send('PATCH', $path, ['json' => $body], reads: false);
    }

    /**
     * One page of a list. ServerAvatar paginates only when asked (`pagination=1`), and wraps the page
     * under a key named after the resource. Both shapes are handled.
     *
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function page(string $path, string $key, int $page = 1, array $query = []): array
    {
        $data = $this->get($path, ['pagination' => 1, 'page' => max(1, $page)] + $query);
        $block = $data[$key] ?? null;

        if (is_array($block) && array_key_exists('data', $block)) {
            $items = array_values(is_array($block['data']) ? $block['data'] : []);

            return [
                'items' => $items,
                'page' => (int) ($block['current_page'] ?? $page),
                'last_page' => max(1, (int) ($block['last_page'] ?? 1)),
                'total' => (int) ($block['total'] ?? count($items)),
            ];
        }

        $items = is_array($block) ? array_values($block) : [];

        return ['items' => $items, 'page' => 1, 'last_page' => 1, 'total' => count($items)];
    }

    /**
     * The organizations the token can see: id and name only. Members (names, emails) are never kept.
     *
     * @return list<array{id: int, name: string}>
     */
    public function organizations(): array
    {
        $data = $this->get('/organizations');

        return array_values(array_map(Trim::organization(...), is_array($data['organizations'] ?? null) ? $data['organizations'] : []));
    }

    /**
     * One page of an organization's servers, trimmed to what is useful (no admin-tool links or slugs).
     *
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function servers(int $organizationId, int $page = 1): array
    {
        $result = $this->page("/organizations/{$organizationId}/servers", 'servers', $page);
        $result['items'] = array_map(Trim::server(...), $result['items']);

        return $result;
    }

    /**
     * One server in full: the record, plus its setup status and its counts. The status and the counts
     * are extras: if either is refused (a role may not allow it), the server is still returned and the
     * gap is noted.
     *
     * @return array<string, mixed>
     */
    public function serverDetail(int $organizationId, int $serverId): array
    {
        $base = "/organizations/{$organizationId}/servers/{$serverId}";
        $detail = ['server' => Trim::server($this->get($base)['server'] ?? []), 'notes' => []];

        foreach (['status' => 'setup_status', 'summary' => 'counts'] as $path => $label) {
            try {
                $data = $this->get("{$base}/{$path}");
                $detail[$label] = $path === 'status' ? Trim::status($data) : Trim::only($data['summary'] ?? [], ['system_users', 'applications', 'databases', 'cronjobs']);
            } catch (ServerAvatarException $e) {
                $detail['notes'][] = "{$label} is not available: ".$e->getMessage();
            }
        }

        return $detail;
    }

    /** @return array<string, mixed> */
    public function serverUsage(int $organizationId, int $serverId): array
    {
        return Trim::usage($this->get("/organizations/{$organizationId}/servers/{$serverId}/usage"));
    }

    /**
     * The log files a server has: file name => description.
     *
     * @return array<string, string>
     */
    public function logFiles(int $organizationId, int $serverId): array
    {
        $files = $this->get("/organizations/{$organizationId}/servers/{$serverId}/logs")['logFiles'] ?? [];

        return array_map('strval', array_filter(is_array($files) ? $files : [], fn ($label, $file) => is_string($file) && is_scalar($label), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The whole text of one log file. A log can be megabytes: callers take the tail.
     *
     * @return array{output: string, path: string}
     */
    public function logOutput(int $organizationId, int $serverId, string $file): array
    {
        $data = $this->postRead("/organizations/{$organizationId}/servers/{$serverId}/logs", ['log' => $file]);

        return ['output' => (string) ($data['output'] ?? ''), 'path' => (string) ($data['path'] ?? $file)];
    }

    /** @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int} */
    public function applications(int $organizationId, int $serverId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/applications", 'applications', $page, Trim::application(...));
    }

    /** @return array<string, mixed> */
    public function application(int $organizationId, int $serverId, int $applicationId): array
    {
        $data = $this->get("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}");

        return [
            'application' => Trim::applicationDetail($data['application'] ?? []),
            // The deployment record may hold credentials, and its shape has not been seen: report only that it exists.
            'has_git_deployment' => ! empty($data['gitDeployment']),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int} */
    public function domains(int $organizationId, int $serverId, int $applicationId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/application-domains", 'applicationDomains', $page, Trim::domain(...));
    }

    /** @return array<string, mixed> */
    public function ssl(int $organizationId, int $serverId, int $applicationId): array
    {
        return Trim::ssl($this->get("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/ssl"));
    }

    /** @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int} */
    public function databases(int $organizationId, int $serverId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/databases", 'databases', $page, Trim::database(...));
    }

    /**
     * The system users ("application users") of a server, so a new application can run as an existing one.
     *
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function systemUsers(int $organizationId, int $serverId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/system-users", 'systemUsers', $page, Trim::systemUser(...));
    }

    /** @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int} */
    public function cronjobs(int $organizationId, int $serverId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/cronjobs", 'cronjobs', $page, Trim::cronjob(...));
    }

    /** @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int} */
    public function supervisors(int $organizationId, int $serverId, int $applicationId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/supervisors", 'supervisors', $page, Trim::loose(...));
    }

    /**
     * Backups of an organization. The response key and the item shape have not been seen (the role used
     * for testing is refused), so the page is found under whichever key holds it.
     *
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function backups(int $organizationId, int $page = 1): array
    {
        $data = $this->get("/organizations/{$organizationId}/backups", ['pagination' => 1, 'page' => max(1, $page)]);

        foreach ($data as $block) {
            if (is_array($block) && array_key_exists('data', $block) && is_array($block['data'])) {
                return $this->paged($block, $page, Trim::loose(...));
            }
        }

        $list = array_values(array_filter($data, 'is_array'));
        $items = isset($list[0]) && array_is_list($list[0]) ? $list[0] : [];

        return ['items' => array_map(fn ($i) => is_array($i) ? Trim::loose($i) : [], $items), 'page' => 1, 'last_page' => 1, 'total' => count($items)];
    }

    // ------------------------------------------------------------ changes (nothing here is repeated after an error)

    /**
     * Restart the server. ServerAvatar answers a message; its shape has not been seen, so it is trimmed loosely.
     *
     * @return array<string, mixed>
     */
    public function restartServer(int $organizationId, int $serverId): array
    {
        return Trim::loose($this->act("/organizations/{$organizationId}/servers/{$serverId}/restart"));
    }

    /**
     * Start, stop or restart a supervisor process of an application.
     *
     * @param  'start'|'stop'|'restart'  $action
     * @return array<string, mixed>
     */
    public function controlSupervisor(int $organizationId, int $serverId, int $applicationId, int $supervisorId, string $action): array
    {
        // The action becomes part of the URL path, so only the three known words may ever get there.
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unknown supervisor action.');
        }

        return Trim::loose($this->act("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/supervisors/{$supervisorId}/{$action}"));
    }

    /** @return array<string, mixed> */
    public function addDomain(int $organizationId, int $serverId, int $applicationId, string $domain): array
    {
        return Trim::loose($this->post("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/application-domains", ['domain' => $domain]));
    }

    /**
     * Install a Let's Encrypt certificate. Custom certificates are not offered: they would send a private
     * key through the model.
     *
     * @return array<string, mixed>
     */
    public function installAutomaticSsl(int $organizationId, int $serverId, int $applicationId, bool $forceHttps): array
    {
        return Trim::loose($this->post("/organizations/{$organizationId}/servers/{$serverId}/applications/{$applicationId}/ssl", [
            'ssl_type' => 'automatic',
            'force_https' => $forceHttps,
        ]));
    }

    /**
     * Create a database. `connection_preference` (localhost, everywhere or specific_ip_addresses) is documented as
     * optional, but leaving it out made the live API answer HTTP 500 (checked on a test server), so it is
     * always sent. `hostname` (a list of IPv4 addresses) is sent only for specific_ip_addresses; ServerAvatar
     * documents it as an array, and that form has not been run live.
     *
     * @param  list<string>  $hostnames
     * @return array<string, mixed>
     */
    public function createDatabase(int $organizationId, int $serverId, string $name, string $username, string $password, string $connectionPreference = 'localhost', array $hostnames = []): array
    {
        return Trim::loose($this->post("/organizations/{$organizationId}/servers/{$serverId}/databases", [
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'connection_preference' => $connectionPreference,
        ] + ($connectionPreference === 'specific_ip_addresses' ? ['hostname' => $hostnames] : [])));
    }

    /**
     * The users of one database: who may connect to it. The password is never kept. ServerAvatar lists 10 per page.
     *
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function databaseUsers(int $organizationId, int $serverId, int $databaseId, int $page = 1): array
    {
        return $this->trimmedPage("/organizations/{$organizationId}/servers/{$serverId}/databases/{$databaseId}/database-users", 'databaseUsers', $page, Trim::databaseUser(...));
    }

    /**
     * One database user, found by name on any page (ServerAvatar lists 10 per page), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findDatabaseUser(int $organizationId, int $serverId, int $databaseId, string $username): ?array
    {
        $page = 1;

        do {
            $result = $this->databaseUsers($organizationId, $serverId, $databaseId, $page);

            foreach ($result['items'] as $user) {
                if (($user['username'] ?? null) === $username) {
                    return $user;
                }
            }
        } while (++$page <= min($result['last_page'], 50));

        return null;
    }

    /**
     * Change who may connect as a database user. ServerAvatar documents username and password as required
     * on update (leaving it out answers 422, checked live), so a new password is always sent. `hostname`
     * (IPv4 addresses) only for specific_ip_addresses.
     *
     * @param  list<string>  $hostnames
     * @return array<string, mixed>
     */
    public function updateDatabaseUser(int $organizationId, int $serverId, int $databaseId, int $userId, string $username, string $password, string $connectionPreference, array $hostnames = []): array
    {
        return Trim::loose($this->patch("/organizations/{$organizationId}/servers/{$serverId}/databases/{$databaseId}/database-users/{$userId}", [
            'username' => $username,
            'password' => $password,
            'connection_preference' => $connectionPreference,
        ] + ($connectionPreference === 'specific_ip_addresses' ? ['hostname' => $hostnames] : [])));
    }

    /**
     * Create an application. Not repeated after an error that may have been processed.
     *
     * @param  array<string, mixed>  $body  from ApplicationTypes::body()
     * @return array<string, mixed>
     */
    public function createApplication(int $organizationId, int $serverId, array $body): array
    {
        // Installing an application (Statamic, for one) can take longer than the usual timeout, and the answer carries nothing we can recover.
        return Trim::loose($this->post("/organizations/{$organizationId}/servers/{$serverId}/applications", $body, (int) config('serveravatar.api.slow_timeout')));
    }

    /** @return array<string, mixed> */
    public function createCronjob(int $organizationId, int $serverId, string $name, string $command, string $schedule, string $systemUser): array
    {
        return Trim::loose($this->post("/organizations/{$organizationId}/servers/{$serverId}/cronjobs", [
            'name' => $name,
            'command' => $command,
            'schedule' => $schedule,
            'system_user' => $systemUser,
        ]));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $trim
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    private function trimmedPage(string $path, string $key, int $page, callable $trim): array
    {
        $result = $this->page($path, $key, $page);
        $result['items'] = array_map(fn ($item) => is_array($item) ? $trim($item) : [], $result['items']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  callable(array<string, mixed>): array<string, mixed>  $trim
     * @return array{items: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    private function paged(array $block, int $page, callable $trim): array
    {
        $items = array_values($block['data']);

        return [
            'items' => array_map(fn ($i) => is_array($i) ? $trim($i) : [], $items),
            'page' => (int) ($block['current_page'] ?? $page),
            'last_page' => max(1, (int) ($block['last_page'] ?? 1)),
            'total' => (int) ($block['total'] ?? count($items)),
        ];
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $options, bool $reads, ?int $timeout = null): array
    {
        $maxRetries = max(0, (int) config('serveravatar.api.max_retries'));

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $attempt < $maxRetries;

            try {
                $response = $this->request($timeout)->send($method, $path, $options);
            } catch (ConnectionException $e) {
                if ($reads && $canRetry) {
                    Sleep::for(200 * ($attempt + 1))->milliseconds();

                    continue;
                }

                // A change that timed out may have been made anyway (an application install can outlast the timeout).
                if (! $reads && str_contains($e->getMessage(), 'timed out')) {
                    throw new RequestTimedOut('ServerAvatar did not answer in time. The change may still have been made: check with the list tools before trying again.');
                }

                throw new ConnectionFailed('Could not reach ServerAvatar. Try again shortly.');
            }

            $status = $response->status();

            if ($status === 429 && $canRetry) {
                Sleep::for(min(max(1, $this->retryAfter($response)), self::MAX_RETRY_AFTER_SECONDS))->seconds();

                continue;
            }

            if ($status >= 500 && $reads && $canRetry) {
                Sleep::for(200 * ($attempt + 1))->milliseconds();

                continue;
            }

            if ($response->successful()) {
                $json = $response->json();

                if (! is_array($json)) {
                    throw new ServerError('ServerAvatar sent a response that could not be read.', $status);
                }

                return $json;
            }

            throw ServerAvatarException::fromResponse($response);
        }
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : 1;
    }

    private function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl ?? (string) config('serveravatar.api.base_url'))
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout((int) config('serveravatar.api.connect_timeout'))
            ->timeout($timeout ?? (int) config('serveravatar.api.timeout'));
    }
}
