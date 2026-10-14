<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * L24 (AdminWalletRequestController::approve, characterization): pins the post-commit cash ride debt
 * clearing rule. Debt is cleared only after a CHARGE (top-up). A WITHDRAWAL must leave the debt alone.
 *
 * The approve tests in L13 pin balances and ledger rows but not this branch, so an inverted guard
 * passed them. This file pins the branch on the unchanged code.
 */
class L24ApproveAutoClearDebtTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(0.0);

        $this->user = User::factory()->create(['is_verified_driver' => true]);
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'phone_number' => '0915'.random_int(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1000,
            'cash_ride_debt' => 300,
        ]);
        $this->user->update(['wallet_id' => $this->wallet->id]);
    }

    public function test_approving_a_top_up_clears_the_cash_ride_debt_when_the_balance_covers_it(): void
    {
        $this->approve($this->request('charge', 5000))->assertOk();

        $this->assertEquals(0.0, (float) $this->wallet->fresh()->cash_ride_debt, 'a charge must clear the debt');
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $this->wallet->id)->where('type', 'cash_ride_debt_cleared')->count(),
            'a charge that clears debt records one cash_ride_debt_cleared row'
        );
    }

    public function test_approving_a_withdrawal_leaves_the_cash_ride_debt_in_place(): void
    {
        $this->approve($this->request('withdraw', 400))->assertOk();

        $this->assertEquals(300.0, (float) $this->wallet->fresh()->cash_ride_debt, 'a withdrawal must not clear the debt');
        $this->assertSame(
            0,
            WalletTransaction::where('wallet_id', $this->wallet->id)->where('type', 'cash_ride_debt_cleared')->count(),
            'a withdrawal records no cash_ride_debt_cleared row'
        );
    }

    private function request(string $type, float $amount): WalletRequest
    {
        return WalletRequest::create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => $type,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    private function approve(WalletRequest $request)
    {
        return $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve", []);
    }
}
