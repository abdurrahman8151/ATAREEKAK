<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether a complaint was ever escalated, independent of its current
 * status. Without this, `status = 'escalated'` is overwritten the moment an
 * escalated complaint is resolved/closed, so there is no way to ask "which
 * complaints were escalated and then resolved?" — see BUG-10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
