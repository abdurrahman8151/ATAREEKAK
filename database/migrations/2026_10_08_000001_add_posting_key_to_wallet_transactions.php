<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-02 L2 (owner decision D1 = A) — `wallet_transactions.posting_key`.
 *
 * WHY
 *
 * `wallet_transactions.transaction_id` LOOKS like an idempotency key and is not one. It is
 * minted from `time()` plus randomness (`'RB_'.time().'_'.Str::random(8)`), so two runs of the
 * same logical movement never produce the same value, nothing enforces its uniqueness, and it
 * cannot be recomputed. Replaying a settlement therefore produced a brand-new, perfectly
 * ordinary-looking transaction every time, and nothing in the schema could tell a replay from
 * the original. That is the structural reason a second settlement COULD pay again: there was no
 * durable fact recording that the movement had already happened.
 *
 * `posting_key` is that fact. It is DETERMINISTIC — derived from the booking/ride and the action,
 * never from a clock or a random string — so the same logical movement recomputes the same key.
 * That is what makes the unique index an idempotency guard rather than merely a constraint: the
 * replay collides at INSERT time, the exception unwinds the surrounding transaction, and no
 * balance was ever written.
 *
 * WHY NULLABLE
 *
 * NULLABLE is deliberate, not laziness. MySQL (and SQLite) permit many NULLs in a UNIQUE index,
 * so every existing row and every transaction that is not one of the instrumented once-only
 * postings is completely unaffected. Had the column been NOT NULL with an empty-string default,
 * the migration would have had to invent a key for historical rows and the index would have had
 * to be built over made-up data.
 *
 * It also mirrors `bookings.idempotency_key` (RV-40/RV-15), which was nullable for the same
 * reason, so the two idempotency mechanisms in this schema read the same way.
 *
 * LENGTH 191 CHARS. The project's existing idempotency column uses the same width. utf8mb4 at
 * 4 bytes/char is 764 bytes, which stays under the 767-byte InnoDB index prefix limit that the
 * older row formats impose, so this cannot fail on the production engine.
 *
 * GUARDED + FAIL-LOUD. The index is only added when MySQL actually has no duplicate non-null key
 * to trip over, and if one somehow exists the migration THROWS with the offending keys named
 * rather than silently building a weaker index or dropping rows. A unique index that is quietly
 * skipped would leave the whole task looking done while enforcing nothing, which is worse than a
 * loud failure.
 */
return new class extends Migration
{
    private const COLUMN = 'posting_key';

    private const INDEX = 'wallet_transactions_posting_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            throw new RuntimeException(
                'RV-02 L2: wallet_transactions does not exist. Refusing to migrate - run the '
                .'full migration set first rather than silently creating a partial schema.'
            );
        }

        if (! Schema::hasColumn('wallet_transactions', self::COLUMN)) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->string(self::COLUMN, 191)
                    ->nullable()
                    ->comment('Deterministic idempotency key for once-only money postings (RV-02 L2 / D1)');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            // SQLite and friends keep the column without the unique index, exactly as the
            // RV-30 index migration does. The application-level guard (guarded escrow
            // decrement) is what protects the money on every driver; this index is the
            // database-level backstop.
            return;
        }

        $indexed = DB::select(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'wallet_transactions'
               AND index_name = ?",
            [self::INDEX]
        )[0]->c ?? 0;

        if ((int) $indexed > 0) {
            return;
        }

        // Fail loud rather than build a partial index: a duplicate non-null key means two rows
        // already claim to be the same posting, which is the exact corruption this column
        // exists to make impossible. Name them so the cause is actionable.
        $duplicates = DB::select(
            'SELECT posting_key, COUNT(*) c FROM wallet_transactions
             WHERE posting_key IS NOT NULL
             GROUP BY posting_key HAVING c > 1
             LIMIT 5'
        );

        if ($duplicates !== []) {
            throw new RuntimeException(
                'RV-02 L2: wallet_transactions.posting_key already holds duplicate non-null '
                .'values, so the unique index cannot be created. Offending keys: '
                .collect($duplicates)
                    ->map(fn ($r) => "{$r->posting_key} (x{$r->c})")
                    ->implode(', ')
                .'. Resolve these before migrating.'
            );
        }

        DB::statement(
            'ALTER TABLE wallet_transactions ADD UNIQUE INDEX '.self::INDEX.' (posting_key)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            throw new RuntimeException(
                'RV-02 L2 down: wallet_transactions does not exist. Refusing to continue.'
            );
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $indexed = DB::select(
                "SELECT COUNT(*) c FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'wallet_transactions'
                   AND index_name = ?",
                [self::INDEX]
            )[0]->c ?? 0;

            if ((int) $indexed > 0) {
                DB::statement('ALTER TABLE wallet_transactions DROP INDEX '.self::INDEX);
            }
        }

        if (Schema::hasColumn('wallet_transactions', self::COLUMN)) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
