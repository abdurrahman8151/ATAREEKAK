<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T3-2 — standardise the precision of every money column.
 *
 * The same currency was declared three different ways:
 *   wallets.balance            decimal(10,2)   max  99,999,999.99
 *   wallet_requests.amount     decimal(12,2)   max 999,999,999.99
 *   wallet_transactions.*      decimal(15,2)   max 999,999,999,999.99
 *
 * The ledger already accepted amounts the wallet could not store, so a balance
 * could overflow its own column before the ledger would accept the debit — an
 * error surfacing as a generic 500 at an unpredictable threshold rather than a
 * domain rule. 15,2 is the chosen standard because it is what the ledger uses
 * AND what the newest money column in this schema (wallets.cash_ride_debt,
 * 2026_07_24_100002) already adopted — so this converges on a decision the
 * codebase already made rather than inventing a fourth width.
 *
 * Widening decimal(p,s) to a larger p with identical s is lossless in MySQL and
 * never truncates stored values. It is also the safe direction: no data can be
 * discarded by this migration. The guard below makes it a no-op on SQLite (where
 * Laravel stores decimals as INTEGER/NUMERIC affinity anyway) and on a fresh
 * install where the columns are already 15,2.
 */
return new class extends Migration
{
    private const TARGET_SCALE = 2;
    private const TARGET_PRECISION = 15;

    /** table => columns that hold money */
    private const MONEY_COLUMNS = [
        'wallets' => ['balance'],
        'wallet_requests' => ['amount'],
        // wallet_transactions is already 15,2; included so a drifted copy is
        // reconciled rather than silently left behind.
        'wallet_transactions' => ['amount', 'previous_balance', 'new_balance'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // widening is a MySQL-specific repair; SQLite ignores precision
        }

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $column) {
                    $definition = $this->currentDefinition($table, $column);

                    if ($definition === null) {
                        continue; // column absent on this install — nothing to reconcile
                    }

                    [$precision, $scale] = $definition;

                    // Never shrink: only widen toward 15,2, and only when needed.
                    if ($scale === self::TARGET_SCALE && $precision >= self::TARGET_PRECISION) {
                        continue;
                    }

                    $t->decimal($column, self::TARGET_PRECISION, self::TARGET_SCALE)
                        ->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Reverting to a narrower precision on a database
        // that has already stored a large value would either fail or silently
        // clamp real money. Widening is lossless; un-widening is not.
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
