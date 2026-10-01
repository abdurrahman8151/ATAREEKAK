<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-30 — index wallet_transactions(reference, type).
 *
 * WHY. `reference` is written on essentially every money row (`booking:{id}`, `ride:{id}`,
 * `wallet:{id}`, `admin_charge:{id}` …) but carried NO index, while every reconciliation read
 * filters on it (`WalletTransaction::where('reference', "booking:{id}")` in the money-path,
 * confirm-completion, cancel-seats and BackfillBookingMoneySnapshot code paths). Those reads
 * were full table scans on a table that only grows. The composite (reference, type) matches the
 * "one reference, many types" lookup shape R1 specified.
 *
 * This is purely additive — an index cannot change query RESULTS, only speed — so it is
 * decision-free and cannot weaken money integrity. Guarded to MySQL (the reconciliation paths are
 * money-critical on the production MySQL engine; other drivers simply keep the existing
 * behaviour). Idempotent: skips if an index on `reference` already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $has = DB::select(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'wallet_transactions'
               AND column_name = 'reference'"
        )[0]->c ?? 0;

        if ((int) $has > 0) {
            return; // already indexed
        }

        DB::statement(
            'ALTER TABLE wallet_transactions ADD INDEX wallet_transactions_reference_type_index (reference, type)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::statement(
                'ALTER TABLE wallet_transactions DROP INDEX wallet_transactions_reference_type_index'
            );
        } catch (Throwable $e) {
            // index may not exist — nothing to undo
        }
    }
};
