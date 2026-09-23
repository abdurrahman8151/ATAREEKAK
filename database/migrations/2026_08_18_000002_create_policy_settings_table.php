<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton row (id=1) holding the company/contact metadata that appears
 * alongside the privacy and cancellation policies (company name, app name,
 * contact email/phone/address, consent checkbox label).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('app_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_address')->nullable();
            $table->string('consent_label')->nullable();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_settings');
    }
};
