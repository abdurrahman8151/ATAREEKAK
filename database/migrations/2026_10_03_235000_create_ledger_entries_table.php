<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decision un3 (owner, 2026-10-02), double-entry remainder: `ledger_entries`.
 *
 * WHY THIS EXISTS. `wallet_transactions` is SINGLE-SIDED: one row records that ONE wallet moved an
 * amount. That is a per-wallet audit trail, not a ledger. It cannot answer the question a ledger
 * exists to answer - "where did this money come from, and did it all arrive?" - because the other
 * side of every movement is simply absent. The escrow charge is the clearest case: the passenger's
 * row says -100 and SyCash's says +100, but nothing states they are two halves of ONE event, so
 * nothing detects it if one half is ever written without the other.
 *
 * SHAPE. One row per LEG. Every transfer writes at least two rows whose signed amounts sum to zero:
 * money is only ever conserved because some wallet gives and another receives. A single-row table
 * could have encoded this as "from_wallet_id/to_wallet_id"; a legs table is used instead because
 * the real flows are not always two-party (the 95/5 platform split, a refund fan-out over many
 * passengers), and N-sided legs are the general case.
 *
 * THIS IS ADDITIVE. Nothing here changes what any existing code does - `wallet_transactions`,
 * balances, reports and tests are untouched. The ledger is written ALONGSIDE, and a transfer is
 * only considered correct once BOTH representations agree. That is what makes this safe to land
 * incrementally, one money path at a time, instead of as a single risky rewrite.
 *
 * `wallet_transaction_id` is indexed and nullable so a leg can exist before (or without) the
 * single-sided row in the rare paths that are still being converted.
 *
 * `direction` is redundant with the sign of `amount` and is deliberately NOT stored: two sources of
 * truth for one fact is how they drift. A positive amount is a credit, negative is a debit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `ledger_entries` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `wallet_id` BIGINT UNSIGNED NOT NULL,
                `wallet_transaction_id` BIGINT UNSIGNED NULL,
                `amount` DECIMAL(15,2) NOT NULL,
                `description` VARCHAR(255) NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `ledger_entries_wallet_id_foreign` (`wallet_id`),
                KEY `ledger_entries_wallet_transaction_id_foreign` (`wallet_transaction_id`),
                CONSTRAINT `ledger_entries_wallet_id_foreign`
                    FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `ledger_entries_wallet_transaction_id_foreign`
                    FOREIGN KEY (`wallet_transaction_id`) REFERENCES `wallet_transactions` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `ledger_entries`');
    }
};
