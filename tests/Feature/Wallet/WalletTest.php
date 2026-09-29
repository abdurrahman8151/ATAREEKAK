<?php

namespace Tests\Feature\Wallet;

use App\Models\Otp;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $user;

    private string $token;

    private string $testPhone; // unique per test — prevents duplicate key errors

    protected function setUp(): void
    {
        parent::setUp();

        // delete() is DML (transactional), truncate() is DDL (commits transaction)
        // truncate() was breaking RefreshDatabase's transaction wrapping
        Otp::query()->delete();

        $this->testPhone = '09'.rand(10000000, 99999999);

        $this->user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->seedSystemWallets(10_000_000);
        $this->token = $this->getToken($this->user);
    }

    // ── Initiate ──────────────────────────────────────────────────────────────

    public function test_can_initiate_wallet_creation(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => $this->testPhone,
                'password' => 'password123',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_initiate_returns_otp_in_testing_mode(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => $this->testPhone,
                'password' => 'password123',
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('otp_code'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('otp_code'));
    }

    public function test_initiate_fails_with_wrong_password(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => $this->testPhone,
                'password' => 'wrong_password',
            ])
            ->assertStatus(401);
    }

    public function test_initiate_fails_if_wallet_already_exists(): void
    {
        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'phone_number' => $this->testPhone,
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 0,
        ]);
        $this->user->update(['wallet_id' => $wallet->id]);

        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '09'.rand(10000000, 99999999), // different phone
                'password' => 'password123',
            ]);

        // Wallet controller returns 409 when wallet already exists for the user
        $this->assertContains($response->status(), [409, 422]);
    }

    public function test_initiate_fails_with_duplicate_phone(): void
    {
        $duplicatePhone = '09'.rand(10000000, 99999999);

        $other = User::factory()->create();
        Wallet::create([
            'user_id' => $other->id,
            'phone_number' => $duplicatePhone,
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 0,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => $duplicatePhone,
                'password' => 'password123',
            ]);

        // phone_number has unique:wallets constraint → validation returns 422
        $response->assertStatus(422);
    }

    // ── Verify and create ─────────────────────────────────────────────────────

    public function test_can_create_wallet_after_otp_verification(): void
    {
        $initResponse = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => $this->testPhone,
                'password' => 'password123',
            ]);

        $initResponse->assertStatus(200);
        $otp = $initResponse->json('otp_code');
        $this->assertNotNull($otp);

        $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => $this->testPhone,
                'otp_code' => $otp,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('wallets', ['user_id' => $this->user->id]);
    }

    public function test_wallet_creation_fails_with_wrong_otp(): void
    {
        $this->withToken($this->token)->postJson('/api/wallet/initiate', [
            'phone_number' => $this->testPhone,
            'password' => 'password123',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => $this->testPhone,
                'otp_code' => '000000',
            ])
            ->assertStatus(400);
    }

    // ── Balance ───────────────────────────────────────────────────────────────

    public function test_can_check_wallet_balance(): void
    {
        $balancePhone = '09'.rand(10000000, 99999999);

        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'phone_number' => $balancePhone,
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 500_000,
        ]);
        $this->user->update(['wallet_id' => $wallet->id]);

        $this->withToken($this->token)->getJson('/api/wallet/balance')
            ->assertStatus(200)
            ->assertJsonStructure(['balance', 'wallet_number']);
    }

    public function test_balance_check_fails_without_wallet(): void
    {
        $this->withToken($this->token)->getJson('/api/wallet/balance')
            ->assertStatus(404);
    }

    public function test_balance_requires_authentication(): void
    {
        $this->getJson('/api/wallet/balance')->assertStatus(401);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
