<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // T3-6: raw MySQL-only statement(s) below. Skip cleanly on other
        // drivers instead of fataling a fresh migrate (no-op on MySQL).
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Drop any indexes on phone_number first
        try {
            DB::statement('ALTER TABLE otps DROP INDEX otps_phone_number_index');
        } catch (Exception $e) {
            // Index may have a different name, try common alternatives
        }
        try {
            DB::statement('ALTER TABLE otps DROP INDEX phone_number');
        } catch (Exception $e) {
        }

        // Resize the column
        DB::statement('ALTER TABLE otps MODIFY phone_number VARCHAR(191) NOT NULL');

        // Recreate index with prefix length (191 = safe limit for utf8mb4)
        DB::statement('ALTER TABLE otps ADD INDEX otps_phone_number_index (phone_number(191))');
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE otps DROP INDEX otps_phone_number_index');
        } catch (Exception $e) {
        }

        DB::statement('ALTER TABLE otps MODIFY phone_number VARCHAR(20) NOT NULL');

        DB::statement('ALTER TABLE otps ADD INDEX otps_phone_number_index (phone_number)');
    }
};
