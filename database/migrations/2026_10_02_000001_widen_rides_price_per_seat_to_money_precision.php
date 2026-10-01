<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-40 (money schema, remaining bullet) — widen rides.price_per_seat to the
 * established money precision, decimal(15,2).
 *
 * WHY THIS IS NOT AN INVENTED DECISION
 *
 * T3-2 standardised every money column to decimal(15,2) and documented that choice as
 * converging on a decision the codebase had ALREADY made ("15,2 ... is what the ledger
 * uses AND what the newest money column in this schema already adopted — so this
 * converges on a decision the codebase already made rather than inventing a fourth
 * width"). Its MONEY_COLUMNS list simply did not include rides.price_per_seat.
 *
 * Measured on the real schema, every currency column is 15,2:
 *   wallets.balance, wallets.cash_ride_debt, wallet_requests.amount,
 *   wallet_transactions.amount / previous_balance / new_balance,
 *   rides.cash_creation_fee, and RV-40's bookings.unit_price / amount_paid / escrow_held.
 * The only money column left narrow is rides.price_per_seat at decimal(8,2)
 * (max 999,999.99).
 *
 * That outlier is exactly the asymmetry T3-2 removed everywhere else, and RV-40 made it
 * worse: the per-seat price is now snapshotted into bookings.unit_price, which is 15,2 —
 * so the destination can hold four more digits than its own source, and the ride side is
 * the narrow choke point. A price above 999,999.99 fails at the database as a generic 500
 * instead of a validation error. R1 RV-14 names it ("no max -> overflows decimal(8,2)")
 * and R1 RV-40 prescribes the fix verbatim: "rides.price_per_seat -> decimal(15,2)".
 *
 * SCOPE — only the column WIDTH. The application-side maximum is deliberately NOT added
 * here: picking one config bound (CreateRideRequest caps at 100000, create-with-route caps
 * at nothing) is an owner decision, recorded with RV-14. This migration just stops the
 * storage layer from being narrower than every other money column and from failing with an
 * undiagnosable 500.
 *
 * WHY RAW ALTER AND NOT ->change()
 *
 * The first attempt used Blueprint::decimal(...)->change(), the T3-2 idiom, and FAILED on
 * this table: ->change() routes through Doctrine DBAL, which introspects the whole table
 * and aborts with "Unknown database type geometry requested" — rides has two GEOMETRY
 * columns (pickup_location / destination_location). T3-2 only ever touched tables without
 * geometry, so its idiom does not transfer here. Raw ALTER is both the repo's established
 * approach for MySQL-specific column changes (the sibling enum migrations) and free of the
 * DBAL dependency. It also reproduces the current definition exactly: NOT NULL, no default.
 * The failed attempt left NO row in `migrations` (verified), so this retries cleanly.
 *
 * Widening decimal(p,s) to a larger p at the same s is lossless in MySQL; nothing can be
 * truncated. Idempotent via the width probe, and down() is a deliberate no-op — un-widening
 * a column that may already hold a large price would fail or silently clamp real money.
 */
return new class extends Migration
{
    private const TARGET_SCALE = 2;

    private const TARGET_PRECISION = 15;

    public function up(): void
    {
        // T3-6 / RV-18 house rule: raw MySQL-only statement(s) below. Skip cleanly on
        // other drivers instead of fataling a fresh migrate (no-op on MySQL).
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('rides')) {
            return;
        }

        $definition = $this->currentDefinition('rides', 'price_per_seat');

        if ($definition === null) {
            return; // column absent on this install — nothing to reconcile
        }

        [$precision, $scale] = $definition;

        // Never shrink: only widen toward 15,2, and only when needed.
        if ($scale === self::TARGET_SCALE && $precision >= self::TARGET_PRECISION) {
            return;
        }

        DB::statement(
            'ALTER TABLE rides MODIFY COLUMN price_per_seat '
            .'DECIMAL(15,2) NOT NULL'
        );
    }

    public function down(): void
    {
        // Intentionally a no-op, as in T3-2: narrowing a column that may already hold a
        // large price would either fail or silently clamp real money. Widening is
        // lossless; un-widening is not.
    }

    /**
     * @return array{0:int,1:int}|null [precision, scale] or null if undetectable
     */
    private function currentDefinition(string $table, string $column): ?array
    {
        $row = DB::select(
            'SELECT NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )[0] ?? null;

        if ($row === null || $row->NUMERIC_PRECISION === null) {
            return null;
        }

        return [(int) $row->NUMERIC_PRECISION, (int) $row->NUMERIC_SCALE];
    }
};
