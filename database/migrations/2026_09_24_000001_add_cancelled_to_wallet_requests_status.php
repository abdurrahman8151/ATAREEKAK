<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'cancelled' to the wallet_requests.status ENUM column.
 *
 * User-side cancellation (DELETE /api/wallet/requests/{id}) marks
 * pending requests as 'cancelled'. The base table definition only
 * allows pending/approved/rejected, so cancelling fails on MySQL
 * with an "incorrect enum value" error without this migration.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE wallet_requests
            MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'rejected',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Re-map stray 'cancelled' rows to 'rejected' so the
        // narrower ENUM remains valid after rollback.
        DB::table('wallet_requests')
            ->where('status', 'cancelled')
            ->update(['status' => 'rejected']);

        DB::statement("
            ALTER TABLE wallet_requests
            MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'rejected'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
