<?php

namespace Tests\Unit\Domain;

use App\Models\User;
use App\Modules\Money\Domain\Strategies\CashPaymentStrategy;
use App\Modules\Money\Domain\Strategies\PaymentResult;
use App\Modules\Money\Domain\Strategies\PaymentStrategy;
use App\Modules\Money\Domain\Strategies\RefundResult;
use App\Modules\Money\Models\WalletTransaction;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashPaymentStrategyTest extends TestCase
{
    use RefreshDatabase;

    private CashPaymentStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CashPaymentStrategy;
    }

    // ── canProcess ──────────────────────────────────────────────────────────

    public function test_can_process_returns_true_for_cash(): void
    {
        $this->assertTrue($this->strategy->canProcess('cash'));
    }

    public function test_can_process_returns_false_for_epay(): void
    {
        $this->assertFalse($this->strategy->canProcess('e-pay'));
    }

    public function test_can_process_returns_false_for_empty_string(): void
    {
        $this->assertFalse($this->strategy->canProcess(''));
    }

    public function test_can_process_returns_false_for_unknown_method(): void
    {
        $this->assertFalse($this->strategy->canProcess('bitcoin'));
    }

    // ── getPaymentMethod ─────────────────────────────────────────────────────

    public function test_get_payment_method_returns_cash(): void
    {
        $this->assertEquals('cash', $this->strategy->getPaymentMethod());
    }

    // ── processBookingPayment ────────────────────────────────────────────────

    public function test_process_booking_payment_returns_payment_result_instance(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processBookingPayment($booking, $ride, $user);
        $this->assertInstanceOf(PaymentResult::class, $result);
    }

    public function test_process_booking_payment_returns_success(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processBookingPayment($booking, $ride, $user);
        $this->assertTrue($result->success);
    }

    public function test_process_booking_payment_message_mentions_offline(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processBookingPayment($booking, $ride, $user);
        $this->assertStringContainsString('offline', strtolower($result->message));
    }

    public function test_process_booking_payment_does_not_modify_wallet_balances(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        // Cash strategy should NOT touch any wallet — no exception should be thrown
        // and no WalletTransaction records should be created
        $before = WalletTransaction::count();
        $this->strategy->processBookingPayment($booking, $ride, $user);
        $this->assertEquals($before, WalletTransaction::count());
    }

    // ── processRefund ────────────────────────────────────────────────────────

    public function test_process_refund_returns_refund_result_instance(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processRefund($ride, $this->set($booking), 'driver_cancellation');
        $this->assertInstanceOf(RefundResult::class, $result);
    }

    public function test_process_refund_returns_success(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processRefund($ride, $this->set($booking), 'driver_cancellation');
        $this->assertTrue($result->success);
    }

    public function test_process_refund_message_mentions_offline(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $result = $this->strategy->processRefund($ride, $this->set($booking), 'driver_cancellation');
        $this->assertStringContainsString('offline', strtolower($result->message));
    }

    public function test_process_refund_does_not_create_wallet_transactions(): void
    {
        [$user, $booking, $ride] = $this->makeScenario();
        $before = WalletTransaction::count();
        $this->strategy->processRefund($ride, $this->set($booking), 'driver_cancellation');
        $this->assertEquals($before, WalletTransaction::count());
    }

    // ── Strategy implements interface ────────────────────────────────────────

    public function test_strategy_implements_payment_strategy_interface(): void
    {
        $this->assertInstanceOf(
            PaymentStrategy::class,
            $this->strategy
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeScenario(): array
    {
        $user = User::factory()->create();

        $booking = new Booking;
        $booking->id = 1;
        $booking->setAttribute('seats', 2);
        $booking->setAttribute('status', 'confirmed');

        $ride = new Ride;
        $ride->driver_id = $user->id;
        $ride->pickup_address = 'Damascus';
        $ride->destination_address = 'Aleppo';
        $ride->price_per_seat = 50000;
        $ride->available_seats = 4;
        $ride->payment_method = 'cash';
        $ride->booking_type = 'direct';

        return [$user, $booking, $ride];
    }

    /**
     * RV-20: refunds are SET-level, so the old tests that passed a single Booking now pass a
     * one-element set. Kept as a named helper rather than `new EloquentCollection([$b])` at each
     * call site so the unit of cancellation is obvious at every use.
     *
     * @return EloquentCollection<int,Booking>
     */
    private function set(Booking ...$bookings): EloquentCollection
    {
        return new EloquentCollection($bookings);
    }
}
