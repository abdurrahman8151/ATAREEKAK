<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function idx(string $table, string $name): bool
    {
        return count(\DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name])) > 0;
    }

    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (!$this->idx('rides', 'rides_driver_status'))
                $table->index(['driver_id', 'status'],      'rides_driver_status');
            if (!$this->idx('rides', 'rides_status_departure'))
                $table->index(['status', 'departure_time'], 'rides_status_departure');
            if (!$this->idx('rides', 'rides_departure_time'))
                $table->index(['departure_time'],           'rides_departure_time');
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!$this->idx('bookings', 'bookings_user_status'))
                $table->index(['user_id', 'status'],        'bookings_user_status');
            if (!$this->idx('bookings', 'bookings_ride_status'))
                $table->index(['ride_id', 'status'],        'bookings_ride_status');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            if (!$this->idx('user_notifications', 'notifications_user_read'))
                $table->index(['user_id', 'read_at'],       'notifications_user_read');
            if (!$this->idx('user_notifications', 'notifications_user_created'))
                $table->index(['user_id', 'created_at'],    'notifications_user_created');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (!$this->idx('wallet_transactions', 'wallet_tx_wallet_type'))
                $table->index(['wallet_id', 'type'],        'wallet_tx_wallet_type');
            if (!$this->idx('wallet_transactions', 'wallet_tx_wallet_created'))
                $table->index(['wallet_id', 'created_at'],  'wallet_tx_wallet_created');
        });

        Schema::table('wallet_requests', function (Blueprint $table) {
            if (!$this->idx('wallet_requests', 'wallet_req_status_created'))
                $table->index(['status', 'created_at'],     'wallet_req_status_created');
            if (!$this->idx('wallet_requests', 'wallet_req_user_status'))
                $table->index(['user_id', 'status'],        'wallet_req_user_status');
        });

        Schema::table('score_transactions', function (Blueprint $table) {
            if (!$this->idx('score_transactions', 'score_tx_user_created'))
                $table->index(['user_id', 'created_at'],    'score_tx_user_created');
        });

        Schema::table('complaints', function (Blueprint $table) {
            if (!$this->idx('complaints', 'complaints_user_status'))
                $table->index(['user_id', 'status'],        'complaints_user_status');
            if (!$this->idx('complaints', 'complaints_status_created'))
                $table->index(['status', 'created_at'],     'complaints_status_created');
        });

        Schema::table('otps', function (Blueprint $table) {
            if (!$this->idx('otps', 'otps_expires_at'))
                $table->index(['expires_at'],               'otps_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_driver_status');
            $table->dropIndex('rides_status_departure');
            $table->dropIndex('rides_departure_time');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_user_status');
            $table->dropIndex('bookings_ride_status');
        });
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_read');
            $table->dropIndex('notifications_user_created');
        });
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_tx_wallet_type');
            $table->dropIndex('wallet_tx_wallet_created');
        });
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->dropIndex('wallet_req_status_created');
            $table->dropIndex('wallet_req_user_status');
        });
        Schema::table('score_transactions', function (Blueprint $table) {
            $table->dropIndex('score_tx_user_created');
        });
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex('complaints_user_status');
            $table->dropIndex('complaints_status_created');
        });
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_expires_at');
        });
    }
};