<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'launched' to the rides.status ENUM column.
 *
 * 'launched' replaces 'awaiting_confirmation' as the semantic name for
 * the state between departure and full completion.  The old value is kept
 * in the column definition so existing rows remain valid.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // RV-18 / T3-6: raw MySQL-only statement(s) below. Skip cleanly on other
        // drivers instead of fataling a fresh migrate (no-op on MySQL). The siblings
        // that ALTER an ENUM already carry this guard; this one was missing it, so a
        // sqlite `migrate:fresh` (which CI does when the driver leaks — see V6) died
        // here rather than skipping.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // MySQL/MariaDB: ALTER TABLE to extend the ENUM.
        // The complete list must be re-declared every time an ENUM is altered.
        DB::statement("
            ALTER TABLE rides
            MODIFY COLUMN status ENUM(
                'active',
                'full',
                'cancelled',
                'finished',
                'awaiting_confirmation',
                'launched'
            ) NOT NULL DEFAULT 'active'
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Remove 'launched' but keep 'awaiting_confirmation' intact.
        // Any row currently set to 'launched' will become invalid –
        // UPDATE them first if you need a clean rollback.
        DB::statement("
            ALTER TABLE rides
            MODIFY COLUMN status ENUM(
                'active',
                'full',
                'cancelled',
                'finished',
                'awaiting_confirmation'
            ) NOT NULL DEFAULT 'active'
        ");
    }
};
