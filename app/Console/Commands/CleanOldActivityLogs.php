<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanOldActivityLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'activity-log:clean
                            {--days= : Number of days to retain (default: 365)}
                            {--force : Run even if auto-delete is disabled}';

    /**
     * The console command description.
     */
    protected $description = 'Delete activity logs older than the configured retention period (default: 1 year)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $enabled = config('app.activity_log_auto_delete', true);
        $force = $this->option('force');

        if (!$enabled && !$force) {
            $this->info('Auto-delete activity logs is disabled. Use --force to run anyway.');
            return 0;
        }

        $days = $this->option('days') ?: config('app.activity_log_retention_days', 365);
        $cutoffDate = Carbon::now()->subDays((int) $days);

        $this->info("Deleting activity logs older than {$days} days (before {$cutoffDate->toDateString()})...");

        $deleted = ActivityLog::where('created_at', '<', $cutoffDate)->delete();

        $this->info("Deleted {$deleted} activity log(s).");

        return 0;
    }
}
