<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('noshow_resolved')->default(false);
            $table->timestamp('driver_claimed_noshow_at')->nullable();
            $table->timestamp('passenger_claimed_noshow_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'noshow_resolved',
                'driver_claimed_noshow_at',
                'passenger_claimed_noshow_at',
            ]);
        });
    }
};
