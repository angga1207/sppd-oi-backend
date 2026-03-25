<?php

namespace App\Console\Commands;

use App\Jobs\SyncEmployeesJob;
use Illuminate\Console\Command;

class SyncEmployees extends Command
{
    protected $signature = 'employees:sync
                            {--instance= : Sync specific instance ID only}';

    protected $description = 'Sync employee data from Semesta API into local employees table';

    public function handle(): int
    {
        $instanceId = $this->option('instance') ? (int) $this->option('instance') : null;

        $this->info('Dispatching employee sync job...');

        SyncEmployeesJob::dispatch($instanceId);

        $this->info('Job dispatched. Check queue worker for progress.');

        return 0;
    }
}
