<?php

namespace Tests\Unit\Services;

use App\Services\Payment\WalletTransactionService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * WalletTransactionServiceRefundPolicyTest
 *
 * Tests ONLY the calculateRefundPolicy() method — pure logic, no DB needed.
 *
 * HOW THIS TEST WORKS:
 * We instantiate the real service and call calculateRefundPolicy() with
 * different Carbon dates to simulate different elapsed percentages.
 * No mocking needed because this method has zero dependencies.
 */
class WalletTransactionServiceRefundPolicyTest extends TestCase
{
    private WalletTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WalletTransactionService(new \App\Repositories\PolicyRepository());
    }

    // ─── Tier 1: 0–30% elapsed → 100% refund ────────────────────────────────

    /**
     * Passenger booked just now, departure is far away.
     * Should get full refund.
     */
    public function test_full_refund_when_just_booked(): void
    {
        $bookingCreatedAt = Carbon::now()->subMinutes(5);
        $departureTime    = Carbon::now()->addHours(48); // 2 days away

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(100, $policy['refund_percentage']);
        $this->assertStringContainsString('Full refund', $policy['policy_tier']);
    }

    public function test_full_refund_at_exactly_25_percent_elapsed(): void
    {
        // Total window = 100 minutes, elapsed = 25 minutes = 25%
        $bookingCreatedAt = Carbon::now()->subMinutes(25);
        $departureTime    = Carbon::now()->addMinutes(75);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(100, $policy['refund_percentage']);
    }

    // ─── Tier 2: 30–50% elapsed → 70% refund ────────────────────────────────

    public function test_70_percent_refund_at_40_percent_elapsed(): void
    {
        // Total window = 100 minutes, elapsed = 40 minutes = 40%
        $bookingCreatedAt = Carbon::now()->subMinutes(40);
        $departureTime    = Carbon::now()->addMinutes(60);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(70, $policy['refund_percentage']);
        $this->assertStringContainsString('30–50%', $policy['policy_tier']);
    }

    // ─── Tier 3: 50–70% elapsed → 50% refund ────────────────────────────────

    public function test_50_percent_refund_at_60_percent_elapsed(): void
    {
        // Total window = 100 minutes, elapsed = 60 minutes = 60%
        $bookingCreatedAt = Carbon::now()->subMinutes(60);
        $departureTime    = Carbon::now()->addMinutes(40);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(50, $policy['refund_percentage']);
        $this->assertStringContainsString('50–70%', $policy['policy_tier']);
    }

    // ─── Tier 4: 70–100% elapsed → 0% refund ────────────────────────────────

    public function test_no_refund_at_80_percent_elapsed(): void
    {
        // Total window = 100 minutes, elapsed = 80 minutes = 80%
        $bookingCreatedAt = Carbon::now()->subMinutes(80);
        $departureTime    = Carbon::now()->addMinutes(20);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(0, $policy['refund_percentage']);
        $this->assertStringContainsString('70–100%', $policy['policy_tier']);
    }

    // ─── Edge cases ───────────────────────────────────────────────────────────

    public function test_no_refund_when_departure_already_passed(): void
    {
        $bookingCreatedAt = Carbon::now()->subHours(5);
        $departureTime    = Carbon::now()->subHours(1); // already departed

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEquals(0, $policy['refund_percentage']);
        $this->assertEquals(100, $policy['time_elapsed_percentage']);
    }

    public function test_policy_includes_elapsed_percentage(): void
    {
        $bookingCreatedAt = Carbon::now()->subMinutes(50);
        $departureTime    = Carbon::now()->addMinutes(50);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertArrayHasKey('time_elapsed_percentage', $policy);
        $this->assertArrayHasKey('refund_percentage', $policy);
        $this->assertArrayHasKey('policy_tier', $policy);
        $this->assertArrayHasKey('total_minutes_from_booking', $policy);
    }

    public function test_elapsed_percentage_is_approximately_50(): void
    {
        $bookingCreatedAt = Carbon::now()->subMinutes(50);
        $departureTime    = Carbon::now()->addMinutes(50);

        $policy = $this->service->calculateRefundPolicy($departureTime, $bookingCreatedAt);

        $this->assertEqualsWithDelta(50.0, $policy['time_elapsed_percentage'], 2.0);
    }
}
