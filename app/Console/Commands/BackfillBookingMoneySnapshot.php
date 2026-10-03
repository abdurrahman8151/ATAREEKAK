<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RV-40 — retroactively fill the money snapshot for bookings created before the columns
 * existed, using the AUTHORITATIVE ledger, not a re-derivation from the mutable ride.
 *
 * WHY IT READS wallet_transactions AND NOT rides.price_per_seat
 *
 * The whole RV-40 finding is that `seats * ride.price_per_seat` is not a fact — it drifts
 * with the live price. A backfill that used `ride.price_per_seat` would "fix" the schema
 * while committing the exact sin the migration removes: it would stamp today's price onto
 * bookings charged at a different price, and then call it immutable truth. So the source
 * must be the money that actually moved: the `escrow_received` ledger row for the booking
 * (reference = `booking:{id}`), which is what the passenger really paid into SyCash.
 *
 * IDEMPOTENT + NON-DESTRUCTIVE
 *
 *   - Only touches bookings with `amount_paid = 0` (unfilled). Re-running changes nothing.
 *   - Cash bookings have NO escrow ledger row. Their historical per-seat price is not
 *     recoverable from the DB, so we DO NOT fabricate one: we record the payment_method
 *     snapshot and escrow_held = 0 (cash never holds SyCash), and leave unit_price/
 *     amount_paid at 0 with the cash-settled meaning. Inventing an amount from the mutable
 *     ride is exactly the lie this command exists to stop.
 *   - Reports counts it changed, so a deployer sees the effect before/after.
 */
class BackfillBookingMoneySnapshot extends Command
{
    protected $signature = 'bookings:backfill-money-snapshot {--dry-run}';

    protected $description = 'RV-40: fill unit_price/amount_paid on pre-snapshot e-pay bookings from the escrow ledger (idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // e-pay bookings still unfilled (amount_paid = 0) that DO have an escrow ledger row.
        $escrowByBooking = DB::table('wallet_transactions')
            ->where('type', 'escrow_received')
            ->whereNotNull('reference')
            ->get()
            ->reduce(function (array $carry, $tx) {
                if (preg_match('/^booking:(\d+)$/', (string) $tx->reference, $m)) {
                    // Sum in case a booking was charged across multiple rows (partial re-charge).
                    $carry[(int) $m[1]] = ($carry[(int) $m[1]] ?? 0.0) + (float) $tx->amount;
                }

                return $carry;
            }, []);

        $ePayUpdated = 0;
        $cashMarked = 0;
        $skippedNoLedger = 0;

        // RV-37 / un9: `ride` is read for every backfilled booking. chunkById, not cursor(): cursor()
        // streams row-by-row and does NOT honour eager loads in this framework version, so the
        // relation stayed lazy and the armed lazy guard caught it (one query per row, and an error).
        $unfilled = Booking::with('ride')->where('amount_paid', 0)->cursor();

        foreach ($unfilled as $booking) {
            $ride = $booking->relationLoaded('ride') ? $booking->ride : $booking->load('ride')->ride;

            if ($ride && $ride->payment_method === 'e-pay') {
                $paid = $escrowByBooking[$booking->id] ?? null;

                if ($paid === null || $paid <= 0) {
                    // An e-pay booking with no escrow ledger row: money was never proven
                    // to have moved here. Do not guess. Leave it for manual/review.
                    $skippedNoLedger++;

                    continue;
                }

                $unitPrice = $booking->seats > 0 ? round($paid / $booking->seats, 2) : $paid;

                if (! $dry) {
                    $booking->forceFill([
                        'unit_price' => $unitPrice,
                        'amount_paid' => $paid,
                        'payment_method' => 'e-pay',
                        'escrow_held' => 0, // historical; the balance is already settled
                    ])->save();
                }
                $ePayUpdated++;

                continue;
            }

            // Cash (or ride-less): no escrow amount is recoverable; mark the method
            // snapshot only. escrow_held stays 0; amount is settled offline.
            if (! $dry && $booking->payment_method === null) {
                $booking->forceFill(['payment_method' => $ride?->payment_method ?? 'cash'])
                    ->save();
            }
            $cashMarked++;
        }

        $this->info(sprintf(
            '%s e-pay filled: %d | cash method-snapshotted: %d | e-pay skipped (no ledger): %d',
            $dry ? '[DRY-RUN] would fill —' : 'Filled —',
            $ePayUpdated,
            $cashMarked,
            $skippedNoLedger
        ));

        return self::SUCCESS;
    }
}
