<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RV-30 ratchet — data-model hygiene invariants.
 *
 * 1. wallet_transactions.reference is INDEXED. It is written on essentially every money row
 *    (`booking:{id}`, `ride:{id}`, `wallet:{id}`, `admin_charge:{id}` …) and every
 *    reconciliation read filters on it, yet it carried no index — a full table scan on a table
 *    that only grows. Pinned against the real schema (MySQL-specific, so skipped elsewhere).
 *
 * 2. NO migration's down() drops a table its own up() does not create. One did:
 *    `create_user_notifications_table` created `user_notifications` but its down() dropped
 *    `push_notification_tokens` — so a rollback destroyed the WRONG table's data and left the
 *    real table behind. The ratchet scans every migration and pins the whole CLASS is clean, so
 *    the bug cannot reappear in a future migration.
 *
 * 3. `user_ratings` keeps unique(rater_id, rated_user_id). V9 recorded that the schema enforces
 *    one-rating-per-PAIR while the business rule wanted one-per-RIDE; changing the uniqueness
 *    rule is a product decision, so the CURRENT, working constraint is pinned here to make any
 *    future change deliberate rather than accidental.
 */
class RV30DataModelHygieneTest extends TestCase
{
    /** @test */
    public function wallet_transactions_reference_is_indexed_for_reconciliation_reads(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('index presence is a MySQL schema concern (the migration guards).');
        }

        $indexes = DB::select(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions'
               AND NON_UNIQUE = 0 OR (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions'
               AND COLUMN_NAME = 'reference')
             GROUP BY INDEX_NAME"
        );

        $hasReference = collect($indexes)->contains(
            fn ($i) => str_contains((string) ($i->cols ?? ''), 'reference')
        );

        $this->assertTrue(
            $hasReference,
            'RV-30: wallet_transactions.reference must be indexed — reconciliation reads filter '
            .'on it and it was a full table scan'
        );
    }

    /** @test */
    public function no_migration_drops_a_table_its_own_up_did_not_create(): void
    {
        $mismatches = [];
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            $src = (string) file_get_contents($file);

            preg_match_all("/Schema::create\(\s*'([^']+)'/", $src, $c);
            $creates = $c[1];
            if ($creates === []) {
                continue; // nothing created → nothing to mismatch
            }

            preg_match_all("/Schema::drop(?:IfExists)?\(\s*'([^']+)'/", $src, $d);
            foreach ($d[1] as $dropped) {
                if (! in_array($dropped, $creates, true)) {
                    $mismatches[] = basename($file).': drops "'.$dropped.'" but creates ['.implode(', ', $creates).']';
                }
            }
        }

        $this->assertSame([], $mismatches,
            "RV-30: every migration's down() must drop only the table its up() created.\n"
            .implode("\n", $mismatches));
    }

    /** @test */
    public function the_user_notifications_migration_drops_its_own_table(): void
    {
        // The specific historical bug, pinned by name so the fix is legible.
        $file = base_path('database/migrations/2025_05_23_173034_create_user_notifications_table.php');
        $this->assertFileExists($file);
        $src = (string) file_get_contents($file);

        $this->assertStringContainsString(
            "Schema::dropIfExists('user_notifications')",
            $src,
            'RV-30: this migration creates user_notifications, so down() must drop that table '
            .'(it previously dropped push_notification_tokens — the wrong table)'
        );
        $this->assertStringNotContainsString(
            "Schema::dropIfExists('push_notification_tokens')",
            $src,
            'RV-30: this migration must not drop push_notification_tokens — it does not create it'
        );
    }

    /** @test */
    public function user_ratings_keeps_unique_rater_rated_user_pair(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('index presence is a MySQL schema concern.');
        }

        $unique = DB::select(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_ratings' AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME"
        );

        $hasPair = collect($unique)->contains(
            fn ($i) => str_contains((string) ($i->cols ?? ''), 'rater_id')
                && str_contains((string) ($i->cols ?? ''), 'rated_user_id')
        );

        $this->assertTrue(
            $hasPair,
            'RV-30/V9: user_ratings must keep unique(rater_id, rated_user_id). Changing this to '
            .'"one per ride" is a product decision — do not alter it implicitly.'
        );
    }
}
