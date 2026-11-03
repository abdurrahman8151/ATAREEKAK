<?php

namespace App\Console\Commands;

use App\Modules\Identity\Contracts\StaffTokenCleanupInterface;
use Illuminate\Console\Command;

class CleanupExpiredStaffTokens extends Command
{
    protected $signature = 'staff-tokens:cleanup';

    protected $description = 'Clean up expired and revoked staff refresh tokens';

    public function __construct(private readonly StaffTokenCleanupInterface $jwtService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Cleaning up expired staff tokens...');
        $deleted = $this->jwtService->cleanupExpiredTokens();
        $this->info("Deleted {$deleted} expired/revoked staff tokens.");

        return 0;
    }
}
