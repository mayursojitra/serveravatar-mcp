<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

class PruneActivityLog extends Command
{
    protected $signature = 'activity:prune {--days= : Keep this many days (default from ACTIVITY_LOG_RETENTION_DAYS, 90)}';

    protected $description = 'Delete activity log rows older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('serveravatar.activity_retention_days'));

        if ($days < 1) {
            $this->error('The retention period must be at least 1 day.');

            return self::FAILURE;
        }

        $deleted = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} activity rows older than {$days} days.");

        return self::SUCCESS;
    }
}
