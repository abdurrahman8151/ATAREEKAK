<?php

namespace Tests\Feature\Wallet;

use App\Enums\WalletRequestStatus;
use App\Enums\WalletRequestType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => bcrypt('password123')]);
        // un10 (owner ruling 2026-10-02): a wallet request always belongs to a wallet, so the
        // user under test owns one. The API path itself already enforces this - see
        // WalletRequestService::requestCharge, which refuses with 422 when `$user->wallet` is
        // missing - and `wallet_requests.wallet_id` is NOT NULL. These fixtures were inserting
        // requests with no wallet, which is the side of the invariant the owner ruled wrong.
        $this->ensureWallet($this->user);
        $this->token = $this->getToken($this->user);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/wallet/requests', [])->assertStatus(401);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/wallet/requests')->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/wallet/requests/1')->assertStatus(401);
    }

    // ─── store() ──────────────────────────────────────────────────────────────

    public function test_user_can_submit_a_top_up_request(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => 100.00,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_user_can_submit_a_withdrawal_request(): void
    {
        // A withdrawal is only valid against real funds: WalletRequestService::requestWithdraw
        // checks `pendingTotal + amount <= balance` under a row lock. The wallet created in
        // setUp() starts empty, so fund it here rather than weakening the check.
        $this->ensureWallet($this->user)->update(['balance' => 500.00]);

        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::WITHDRAWAL->value,
                'amount' => 50.00,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_new_wallet_request_is_persisted_to_database(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => 75.00,
            ]);

        $this->assertDatabaseHas('wallet_requests', [
            'user_id' => $this->user->id,
            'amount' => 75.00,
        ]);
    }

    public function test_new_request_is_created_with_pending_status(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => 50.00,
            ]);

        $this->assertDatabaseHas('wallet_requests', [
            'user_id' => $this->user->id,
            'status' => WalletRequestStatus::PENDING->value,
        ]);
    }

    public function test_response_includes_wallet_request_structure(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => 200.00,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'data']);
    }

    public function test_store_fails_with_missing_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', ['amount' => 100.00])
            ->assertStatus(422);
    }

    public function test_store_fails_with_missing_amount(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', ['type' => WalletRequestType::TOP_UP->value])
            ->assertStatus(422);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => 'not_a_valid_type',
                'amount' => 100.00,
            ])
            ->assertStatus(422);
    }

    public function test_store_fails_with_zero_amount(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_store_fails_with_negative_amount(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/requests', [
                'type' => WalletRequestType::TOP_UP->value,
                'amount' => -50.00,
            ])
            ->assertStatus(422);
    }

    // ─── index() ──────────────────────────────────────────────────────────────

    public function test_user_can_list_own_wallet_requests(): void
    {
        WalletRequest::create($this->walletRequestData());

        $this->withToken($this->token)
            ->getJson('/api/wallet/requests')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_index_returns_empty_when_no_requests(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/wallet/requests');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_index_excludes_other_users_requests(): void
    {
        $other = User::factory()->create();

        WalletRequest::create($this->walletRequestData(['user_id' => $this->user->id]));
        WalletRequest::create($this->walletRequestData(['user_id' => $other->id]));

        $response = $this->withToken($this->token)->getJson('/api/wallet/requests');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_returns_multiple_own_requests(): void
    {
        WalletRequest::create($this->walletRequestData());
        WalletRequest::create($this->walletRequestData(['type' => WalletRequestType::WITHDRAWAL->value]));

        $response = $this->withToken($this->token)->getJson('/api/wallet/requests');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    // ─── show() ───────────────────────────────────────────────────────────────

    public function test_user_can_view_own_wallet_request(): void
    {
        $request = WalletRequest::create($this->walletRequestData());

        $this->withToken($this->token)
            ->getJson("/api/wallet/requests/{$request->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_user_cannot_view_another_users_request(): void
    {
        $other = User::factory()->create();
        $request = WalletRequest::create($this->walletRequestData(['user_id' => $other->id]));

        $this->withToken($this->token)
            ->getJson("/api/wallet/requests/{$request->id}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_for_nonexistent_request(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/wallet/requests/999999')
            ->assertStatus(404);
    }

    public function test_show_returns_correct_request_id(): void
    {
        $request = WalletRequest::create($this->walletRequestData());

        $response = $this->withToken($this->token)
            ->getJson("/api/wallet/requests/{$request->id}");

        $response->assertStatus(200);
        $this->assertEquals($request->id, $response->json('data.id'));
    }

    // ─── cancel() ─────────────────────────────────────────────────────────────

    public function test_user_can_cancel_a_pending_request(): void
    {
        $request = WalletRequest::create($this->walletRequestData());

        $this->withToken($this->token)
            ->deleteJson("/api/wallet/requests/{$request->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('wallet_requests', [
            'id' => $request->id,
            'status' => WalletRequestStatus::CANCELLED->value,
        ]);
    }

    public function test_user_cannot_cancel_an_approved_request(): void
    {
        $request = WalletRequest::create($this->walletRequestData([
            'status' => WalletRequestStatus::APPROVED->value,
        ]));

        $this->withToken($this->token)
            ->deleteJson("/api/wallet/requests/{$request->id}")
            ->assertStatus(422);
    }

    public function test_user_cannot_cancel_another_users_request(): void
    {
        $other = User::factory()->create();
        $request = WalletRequest::create($this->walletRequestData(['user_id' => $other->id]));

        $this->withToken($this->token)
            ->deleteJson("/api/wallet/requests/{$request->id}")
            ->assertStatus(404);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function walletRequestData(array $overrides = []): array
    {
        // The wallet belongs to whichever user the request is for, so a request built for a
        // second user does not point at the first user's wallet.
        $user = $overrides['user_id'] ?? null
            ? User::find($overrides['user_id'])
            : $this->user;

        return array_merge([
            'user_id' => $user->id,
            'wallet_id' => $this->ensureWallet($user)->id,
            'type' => WalletRequestType::TOP_UP->value,
            'amount' => 100.00,
            'status' => WalletRequestStatus::PENDING->value,
        ], $overrides);
    }

    /** Create (once) and return the user's wallet, mirroring the real signup flow. */
    private function ensureWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'phone_number' => '09'.random_int(10000000, 99999999),
                'wallet_number' => 'WLT-'.Str::random(8),
                'balance' => 0,
            ]
        );
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
