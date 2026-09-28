<?php

use App\Models\UserRating;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_ratings', function (Blueprint $table) {
            // Allow NULL so system-generated seed ratings need no rater row.
            // NULL rater_id means "platform-assigned initial rating".
            $table->unsignedBigInteger('rater_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Delete system rows first so the NOT NULL constraint can be restored.
        UserRating::whereNull('rater_id')->delete();

        Schema::table('user_ratings', function (Blueprint $table) {
            $table->unsignedBigInteger('rater_id')->nullable(false)->change();
        });
    }
};
