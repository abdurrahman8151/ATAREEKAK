<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    private WalletTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'phone_number' => '09'.rand(10000000, 99999999), // unique per test
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 0,
        ]);

        $this->transaction = WalletTransaction::create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'type' => 'test_credit',
            'amount' => 500.00,
            'previous_balance' => 0.00,
            'new_balance' => 500.00,
            'description' => 'Test transaction',
            'transaction_id' => 'TX-'.Str::uuid(), // unique per test — no duplicate key
            'status' => 'completed',
        ]);
    }

    public function test_fillable_contains_all_expected_fields(): void
    {
        $fillable = $this->transaction->getFillable();

        foreach ([
            'wallet_id', 'user_id', 'type', 'amount',
            'previous_balance', 'new_balance', 'description',
            'transaction_id', 'status', 'reference',
        ] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_amount_is_cast_to_decimal(): void
    {
        $this->assertEquals('decimal:2', $this->transaction->getCasts()['amount']);
    }

    public function test_previous_balance_is_cast_to_decimal(): void
    {
        $this->assertEquals('decimal:2', $this->transaction->getCasts()['previous_balance']);
    }

    public function test_new_balance_is_cast_to_decimal(): void
    {
        $this->assertEquals('decimal:2', $this->transaction->getCasts()['new_balance']);
    }

    public function test_belongs_to_wallet(): void
    {
        $this->assertNotNull($this->transaction->wallet);
        $this->assertEquals($this->wallet->id, $this->transaction->wallet->id);
    }

    public function test_belongs_to_user(): void
    {
        $this->assertNotNull($this->transaction->user);
        $this->assertEquals($this->user->id, $this->transaction->user->id);
    }
}
