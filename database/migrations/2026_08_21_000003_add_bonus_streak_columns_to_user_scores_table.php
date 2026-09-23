<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Supports the two streak-based score bonuses:
//   - 3 consecutive 5-star ratings  → +2 bonus (consecutive_five_star_ratings)
//   - Driver commitment without cancellation for 7/14/30 days → +2/+4/+6
//     (last_driver_cancellation_at is the streak anchor, last_streak_milestone_days
//     records the highest milestone already rewarded so it is never double-paid)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->unsignedTinyInteger('consecutive_five_star_ratings')->default(0)->after('tier');
            $table->timestamp('last_driver_cancellation_at')->nullable()->after('consecutive_five_star_ratings');
            $table->unsignedTinyInteger('last_streak_milestone_days')->default(0)->after('last_driver_cancellation_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->dropColumn([
                'consecutive_five_star_ratings',
                'last_driver_cancellation_at',
                'last_streak_milestone_days',
            ]);
        });
    }
};
