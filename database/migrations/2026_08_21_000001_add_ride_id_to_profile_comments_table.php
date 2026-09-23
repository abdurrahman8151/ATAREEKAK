<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ProfileComment (app/Models/ProfileComment.php) and ProfileInteractionService
// read/write a `ride_id` column that was never added to this table, so every
// call to POST /profile/{userId}/comments fails with a SQL "unknown column"
// error (surfaced to clients as a 500).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('profile_comments', function (Blueprint $table) {
            $table->foreignId('ride_id')
                ->nullable()
                ->after('user_id')
                ->constrained('rides')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profile_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ride_id');
        });
    }
};
