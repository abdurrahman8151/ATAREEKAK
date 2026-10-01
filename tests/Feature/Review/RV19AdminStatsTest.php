<?php

namespace Tests\Feature\Review;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\AdminReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-19 (slice 1) — the admin "pending complaints" and "revenue" KPIs must be real numbers.
 *
 * getStats() shipped TWO hardcoded/derived-fake numbers (R1 RV-19 named both):
 *   1. 'pending_complaints' => 0            — a literal, so the open-workload card could
 *      never rise no matter how big the support backlog got. Admins were blind to it.
 *   2. revenue read config('system_admin.phone') — a key with NO backing file (V8). The
 *      lookup was always null, the wallet query found nothing, and the revenue card showed
 *      0.00 FOREVER even with escrow present.
 *
 * Fixes: count ComplaintStatus::PENDING (the sibling verification_requests KPI counts
 * exactly 'pending', and StaffAdminController tracks in_review/escalated in their own
 * surfaces, so the convention is decided by the codebase, not by me); and read the
 * canonical admin.system_admin.phone key every money path already uses, scoped with the
 * RV-21 whereNull('user_id') boundary so only the platform wallet satisfies it.
 *
 * The revenue VALUE definition ("what the Primary balance means") is Wave-3's gated escrow
 * redesign (§26.14) — untouched here; this only makes the intended lookup resolve.
 */
class RV19AdminStatsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private function stats(): array
    {
        return app(AdminReportService::class)->getStats();
    }

    private function makeComplaint(string $status, User $by): Complaint
    {
        return Complaint::create([
            'user_id' => $by->id,
            'title' => 't',
            'description' => 'd',
            'type' => ComplaintType::OTHER->value,
            'status' => $status,
        ]);
    }

    /** @test */
    public function pending_complaints_counts_open_workload_instead_of_a_hardcoded_zero(): void
    {
        $u = User::factory()->create();

        $this->assertSame(0, $this->stats()['pending_complaints'], 'empty queue reads zero');

        $this->makeComplaint(ComplaintStatus::PENDING->value, $u);
        $this->makeComplaint(ComplaintStatus::PENDING->value, $u);

        $this->assertSame(
            2,
            $this->stats()['pending_complaints'],
            'RV-19: two pending complaints must show 2, not the literal 0'
        );
    }

    /** @test */
    public function pending_complaints_counts_only_pending_status(): void
    {
        // Convention pin: in_review / resolved / closed / escalated are NOT "pending"
        // (they have their own surfaces); counting them here would be a different metric.
        $u = User::factory()->create();
        $this->makeComplaint(ComplaintStatus::PENDING->value, $u);
        $this->makeComplaint(ComplaintStatus::IN_REVIEW->value, $u);
        $this->makeComplaint(ComplaintStatus::CLOSED->value, $u);
        $this->makeComplaint(ComplaintStatus::RESOLVED->value, $u);

        $this->assertSame(
            1,
            $this->stats()['pending_complaints'],
            'only status=pending counts toward the pending backlog KPI'
        );
    }

    /** @test */
    public function revenue_reads_the_real_primary_wallet_balance(): void
    {
        // Seed the platform (user_id NULL) Primary Escrow wallet the same way production
        // does, fund it, and the revenue KPI must reflect the balance — the phantom-key bug
        // made this 0.00 permanently.
        $this->seedSystemWallets(10_000_000.0);

        $primaryPhone = (string) config('admin.system_admin.phone');
        $wallet = Wallet::where('phone_number', $primaryPhone)->whereNull('user_id')->firstOrFail();
        $this->assertSame(10_000_000.0, (float) $wallet->balance);

        $stats = $this->stats();

        $this->assertSame(
            10_000_000.0,
            (float) $stats['total_revenue']['raw'],
            'RV-19: revenue must equal the primary escrow balance, not a forced zero'
        );
        $this->assertStringContainsString('10,000,000', $stats['total_revenue']['formatted']);
    }

    /** @test */
    public function a_user_owned_wallet_on_the_system_phone_is_not_counted_as_revenue(): void
    {
        // RV-21 boundary held here too: the revenue lookup must not adopt a user-owned
        // wallet that squats the primary phone. Only the platform wallet (user_id NULL) is.
        $this->seedSystemWallets(10_000_000.0);
        $primaryPhone = (string) config('admin.system_admin.phone');

        // Move the system wallet's phone is not the test — instead: no system wallet at all,
        // only a user-owned one on the phone (pre-seeder hijack shape) -> revenue stays 0.
        Wallet::where('phone_number', $primaryPhone)->whereNull('user_id')->delete();
        $hijacker = User::factory()->create();
        Wallet::create([
            'user_id' => $hijacker->id,
            'phone_number' => $primaryPhone,
            'balance' => 999_999.0,
        ]);

        $this->assertSame(
            0.0,
            (float) $this->stats()['total_revenue']['raw'],
            'a user-owned wallet on the system phone must never be read as platform revenue'
        );
    }
}
