<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the platform's profit percentage from trips to the settings singleton
 * row. Drives the driver/platform split in ride creation fees, ride
 * completion payouts, and no-show settlements (previously hardcoded to 5%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_settings', function (Blueprint $table) {
            $table->decimal('platform_profit_percentage', 5, 2)->default(5.00)->after('consent_label');
        });
    }

    public function down(): void
    {
        Schema::table('policy_settings', function (Blueprint $table) {
            $table->dropColumn('platform_profit_percentage');
        });
    }
};
