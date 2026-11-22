<?php

namespace App\Console\Commands;

use App\Models\McpGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Client registration is open (claude.ai registers itself), so clients that never completed a
 * connection would pile up. A client with no grant has no live tokens (tokens only exist after an
 * approval, which creates the grant), so removing it, and anything left over for it, is safe.
 */
class PruneOAuthClients extends Command
{
    protected $signature = 'oauth:prune-clients {--hours=24 : Only clients older than this many hours}';

    protected $description = 'Delete OAuth clients that never became a connection';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $stale = Passport::client()->newQuery()
            ->where('created_at', '<', now()->subHours($hours))
            ->whereNotIn('id', McpGrant::query()->select('oauth_client_id'))
            ->pluck('id');

        if ($stale->isEmpty()) {
            $this->info('No stale OAuth clients.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($stale): void {
            $tokenIds = Passport::token()->newQuery()->whereIn('client_id', $stale)->pluck('id');

            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->delete();
            Passport::token()->newQuery()->whereIn('id', $tokenIds)->delete();
            Passport::authCode()->newQuery()->whereIn('client_id', $stale)->delete();
            Passport::client()->newQuery()->whereIn('id', $stale)->delete();
        });

        $this->info("Deleted {$stale->count()} stale OAuth clients.");

        return self::SUCCESS;
    }
}
