<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T3-6: raw MySQL-only statement(s) below. Skip cleanly on other
        // drivers instead of fataling a fresh migrate (no-op on MySQL).
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            // 1. add new timestamp columns if they do not exist
            if (! Schema::hasColumn('bookings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('bookings', 'passenger_confirmed_at')) {
                $table->timestamp('passenger_confirmed_at')->nullable()->after('completed_at');
            }
        });

        // 2. change status to enum via raw SQL (Doctrine-safe)
        DB::statement("
            ALTER TABLE bookings
            MODIFY COLUMN status ENUM('pending','confirmed','cancelled','no_show','completed')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['completed_at', 'passenger_confirmed_at']);
            DB::statement("ALTER TABLE bookings MODIFY COLUMN status VARCHAR(20) DEFAULT 'pending'");
        });
    }
};
