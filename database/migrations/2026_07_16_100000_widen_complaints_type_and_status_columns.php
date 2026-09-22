<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix complaints table ENUM columns.
 *
 * The PHP enums (ComplaintType, ComplaintStatus) were extended with new values
 * but the database ENUM columns were never updated to match, causing
 * SQLSTATE[01000] "Data truncated" errors on insert/update.
 *
 * ComplaintType values added:  financial_issue, account_issue, technical_issue
 * ComplaintStatus values added: escalated
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('complaints')) {
            return;
        }

        // Expand `type` ENUM to match all App\Enums\ComplaintType cases
        DB::statement("
            ALTER TABLE `complaints`
            MODIFY COLUMN `type`
            ENUM(
                'trip_safety',
                'driver_behavior',
                'passenger_behavior',
                'ride_cancellation',
                'financial_issue',
                'account_issue',
                'technical_issue',
                'other'
            ) NOT NULL
        ");

        // Expand `status` ENUM to match all App\Enums\ComplaintStatus cases
        DB::statement("
            ALTER TABLE `complaints`
            MODIFY COLUMN `status`
            ENUM(
                'pending',
                'in_review',
                'escalated',
                'resolved',
                'closed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('complaints')) {
            return;
        }

        // Remove values added in up() — safe only when no rows use them
        DB::statement("
            ALTER TABLE `complaints`
            MODIFY COLUMN `type`
            ENUM(
                'trip_safety',
                'driver_behavior',
                'passenger_behavior',
                'ride_cancellation',
                'other'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE `complaints`
            MODIFY COLUMN `status`
            ENUM(
                'pending',
                'in_review',
                'resolved',
                'closed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
