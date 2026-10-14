<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * L10 (modular-monolith slice 9, MONEY): characterization test for POST /api/admin/wallet/charge.
 *
 * Step 1 of the chargeWallet change. The existing tests check status codes only. None checks the
 * money effects a refactor could break: the target balance, the External Capital debit, the two ledger
 * rows, the transaction type, or the failure paths. This file pins them on the UNCHANGED code.
 * It changes no application code, and must pass before any step 2 change is considered.
 */
class L10AdminChargeWalletCharacterizationTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    private const TARGET_PHONE = '0911222333';

    private Wallet $target;

    private Wallet $external;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->seedSystemWallets(0.0);
        $this->external = $system['external'];

        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->target = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => self::TARGET_PHONE,
            'wallet_number' => 'WLT-L10'.random_int(100000, 999999),
            'balance' => 100,
        ]);
        $user->update(['wallet_id' => $this->target->id]);
    }

    public function test_charge_credits_the_target_wallet_by_exactly_the_amount(): void
    {
        $this->charge(5000)->assertOk();

        $this->assertEquals(5100.0, (float) $this->target->fresh()->balance);
    }

    public function test_charge_debits_the_external_capital_account_by_the_same_amount(): void
    {
        $before = (float) $this->external->fresh()->balance;

        $this->charge(5000)->assertOk();

        $this->assertEquals($before - 5000, (float) $this->external->fresh()->balance);
    }

    public function test_charge_writes_an_admin_credit_row_on_the_target_wallet(): void
    {
        $response = $this->charge(5000)->assertOk();

        $row = WalletTransaction::where('wallet_id', $this->target->id)
            ->where('type', 'admin_credit')
            ->firstOrFail();

        $this->assertEquals(5000.0, (float) $row->amount);
        $this->assertEquals(100.0, (float) $row->previous_balance);
        $this->assertEquals(5100.0, (float) $row->new_balance);
        $this->assertSame('completed', $row->status);
        $this->assertSame($row->transaction_id, $response->json('transaction_id'));
    }

    public function test_charge_writes_an_external_inbound_row_on_the_external_account(): void
    {
        $this->charge(5000)->assertOk();

        $row = WalletTransaction::where('wallet_id', $this->external->id)
            ->where('type', 'external_inbound')
            ->firstOrFail();

        $this->assertEquals(-5000.0, (float) $row->amount);
        $this->assertSame('completed', $row->status);
    }

    public function test_the_response_reports_previous_and_new_balance(): void
    {
        $response = $this->charge(5000)->assertOk();

        $this->assertSame('success', $response->json('status'));
        $this->assertSame(self::TARGET_PHONE, $response->json('wallet.phone_number'));
        $this->assertArrayHasKey('previous_balance', $response->json('wallet'));
        $this->assertArrayHasKey('new_balance', $response->json('wallet'));
    }

    public function test_a_second_charge_stacks_on_the_first(): void
    {
        $this->charge(1000)->assertOk();
        $this->charge(2000)->assertOk();

        $this->assertEquals(3100.0, (float) $this->target->fresh()->balance);
    }

    public function test_an_unknown_phone_is_refused_with_404_and_moves_no_money(): void
    {
        $externalBefore = (float) $this->external->fresh()->balance;

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0999000111', 'amount' => 5000])
            ->assertStatus(404)
            ->assertJsonPath('code', 'WALLET_NOT_FOUND');

        $this->assertEquals(100.0, (float) $this->target->fresh()->balance);
        $this->assertEquals($externalBefore, (float) $this->external->fresh()->balance);
    }

    public function test_a_zero_or_negative_amount_is_refused_and_moves_no_money(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => self::TARGET_PHONE, 'amount' => -50])
            ->assertStatus(422);

        $this->assertEquals(100.0, (float) $this->target->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('type', 'admin_credit')->count());
    }

    public function test_a_non_admin_cannot_charge_and_moves_no_money(): void
    {
        $this->withToken($this->sycashToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => self::TARGET_PHONE, 'amount' => 5000])
            ->assertStatus(403);

        $this->assertEquals(100.0, (float) $this->target->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('type', 'admin_credit')->count());
    }

    private function charge(float $amount)
    {
        return $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => self::TARGET_PHONE, 'amount' => $amount]);
    }

    private function sycashToken(): string
    {
        return $this->adminToken(null, 'sycash');
    }
}
