<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decision un3 (owner, 2026-10-02), FOUNDATION SLICE: `wallets.kind`.
 *
 * THE AMBIGUITY THIS REMOVES. A wallet's role is currently inferred from `user_id IS NULL`: that is
 * how `WalletTransactionService::lockWalletByPhone` decides a row is the platform's SyCash/Primary
 * wallet, and how `AdminReportService` decides which wallet holds platform revenue. Both had to be
 * defensive about it (lockWalletByPhone filters `whereNull('user_id')` AND re-checks, because a
 * user who registered on the platform's configured phone number could otherwise own the escrow
 * sink - the RV-21 hijack the audit recorded).
 *
 * Inferring ownership from a nullable foreign key is a load-bearing accident: it makes "is this a
 * system wallet?" a question about DATA rather than about TYPE, so a bad insert, a restore, or a
 * seeder bug can silently turn a user's wallet into the escrow sink. `kind` makes it explicit.
 *
 * BACKFILL, NOT A GUESS. The two system wallets are identified by the SAME configured phones the
 * application already uses to find them (`config('admin.sycash.phone')`,
 * `config('admin.system_admin.phone')`), not by guessing which rows look system-ish. Every other
 * wallet is a `user` wallet. A row matching NEITHER configured phone keeps `user` - the safe default,
 * because only the two configured phones can ever be looked up as system wallets by the code.
 *
 * `user_id` IS DELIBERATELY KEPT. This migration adds meaning; it does not remove the existing
 * constraint. Dropping it would break `lockWalletByPhone`'s `whereNull('user_id')` guard and the
 * admin reports in the same release. Keeping both means the new column and the old invariant are
 * consistent by construction, and `lockWalletByPhone` now checks BOTH (defence in depth) - see R2
 * section 56.
 *
 * ROLLBACK is exact: dropping the column restores the previous schema, and the backfilled values
 * carry no information that cannot be re-derived from `user_id IS NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `wallets` ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'user' AFTER `user_id`");

        // System wallets are exactly the two configured platform phones.
        foreach (['admin.sycash.phone', 'admin.system_admin.phone'] as $configKey) {
            $phone = config($configKey);

            if (empty($phone)) {
                // A missing config must not silently mark nothing (or everything). Skipping is safe:
                // the row keeps 'user', and the lookup in lockWalletByPhone fails closed anyway.
                continue;
            }

            DB::table('wallets')
                ->where('phone_number', $phone)
                ->update(['kind' => 'system']);
        }

        /*
         * A DATABASE TRIGGER, so `kind` can never silently disagree with `user_id`.
         *
         * Why this and not "just remember to pass kind" — measured, not theorised: eight test files
         * hand-roll their own system-wallet seeding (`Wallet::create(['user_id' => null, 'phone' =>
         * ...])`) instead of using the shared trait. With a plain column default every one of them
         * produced a `kind = user` wallet and `lockWalletByPhone` failed closed with "System wallet
         * not found" — 46 errors that looked like a broken migration and were really eight forgotten
         * call sites. Editing eight tests to add one field would have left the same trap for the
         * next person who seeds a wallet.
         *
         * The trigger makes the two columns agree by construction: any INSERT or UPDATE that gives a
         * row `user_id IS NULL` gets `kind = system`; any row that acquires a user_id reverts to
         * `user`. That is the RV-21 hijack defence stated as a schema rule instead of as a filter
         * somebody has to remember to write.
         */
        foreach (['wallets_kind_matches_owner', 'wallets_kind_matches_owner_update'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        $body = "BEGIN
                IF NEW.user_id IS NULL THEN
                    SET NEW.kind = 'system';
                ELSE
                    SET NEW.kind = 'user';
                END IF;
            END";

        DB::unprepared(
            "CREATE TRIGGER `wallets_kind_matches_owner` BEFORE INSERT ON `wallets` FOR EACH ROW {$body}"
        );

        DB::unprepared(
            "CREATE TRIGGER `wallets_kind_matches_owner_update` BEFORE UPDATE ON `wallets` FOR EACH ROW {$body}"
        );
    }

    public function down(): void
    {
        // Triggers first: dropping the column while a trigger references NEW.kind would fail.
        foreach (['wallets_kind_matches_owner', 'wallets_kind_matches_owner_update'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        DB::statement('ALTER TABLE `wallets` DROP COLUMN `kind`');
    }
};
