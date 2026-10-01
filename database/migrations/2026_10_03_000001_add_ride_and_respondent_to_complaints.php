<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-23 — complaints must be able to STORE the ride and respondent context.
 *
 * `Noshowservice::handleConflict` writes a no-show auto-complaint with `ride_id` and
 * `complained_id` (L461-469), but neither column exists on the table and neither key is
 * in Complaint::$fillable — so Eloquent silently DROPPED both. The complaint opened when
 * driver and passenger BOTH press the no-show button — the single case support most
 * needs context for — was created with no ride link and no respondent: staff saw an
 * unattributed "conflict" complaint with no way to reach the booking, while the code
 * kept writing the fields as though it worked. Silent data loss on the dispute path.
 *
 * Additive + nullable, matching the table's own style (`assigned_to` is nullable +
 * constrained): manual complaints (ComplaintService::submit) carry no ride, so existing
 * rows and flows are untouched. nullOnDelete preserves the complaint as evidence even
 * if the ride or respondent row is later removed — cascade would delete the complaint
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('ride_id')->nullable()->after('user_id')
                ->constrained('rides')->nullOnDelete();
            $table->foreignId('complained_id')->nullable()->after('ride_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropForeign(['complained_id']);
            $table->dropForeign(['ride_id']);
            $table->dropColumn(['ride_id', 'complained_id']);
        });
    }
};
