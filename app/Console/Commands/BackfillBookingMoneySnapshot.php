<?php

namespace App\Console\Commands;

use App\Modules\Money\Contracts\LedgerAggregates;
use App\Modules\Ride\Contracts\RideMaintenance;
use Illuminate\Console\Command;

/**
 * RV-40: fill `unit_price`/`amount_paid` on pre-snapshot e-pay bookings from the escrow ledger (idempotent).
 *
 * Stage 2 C2: this command used to read `bookings` (with its ride) and write the snapshot columns itself, and to
 * query `wallet_transactions` directly - a production entrypoint naming Ride's model and Money's table. Both
 * halves now come from their owning module: the escrow totals from `LedgerAggregates::escrowTotalsByBooking()`
 * and everery row access from `RideMaintenance`. The command keeps what a command owns: the dry-run flag, the
 * counts, the summary line.
 */
class BackfillBookingMoneySnapshot extends Command
{
    protected $signature = 'bookings:backfill-money-snapshot {--dry-run}';

    protected $description = 'RV-40: fill unit_price/amount_paid on pre-snapshot e-pay bookings from the escrow ledger (idempotent)';

    public function handle(LedgerAggregates $ledger, RideMaintenance $bookings): int
    {
        $dry = (bool) $this->option('dry-run');

        // e-pay bookings still unfilled (amount_paid = 0) that DO have an escrow ledger row.
        $escrowByBooking = $ledger->escrowTotalsByBooking();

        $ePayUpdated = 0;
        $cashMarked = 0;
        $skippedNoLedger = 0;

        foreach ($bookings->unfilledSnapshotBookingIds() as $bookingId) {
            $outcome = $bookings->backfillSnapshot($bookingId, $escrowByBooking[$bookingId] ?? null, $dry);

            if ($outcome === 'e-pay') {
                $ePayUpdated++;
            } elseif ($outcome === 'cash') {
                $cashMarked++;
            } else {
                $skippedNoLedger++;
            }
        }

        $this->reportSummary($dry, $ePayUpdated, $cashMarked, $skippedNoLedger);

        return self::SUCCESS;
    }

    private function reportSummary(bool $dry, int $ePayUpdated, int $cashMarked, int $skippedNoLedger): void
    {
        $this->info(sprintf(
            '%s e-pay filled: %d | cash method-snapshotted: %d | e-pay skipped (no ledger): %d',
            $dry ? '[DRY-RUN] would fill —' : 'Filled —',
            $ePayUpdated,
            $cashMarked,
            $skippedNoLedger
        ));
    }
}
