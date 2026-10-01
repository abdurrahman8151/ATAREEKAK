<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('notification_id')->constrained()->onDelete('cascade');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->unique(['user_id', 'notification_id']);
        });
    }

    public function down(): void
    {
        // RV-30: this migration CREATES `user_notifications`, so `down()` must drop THAT
        // table. It previously dropped `push_notification_tokens` — a table this migration
        // never created — so a rollback destroyed the WRONG table's data and left
        // `user_notifications` in place.
        Schema::dropIfExists('user_notifications');
    }
};
