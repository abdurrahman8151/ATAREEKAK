<?php

namespace App\Console\Commands;

use App\Services\Ride\NoshowService;
use Illuminate\Console\Command;

/**
 * PLACE IN: app/Console/Commands/ResolveExpiredNoshowReports.php
 *
 * REGISTER IN: app/Console/Kernel.php  (see the schedule block below)
 *
 * This command is called every 15 minutes by the scheduler.
 * It finds no-show reports whose 2-hour dispute window has expired
 * and applies the penalty to whichever party was reported.
 *
 * Worst case: a penalty is delayed up to 15 minutes past the 2-hour mark.
 * That is acceptable for this use case.
 *
 * Run manually for testing:
 *   php artisan noshow:resolve
 */
class ResolveExpiredNoshowReports extends Command
{
    protected $signature   = 'noshow:resolve';
    protected $description = 'Resolve expired no-show reports and apply penalties to the losing party';

    public function handle(NoshowService $noshowService): int
    {
        $this->info('Checking for expired no-show reports...');

        $resolved = $noshowService->resolveExpiredReports();

        if ($resolved === 0) {
            $this->line('No expired reports found.');
        } else {
            $this->info("Resolved {$resolved} expired no-show report(s) and applied penalties.");
        }

        return 0;
    }
}
