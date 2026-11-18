<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletRequest;
use App\Modules\Money\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * L13 (modular-monolith slice 12, MONEY): characterization test for POST /api/admin/requests/{id}/approve.
 *
 * Step 1 of the approve change. The attribution tests check WHO approved, not the money. Nothing pins the
 * balances, the External Capital sign for top-ups and withdrawals, the two ledger rows per approval, the
 * overdraft refusal on a withdrawal, or the already-decided refusal. This file pins them on the UNCHANGED code.
 * It changes no application code and must pass before any step 2 change is considered.
 */
class L13WalletRequestApproveCharacterizationTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $user;

    private Wallet $wallet;

    private Wallet $external;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->seedSystemWallets(0.0);
        $this->external = $system['external'];

        $this->user = User::factory()->create(['is_verified_passenger' => true]);
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'phone_number' => '0914'.random_int(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1000,
        ]);
        $this->user->update(['wallet_id' => $this->wallet->id]);
    }

    public function test_approving_a_top_up_credits_the_wallet_by_the_amount(): void
    {
        $request = $this->request('charge', 5000);

        $this->approve($request)->assertOk();

        $this->assertEquals(6000.0, (float) $this->wallet->fresh()->balance);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_approving_a_top_up_writes_an_admin_charge_row_on_the_wallet(): void
    {
        $request = $this->request('charge', 5000);

        $this->approve($request)->assertOk();

        $row = WalletTransaction::where('wallet_id', $this->wallet->id)->where('type', 'admin_charge')->firstOrFail();
        $this->assertEquals(5000.0, (float) $row->amount);
        $this->assertEquals(1000.0, (float) $row->previous_balance);
        $this->assertEquals(6000.0, (float) $row->new_balance);
        $this->assertSame('completed', $row->status);
    }

    public function test_approving_a_top_up_debits_the_external_capital_account(): void
    {
        $before = (float) $this->external->fresh()->balance;

        $this->approve($this->request('charge', 5000))->assertOk();

        $this->assertEquals($before - 5000, (float) $this->external->fresh()->balance);
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $this->external->id)->where('type', 'external_inbound')->count(),
            'a top-up records one external_inbound row'
        );
    }

    public function test_approving_a_withdrawal_debits_the_wallet_by_the_amount(): void
    {
        $request = $this->request('withdraw', 400);

        $this->approve($request)->assertOk();

        $this->assertEquals(600.0, (float) $this->wallet->fresh()->balance);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_approving_a_withdrawal_writes_a_negative_withdrawal_row(): void
    {
        $this->approve($this->request('withdraw', 400))->assertOk();

        $row = WalletTransaction::where('wallet_id', $this->wallet->id)->where('type', 'withdrawal')->firstOrFail();
        $this->assertEquals(-400.0, (float) $row->amount, 'a withdrawal is stored as an outflow (negative)');
    }

    public function test_approving_a_withdrawal_credits_the_external_capital_account(): void
    {
        $before = (float) $this->external->fresh()->balance;

        $this->approve($this->request('withdraw', 400))->assertOk();

        $this->assertEquals($before + 400, (float) $this->external->fresh()->balance);
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $this->external->id)->where('type', 'external_outbound')->count(),
            'a withdrawal records one external_outbound row'
        );
    }

    public function test_a_withdrawal_larger_than_the_balance_is_refused_and_moves_no_money(): void
    {
        $request = $this->request('withdraw', 5000);
        $externalBefore = (float) $this->external->fresh()->balance;

        $this->approve($request)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertEquals(1000.0, (float) $this->wallet->fresh()->balance, 'the wallet must not go negative');
        $this->assertEquals($externalBefore, (float) $this->external->fresh()->balance);
        $this->assertSame('pending', $request->fresh()->status, 'the request must stay pending');
        $this->assertSame(0, WalletTransaction::where('type', 'withdrawal')->count());
    }

    public function test_an_already_decided_request_is_refused_and_moves_no_money(): void
    {
        $request = $this->request('charge', 5000, 'approved');

        $this->approve($request)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertEquals(1000.0, (float) $this->wallet->fresh()->balance, 'an approved request must not credit again');
    }

    private function request(string $type, float $amount, string $status = 'pending'): WalletRequest
    {
        return WalletRequest::create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => $type,
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    private function approve(WalletRequest $request)
    {
        return $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve", []);
    }
}
