<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test/demo data (seeded via Syrideseeder before the ComplaintType/ComplaintStatus
 * enums were renamed) left `complaints` rows with values the current PHP enums
 * don't recognise. Since 2026_08_01_000001, `type`/`status` are plain VARCHAR
 * columns with no DB-level constraint, so these rows insert fine but blow up
 * with a ValueError the moment Eloquent casts them (GET /staff/complaints 500s
 * on every page containing one).
 *
 * Maps the old vocabulary onto its current equivalent:
 *   type:   payment_issue → financial_issue, app_issue → technical_issue
 *   status: open → pending, in_progress → in_review
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('complaints')) {
            return;
        }

        DB::table('complaints')->where('type', 'payment_issue')->update(['type' => 'financial_issue']);
        DB::table('complaints')->where('type', 'app_issue')->update(['type' => 'technical_issue']);

        DB::table('complaints')->where('status', 'open')->update(['status' => 'pending']);
        DB::table('complaints')->where('status', 'in_progress')->update(['status' => 'in_review']);
    }

    public function down(): void {}
};
