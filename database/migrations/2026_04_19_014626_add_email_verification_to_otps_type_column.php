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

        DB::statement("ALTER TABLE otps MODIFY type ENUM(
            'E-PAYMENT',
            'WALLET_CREATION',
            'registration',
            'login',
            'password_reset',
            'EMAIL_VERIFICATION'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE otps MODIFY type ENUM(
            'E-PAYMENT',
            'WALLET_CREATION',
            'registration',
            'login',
            'password_reset'
        ) NOT NULL");
    }
};
