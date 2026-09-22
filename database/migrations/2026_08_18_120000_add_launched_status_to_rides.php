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
