<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RV-02 L2 (owner decision D1 = A) — derive `bookings.escrow_held` for existing rows.
 *
 * WHY A MIGRATION AT ALL
 *
 * `bookings.escrow_held` has existed since RV-40 but NOTHING has ever written it: no settlement
 * path read or cleared it, and `bookings:backfill-money-snapshot` deliberately stamped 0 with
 * the comment "historical; the balance is already settled". After this task every escrow debit
 * becomes a GUARDED decrement (`WHERE escrow_held >= :amount`, abort unless exactly one row
 * changed), so a row left at 0 would make its own first settlement impossible — the guard would
 * abort a settlement that is legitimately owed. Instrumenting the write paths without seeding
 * the existing ones would therefore break exactly the bookings the task is meant to make safe.
 *
 * WHY IT IS DERIVED, NOT GUESSED
 *
 * The source is `wallet_transactions` — the money that actually moved — and never
 * `seats * ride.price_per_seat`. That distinction is the whole RV-40 finding: the price is not a
 * fact, it drifts, and a backfill that re-derives from it "fixes" the schema by committing the
 * same sin. `BackfillBookingMoneySnapshot` already draws this line and names this source.
 *
 *   escrow_held = GREATEST(0, received - released)
 *
 *   received  SUM(amount) of the booking's `escrow_received` rows      (money INTO SyCash)
 *   released  SUM(-amount) of its booking-scoped escrow-OUT rows       (money OUT of SyCash)
 *
 * Cash bookings need no special case and no fabricated amount: they never produced an
 * `escrow_received` row, so their `received` is 0 and their `escrow_held` is 0 — which is
 * exactly the meaning `BackfillBookingMoneySnapshot` already documents ("cash never holds
 * SyCash"). That is why this reads as one derived formula rather than a cash/e-pay branch.
 *
 * WHICH ROWS COUNT AS "RELEASED" — AND WHY ONLY FOUR
 *
 * Six movements take money out of SyCash for a booking, but only four of them write a
 * BOOKING-referenced row:
 *
 *   escrow_release                  (per-passenger completion)      booking:  <- counted
 *   cancellation_processing         (time-based cancel)             booking:  <- counted
 *   passenger_no_show_settlement    (passenger no-show)             booking:  <- counted
 *   driver_no_show_refund           (driver no-show)                booking:  <- counted
 *   driver_cancellation_refunds     (driver cancels)                RIDE:     aggregate only
 *   staff_cancellation_refunds      (staff cancels)                 RIDE:     aggregate only
 *
 * The last two are one SyCash row for the WHOLE ride, so they cannot be attributed to a booking
 * from the row itself. Their sibling per-passenger rows (`driver_cancellation_refund`,
 * `staff_cancellation_refund`) ARE booking-referenced and sum to the same money, which is why the
 * aggregate types are deliberately excluded from `released`: including them would double-count
 * every driver-cancelled and staff-cancelled booking.
 *
 * GREATEST(0, …) CLAMPS RATHER THAN HIDING A DEFECT. If released ever exceeded received the row
 * is money the ledger cannot explain; clamping keeps the column non-negative (it is the guard's
 * precondition) while `ledger:reconcile` remains the place that reports the divergence. Letting
 * a negative value through would instead let `WHERE escrow_held >= :amount` pass for a wrong
 * amount, which is the failure this task exists to remove.
 *
 * IDEMPOTENT AND RE-RUNNABLE. The formula is a pure function of `wallet_transactions`, so running
 * it twice produces the same value; running it later, after more movements, correctly reflects the
 * escrow that is still held. It is guarded to MySQL (the derived-table UPDATE below), matching
 * the RV-24/RV-25 backfill migrations; on any other driver it is a no-op.
 */
return new class extends Migration
{
    /** Money INTO SyCash for a booking. */
    private const IN_TYPE = 'escrow_received';

    /** Money OUT of SyCash, on rows that name the booking. See the class docblock. */
    private const OUT_TYPES = [
        'escrow_release',
        'cancellation_processing',
        'passenger_no_show_settlement',
        'driver_no_show_refund',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $types = "'".self::IN_TYPE."', '".implode("', '", self::OUT_TYPES)."'";

        DB::statement(
            "UPDATE bookings b
             LEFT JOIN (
                 SELECT CAST(SUBSTRING_INDEX(wt.reference, ':', -1) AS UNSIGNED) AS booking_id,
                        SUM(CASE WHEN wt.type = '".self::IN_TYPE."' THEN wt.amount ELSE 0 END) AS received,
                        SUM(CASE WHEN wt.type IN (".$types.") THEN -wt.amount ELSE 0 END) AS released
                 FROM wallet_transactions wt
                 WHERE wt.reference LIKE 'booking:%'
                   AND wt.type IN (".$types.")
                 GROUP BY CAST(SUBSTRING_INDEX(wt.reference, ':', -1) AS UNSIGNED)
             ) t ON t.booking_id = b.id
             SET b.escrow_held = GREATEST(0, ROUND(COALESCE(t.received, 0) - COALESCE(t.released, 0), 2))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Restores the EXACT pre-migration state, not an approximation of it: before this task
        // nothing in the application ever wrote this column, and every writer that did
        // (`BackfillBookingMoneySnapshot`) wrote 0. So "zero it" is the pre-state, and a rollback
        // cannot silently discard real escrow information that the instrumentation needed.
        //
        // Rows whose escrow was already released also land on 0, which is correct twice over: they
        // held nothing, and it is the same value the guarded decrement would have driven them to.
        DB::statement('UPDATE bookings SET escrow_held = 0');
    }
};
