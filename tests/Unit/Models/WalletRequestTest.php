<?php

namespace Tests\Unit\Models;

use App\Enums\WalletRequestStatus;
use App\Enums\WalletRequestType;
use App\Models\User;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletRequestTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_contains_user_id(): void
    {
        $this->assertContains('user_id', (new WalletRequest)->getFillable());
    }

    public function test_fillable_contains_type(): void
    {
        $this->assertContains('type', (new WalletRequest)->getFillable());
    }

    public function test_fillable_contains_amount(): void
    {
        $this->assertContains('amount', (new WalletRequest)->getFillable());
    }

    public function test_fillable_contains_status(): void
    {
        $this->assertContains('status', (new WalletRequest)->getFillable());
    }

    public function test_fillable_contains_the_notes_columns(): void
    {
        // Batch 229 - current truth: the notes columns are user_notes and admin_notes; there is
        // no plain `notes` column on wallet_requests.
        $fillable = (new WalletRequest)->getFillable();

        $this->assertContains('user_notes', $fillable);
        $this->assertContains('admin_notes', $fillable);
    }

    public function test_fillable_contains_processed_by(): void
    {
        $this->assertContains('processed_by', (new WalletRequest)->getFillable());
    }

    public function test_fillable_contains_processed_at(): void
    {
        $this->assertContains('processed_at', (new WalletRequest)->getFillable());
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_status_is_not_cast_so_the_raw_string_is_kept(): void
    {
        // Batch 229 - current truth: status and type are stored and read as their string values;
        // the model keeps amount/processed_at as its only casts and WalletRequestStatus /
        // WalletRequestType are used for their values, not as casts.
        $this->assertArrayNotHasKey('status', (new WalletRequest)->getCasts());
    }

    public function test_type_is_not_cast_so_the_raw_string_is_kept(): void
    {
        $this->assertArrayNotHasKey('type', (new WalletRequest)->getCasts());
    }

    public function test_amount_is_cast_to_decimal(): void
    {
        $casts = (new WalletRequest)->getCasts();
        $this->assertArrayHasKey('amount', $casts);
        $this->assertStringContainsString('decimal', $casts['amount']);
    }

    public function test_processed_at_is_cast_to_datetime(): void
    {
        $casts = (new WalletRequest)->getCasts();
        $this->assertArrayHasKey('processed_at', $casts);
        $this->assertEquals('datetime', $casts['processed_at']);
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function test_has_user_relationship(): void
    {
        $this->assertTrue(method_exists(WalletRequest::class, 'user'));
    }

    public function test_has_processor_or_processed_by_relationship(): void
    {
        $this->assertTrue(
            method_exists(WalletRequest::class, 'processor') ||
            method_exists(WalletRequest::class, 'processedBy')
        );
    }

    // ─── Persistence ──────────────────────────────────────────────────────────

    /**
     * A wallet request always belongs to a wallet.
     *
     * `wallet_requests.wallet_id` is NOT NULL + FK on purpose: the product rule is "a user must
     * have a wallet before requesting a top-up" - WalletRequestService::requestCharge and
     * ::requestWithdraw both refuse with 422 when `$user->wallet` is missing, and the admin approve
     * path credits `$request->wallet`. Owner ruling 2026-10-02 (un10) confirmed that rule, so the
     * schema stays; these fixtures, which inserted a request with no wallet at all, were the wrong
     * side of the invariant.
     */
    private function makeRequest(User $user, string $type, float $amount): WalletRequest
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 0,
        ]);

        return WalletRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'status' => WalletRequestStatus::PENDING->value,
        ]);
    }

    public function test_wallet_request_can_be_created_in_database(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest($user, WalletRequestType::TOP_UP->value, 50.00);

        $this->assertDatabaseHas('wallet_requests', [
            'id' => $request->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_status_is_read_back_as_the_stored_string(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest($user, WalletRequestType::TOP_UP->value, 25.00);

        $fresh = WalletRequest::find($request->id);

        $this->assertSame(WalletRequestStatus::PENDING->value, $fresh->status);
    }

    public function test_type_is_read_back_as_the_stored_string(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest($user, WalletRequestType::WITHDRAWAL->value, 30.00);

        $fresh = WalletRequest::find($request->id);

        $this->assertSame(WalletRequestType::WITHDRAWAL->value, $fresh->type);
    }

    public function test_user_relationship_returns_correct_user(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest($user, WalletRequestType::TOP_UP->value, 10.00);

        $this->assertEquals($user->id, $request->user->id);
    }

    public function test_processed_at_defaults_to_null(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest($user, WalletRequestType::TOP_UP->value, 20.00);

        $this->assertNull($request->processed_at);
    }
}
