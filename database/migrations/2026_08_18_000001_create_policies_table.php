<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the privacy and cancellation policy text shown to users, moved out
 * of the mobile app's hardcoded `PolicyText` class so a system_admin can
 * edit it from the dashboard's Settings page without a client release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->unique();
            $table->string('last_updated_label', 100)->nullable();
            $table->json('sections');
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
