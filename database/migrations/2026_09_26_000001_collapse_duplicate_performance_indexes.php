<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T3-5 — collapse the duplicated performance indexes.
 *
 * 2026_08_14_144934 created rides_driver_status_index /
 * bookings_user_status_index / bookings_ride_status_index.
 * 2026_08_21_000028 later created the SAME column sets under the names
 * rides_driver_status / bookings_user_status / bookings_ride_status, because
 * its existence guard checked only its OWN names — which the earlier migration
 * had never created. Verified on MySQL 8.2.0 after a full migrate: SHOW INDEX
 * reports two indexes for (driver_id,status) on rides and for
 * (user_id,status) and (ride_id,status) on bookings.
 *
 * Duplicate indexes add write amplification and storage on the hottest tables
 * with no planner benefit — MySQL may pick either, but every INSERT/UPDATE must
 * maintain both.
 *
 * This migration drops the older *_index variants (keeping the newer, shorter
 * names that the codebase's other tooling now expects) when an equivalent
 * remains. The existence checks go through Schema::getIndexes(), which is
 * driver-agnostic — unlike the raw SHOW INDEX the previous migration used,
 * which made that guard unrunnable on SQLite (also part of T3-5).
 */
return new class extends Migration
{
    /**
     * table => [redundant index to drop => index that supersedes it]
     */
    private const REDUNDANT = [
        'rides' => [
            'rides_driver_status_index' => 'rides_driver_status',
        ],
        'bookings' => [
            'bookings_user_status_index' => 'bookings_user_status',
            'bookings_ride_status_index' => 'bookings_ride_status',
        ],
        // Discovered live during T3-6 verification: information_schema shows both
        // wallet_transactions_transaction_id_unique and ..._index on the same
        // single column. A UNIQUE index already serves equality lookups, so the
        // plain one is pure write amplification on the ledger. Dropping the plain
        // copy only when the unique one is present keeps the constraint intact.
        // The same sweep also found (wallet_id,created_at) twice — the create-
        // table migration's auto-named index vs the 2026_08_21 explicit one;
        // the newer explicit name is kept, matching this file's policy above.
        'wallet_transactions' => [
            'wallet_transactions_transaction_id_index' => 'wallet_transactions_transaction_id_unique',
            'wallet_transactions_wallet_id_created_at_index' => 'wallet_tx_wallet_created',
        ],
    ];

    private function hasIndex(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return in_array(
            $name,
            array_map(
                static fn (array $i): string => (string) ($i['name'] ?? ''),
                Schema::getIndexes($table)
            ),
            true
        );
    }

    public function up(): void
    {
        foreach (self::REDUNDANT as $table => $pairs) {
            foreach ($pairs as $drop => $keep) {
                // Only drop the duplicate when the surviving index is actually
                // there to replace it — never leave the table with zero coverage.
                if ($this->hasIndex($table, $drop) && $this->hasIndex($table, $keep)) {
                    Schema::table($table, function (Blueprint $t) use ($drop) {
                        $t->dropIndex($drop);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Re-creating the dropped indexes is safe and makes the rollback whole;
        // guarded so a fresh database that never had them cannot error.
        foreach (self::REDUNDANT as $table => $pairs) {
            foreach ($pairs as $drop => $keep) {
                if ($this->hasIndex($table, $keep) && ! $this->hasIndex($table, $drop)) {
                    $columns = match ($drop) {
                        'rides_driver_status_index' => ['driver_id', 'status'],
                        'bookings_user_status_index' => ['user_id', 'status'],
                        'bookings_ride_status_index' => ['ride_id', 'status'],
                        'wallet_transactions_transaction_id_index' => ['transaction_id'],
                        'wallet_transactions_wallet_id_created_at_index' => ['wallet_id', 'created_at'],
                    };

                    Schema::table($table, function (Blueprint $t) use ($columns, $drop) {
                        $t->index($columns, $drop);
                    });
                }
            }
        }
    }
};
