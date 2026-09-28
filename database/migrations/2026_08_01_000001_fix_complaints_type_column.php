<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // T3-6: raw MySQL-only statement(s) below. Skip cleanly on other
        // drivers instead of fataling a fresh migrate (no-op on MySQL).
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE complaints MODIFY COLUMN `type` VARCHAR(50) NOT NULL");
        DB::statement("ALTER TABLE complaints MODIFY COLUMN `status` VARCHAR(50) NOT NULL");
    }

    public function down(): void {}
};
