<?php

namespace Tests\Unit\Models;

use App\Enums\WalletRequestStatus;
use App\Enums\WalletRequestType;
use App\Models\User;
use App\Models\WalletRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_fillable_contains_notes(): void
    {
        $this->assertContains('notes', (new WalletRequest)->getFillable());
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

    public function test_status_is_cast_to_wallet_request_status_enum(): void
    {
        $casts = (new WalletRequest)->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertEquals(WalletRequestStatus::class, $casts['status']);
    }

    public function test_type_is_cast_to_wallet_request_type_enum(): void
    {
        $casts = (new WalletRequest)->getCasts();
        $this->assertArrayHasKey('type', $casts);
        $this->assertEquals(WalletRequestType::class, $casts['type']);
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

    public function test_wallet_request_can_be_created_in_database(): void
    {
        $user = User::factory()->create();
        $request = WalletRequest::create([
            'user_id' => $user->id,
            'type' => WalletRequestType::TOP_UP->value,
            'amount' => 50.00,
            'status' => WalletRequestStatus::PENDING->value,
        ]);

        $this->assertDatabaseHas('wallet_requests', [
            'id' => $request->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_status_is_cast_to_enum_on_retrieval(): void
    {
        $user = User::factory()->create();
        $request = WalletRequest::create([
            'user_id' => $user->id,
            'type' => WalletRequestType::TOP_UP->value,
            'amount' => 25.00,
            'status' => WalletRequestStatus::PENDING->value,
        ]);

        $fresh = WalletRequest::find($request->id);

        $this->assertInstanceOf(WalletRequestStatus::class, $fresh->status);
        $this->assertEquals(WalletRequestStatus::PENDING, $fresh->status);
    }

    public function test_type_is_cast_to_enum_on_retrieval(): void
    {
        $user = User::factory()->create();
        $request = WalletRequest::create([
            'user_id' => $user->id,
            'type' => WalletRequestType::WITHDRAWAL->value,
            'amount' => 30.00,
            'status' => WalletRequestStatus::PENDING->value,
        ]);

        $fresh = WalletRequest::find($request->id);

        $this->assertInstanceOf(WalletRequestType::class, $fresh->type);
        $this->assertEquals(WalletRequestType::WITHDRAWAL, $fresh->type);
    }

    public function test_user_relationship_returns_correct_user(): void
    {
        $user = User::factory()->create();
        $request = WalletRequest::create([
            'user_id' => $user->id,
            'type' => WalletRequestType::TOP_UP->value,
            'amount' => 10.00,
            'status' => WalletRequestStatus::PENDING->value,
        ]);

        $this->assertEquals($user->id, $request->user->id);
    }

    public function test_processed_at_defaults_to_null(): void
    {
        $user = User::factory()->create();
        $request = WalletRequest::create([
            'user_id' => $user->id,
            'type' => WalletRequestType::TOP_UP->value,
            'amount' => 20.00,
            'status' => WalletRequestStatus::PENDING->value,
        ]);

        $this->assertNull($request->processed_at);
    }
}
