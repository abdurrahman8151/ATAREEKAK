<?php

namespace Tests\Unit\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\AdminWalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminWalletService $service;

    private array $primaryConfig;

    private array $sycashConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AdminWalletService;

        $this->primaryConfig = [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
            'first_name' => 'Primary',
            'last_name' => 'Admin',
            'phone' => '0910000001',
            'type' => 'system_admin',
            'wallet_prefix' => 'PRIM',
            'permissions' => ['*'],
        ];

        $this->sycashConfig = [
            'email' => 'sycash@admin.test',
            'password' => 'sycash_pass',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'phone' => '0910000002',
            'type' => 'sycash',
            'wallet_prefix' => 'SYCSH',
            'permissions' => ['view_wallet'],
        ];

        // FIX: config key is admin.system_admin in the real app, not admin.primary
        Config::set('admin.system_admin', $this->primaryConfig);
        Config::set('admin.sycash', $this->sycashConfig);

        // FIX: AdminWalletService::getOrCreateWallet() only fetches now — it
        // throws RuntimeException if no wallet exists. Pre-seed both wallets.
        $this->seedWalletFor($this->primaryConfig);
        $this->seedWalletFor($this->sycashConfig);
    }

    private function seedWalletFor(array $cfg): Wallet
    {
        $user = User::firstOrCreate(
            ['email' => $cfg['email']],
            [
                'first_name' => $cfg['first_name'],
                'last_name' => $cfg['last_name'],
                'password' => bcrypt($cfg['password']),
                'gender' => 'M',
                'address' => 'دمشق',
                'status' => 1,
            ]
        );

        $wallet = Wallet::firstOrCreate(
            ['phone_number' => $cfg['phone']],
            [
                'user_id' => $user->id,
                'balance' => 0,
                // wallet_number intentionally omitted — the model auto-generates
                // a 16-digit number; anything we set by hand must fit that same
                // VARCHAR(16) column or MySQL throws "Data too long for column".
            ]
        );

        if (! $user->wallet_id) {
            $user->update(['wallet_id' => $wallet->id]);
        }

        return $wallet;
    }

    // ─── getOrCreateWallet ───────────────────────────────────────────────
    public function test_get_or_create_wallet_returns_wallet_for_admin(): void
    {
        $wallet = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertEquals($this->primaryConfig['phone'], $wallet->phone_number);
    }

    public function test_get_or_create_wallet_returns_same_wallet_on_repeat_calls(): void
    {
        $first = $this->service->getOrCreateWallet($this->primaryConfig);
        $second = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Wallet::where('phone_number', $this->primaryConfig['phone'])->count());
    }

    public function test_get_or_create_wallet_different_configs_return_separate_wallets(): void
    {
        $w1 = $this->service->getOrCreateWallet($this->primaryConfig);
        $w2 = $this->service->getOrCreateWallet($this->sycashConfig);

        $this->assertNotEquals($w1->id, $w2->id);
    }

    public function test_get_or_create_wallet_throws_when_no_wallet_seeded(): void
    {
        $unseededConfig = [
            'email' => 'ghost@admin.test',
            'phone' => '0999999999',
            'type' => 'ghost',
        ];

        $this->expectException(\RuntimeException::class);
        $this->service->getOrCreateWallet($unseededConfig);
    }

    // ─── chargeWallet ────────────────────────────────────────────────────
    public function test_charge_wallet_increases_balance(): void
    {
        $wallet = $this->createUserWallet('0911111111', 1000);
        $amount = Money::from(500);

        $result = $this->service->chargeWallet('0911111111', $amount, $this->primaryConfig);

        $this->assertEquals(1500, (float) $wallet->fresh()->balance);
        $this->assertEquals(1000, (float) $result['previous_balance']->amount());
        $this->assertEquals(1500, (float) $result['new_balance']->amount());
    }

    public function test_charge_wallet_returns_wallet_transaction_and_balances(): void
    {
        $this->createUserWallet('0911111111', 0);

        $result = $this->service->chargeWallet(
            '0911111111',
            Money::from(2000),
            $this->primaryConfig
        );

        $this->assertArrayHasKey('wallet', $result);
        $this->assertArrayHasKey('transaction', $result);
        $this->assertArrayHasKey('previous_balance', $result);
        $this->assertArrayHasKey('new_balance', $result);
    }

    public function test_charge_wallet_creates_transaction_record(): void
    {
        $wallet = $this->createUserWallet('0911111111', 0);

        $this->service->chargeWallet('0911111111', Money::from(300), $this->primaryConfig);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'admin_credit',
            'amount' => 300,
        ]);
    }

    public function test_charge_wallet_transaction_id_includes_admin_type(): void
    {
        $this->createUserWallet('0911111111', 0);

        $result = $this->service->chargeWallet(
            '0911111111',
            Money::from(100),
            $this->primaryConfig
        );

        // FIX: transaction id prefix derives from admin type, which is now
        // 'system_admin' rather than 'primary'
        $this->assertStringStartsWith('SYSTEM_ADMIN_', $result['transaction']->transaction_id);
    }

    public function test_charge_wallet_throws_when_phone_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->chargeWallet('0000000000', Money::from(100), $this->primaryConfig);
    }

    // ─── getAdminWallets ─────────────────────────────────────────────────
    public function test_get_admin_wallets_returns_array(): void
    {
        $wallets = $this->service->getAdminWallets();

        $this->assertIsArray($wallets);
        $this->assertCount(2, $wallets);
    }

    public function test_get_admin_wallets_returns_required_keys(): void
    {
        $wallets = $this->service->getAdminWallets();

        foreach ($wallets as $wallet) {
            $this->assertArrayHasKey('id', $wallet);
            $this->assertArrayHasKey('wallet_number', $wallet);
            $this->assertArrayHasKey('phone_number', $wallet);
            $this->assertArrayHasKey('balance', $wallet);
            $this->assertArrayHasKey('name', $wallet); // was 'owner'
            $this->assertArrayHasKey('admin_type', $wallet);
        }
    }

    // ─── getWalletTransactions ───────────────────────────────────────────
    public function test_get_wallet_transactions_returns_wallet_and_transactions(): void
    {
        $wallet = $this->createUserWallet('0911111111', 500);

        $result = $this->service->getWalletTransactions($wallet->id);

        $this->assertArrayHasKey('wallet', $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertEquals($wallet->id, $result['wallet']->id);
    }

    public function test_get_wallet_transactions_throws_for_nonexistent_wallet(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->getWalletTransactions(999999);
    }

    public function test_get_wallet_transactions_respects_per_page_parameter(): void
    {
        $wallet = $this->createUserWallet('0911111111', 0);

        for ($i = 0; $i < 15; $i++) {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => 'admin_credit',
                'amount' => 10,
                'previous_balance' => 0,
                'new_balance' => 10,
                'description' => "Transaction {$i}",
                'transaction_id' => "TEST_{$i}_".uniqid(),
                'status' => 'completed',
            ]);
        }

        $result5 = $this->service->getWalletTransactions($wallet->id, 5);
        $result10 = $this->service->getWalletTransactions($wallet->id, 10);

        $this->assertEquals(5, $result5['transactions']->perPage());
        $this->assertEquals(10, $result10['transactions']->perPage());
    }

    // ─── getAllWallets ───────────────────────────────────────────────────
    public function test_get_all_wallets_returns_array(): void
    {
        $this->createUserWallet('0911111121', 0);
        $this->createUserWallet('0911111122', 0);

        $wallets = $this->service->getAllWallets();

        $this->assertIsArray($wallets);
        $this->assertGreaterThanOrEqual(4, count($wallets)); // 2 admin + 2 user
    }

    public function test_get_all_wallets_returns_required_keys(): void
    {
        $this->createUserWallet('0911111131', 500);

        $wallets = $this->service->getAllWallets();

        $this->assertNotEmpty($wallets);
        foreach ($wallets as $wallet) {
            $this->assertArrayHasKey('id', $wallet);
            $this->assertArrayHasKey('wallet_number', $wallet);
            $this->assertArrayHasKey('phone_number', $wallet);
            $this->assertArrayHasKey('balance', $wallet);
            $this->assertArrayHasKey('owner', $wallet);
            $this->assertArrayHasKey('created_at', $wallet);
        }
    }

    public function test_get_all_wallets_ordered_by_created_at_desc(): void
    {
        $w1 = $this->createUserWallet('0911111141', 0);
        $w2 = $this->createUserWallet('0911111142', 0);

        DB::table('wallets')
            ->where('id', $w1->id)
            ->update(['created_at' => now()->subDay()]);

        $wallets = $this->service->getAllWallets();

        $ids = array_column($wallets, 'id');
        $posW1 = array_search($w1->id, $ids);
        $posW2 = array_search($w2->id, $ids);

        $this->assertNotFalse($posW1);
        $this->assertNotFalse($posW2);
        $this->assertLessThan($posW1, $posW2);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────
    private function createUserWallet(string $phone, float $balance): Wallet
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => $phone,
            'wallet_number' => 'WLT'.rand(1000000, 9999999),
            'balance' => $balance,
        ]);

        $user->update(['wallet_id' => $wallet->id]);

        return $wallet;
    }
}
