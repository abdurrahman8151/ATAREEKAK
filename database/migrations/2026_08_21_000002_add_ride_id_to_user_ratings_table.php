<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// UserRating (app/Models/UserRating.php) and ProfileInteractionService::rateUser()
// read/write a `ride_id` column that was never added to this table, so every
// call to POST /profile/{userId}/rate fails with a SQL "unknown column" error
// (surfaced to clients as a 500).
//
// The original unique index (rater_id, rated_user_id) also predates per-ride
// ratings: the service already allows one rating per ride (it checks
// rater_id + rated_user_id + ride_id for duplicates), so the old index would
// still throw a duplicate-key DB error the second time the same passenger
// rates the same driver after a later ride. Replaced with a per-ride index.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_ratings', function (Blueprint $table) {
            $table->foreignId('ride_id')
                ->nullable()
                ->after('rated_user_id')
                ->constrained('rides')
                ->nullOnDelete();
        });

        Schema::table('user_ratings', function (Blueprint $table) {
            $table->dropUnique('user_ratings_rater_id_rated_user_id_unique');
            $table->unique(['rater_id', 'rated_user_id', 'ride_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_ratings', function (Blueprint $table) {
            $table->dropUnique(['rater_id', 'rated_user_id', 'ride_id']);
            $table->unique(['rater_id', 'rated_user_id']);
            $table->dropConstrainedForeignId('ride_id');
        });
    }
};
