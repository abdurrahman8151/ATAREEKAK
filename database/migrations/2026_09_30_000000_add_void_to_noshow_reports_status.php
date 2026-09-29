<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RV-02 (L1): add the `void` terminal state to noshow_reports.status.
 *
 * The settlement path could not be made safe without it. `applyPenalty()` now
 * refuses to settle a booking that is no longer `confirmed` (already released by
 * passenger confirmation, or cancelled), and the report is marked `void` instead
 * of staying `pending`. Leaving it `pending` is what made the scheduler retry the
 * same settlement every minute when the second attempt threw.
 *
 * `failed` (the attempt-counter state for the "SyCash is empty" retry loop R2
 * §1.3 describes) is deliberately NOT added here: it has no writer until L2
 * lands with RV-09/RV-40, and adding an unused enum value would be speculative.
 *
 * Raw ALTER because Laravel's `enum()` change helper needs doctrine/dbal on this
 * Laravel version; the list is spelled out to keep the migration self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE noshow_reports MODIFY COLUMN status '
            ."ENUM('pending','resolved_reporter_wins','disputed','void') "
            ."NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        // Rows in the new state would be truncated by the smaller enum, so move
        // them back to the closest pre-existing value before shrinking the list.
        DB::table('noshow_reports')->where('status', 'void')->update(['status' => 'disputed']);

        DB::statement(
            'ALTER TABLE noshow_reports MODIFY COLUMN status '
            ."ENUM('pending','resolved_reporter_wins','disputed') "
            ."NOT NULL DEFAULT 'pending'"
        );
    }
};
