<?php

namespace Tests\Feature\Review;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentStrategy;
use App\Models\Booking;
use App\Models\Ride;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;
use Tests\TestCase;

/**
 * RV-20 refund half: `processRefund` is now SET-LEVEL `(Ride, Collection, $reason)`.
 *
 * WHY THIS FILE EXISTS. Before RV-20 the signature was `processRefund(Booking, Ride, User)` and
 * `EPayPaymentStrategy` implemented it by wrapping the single booking in a one-element collection and
 * calling `refundPassengersForDriverCancellation` - the DRIVER-cancellation path, which refunds 100%
 * of every confirmed passenger. So a staff cancellation routed through this strategy would have
 * refunded one passenger in full instead of the policy amount off the `amount_paid` snapshot.
 *
 * It was never called in production, which is the only reason that never cost anyone money. These
 * tests exist so that a future per-booking rewire cannot ship green again.
 *
 * `R2 sec 87` already pinned the set-level semantics (aggregate SyCash guard, set-scoped idempotency,
 * one ledger row per set) at the wallet-service layer. This file pins the thing sec 87 could not see:
 * WHICH wallet path the strategy selects for a given reason.
 *
 * No database: the wallet service is mocked, so both paths are asserted by CALL, not by money
 * outcome. That is deliberate - the money arithmetic is `WalletTransactionService`'s to get right and
 * is covered there; here we only care that the right one is invoked.
 */
class RV20RefundReasonRoutingTest extends TestCase
{
    private function set(): EloquentCollection
    {
        return new EloquentCollection([new Booking(['seats' => 2, 'amount_paid' => 100.0])]);
    }

    private function ride(): Ride
    {
        $ride = new Ride(['price_per_seat' => 100.0]);
        $ride->id = 77;

        return $ride;
    }

    private function staffSummary(array $over = []): array
    {
        return array_merge([
            'refunded' => 150.0,
            'bookings' => 2,
            'needs_review' => 0,
            'amount_source' => 'amount_paid',
        ], $over);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── The regression guard: the two reasons must not be interchangeable ──────

    public function test_staff_cancellation_never_takes_the_driver_cancellation_path(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        // The whole point: this must NOT be reached for a staff cancellation.
        $wallet->shouldReceive('refundPassengersForDriverCancellation')->never();
        $wallet->shouldReceive('refundPassengersForStaffCancellation')
            ->once()
            ->andReturn($this->staffSummary());

        (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $this->set(), 'staff_cancellation');

        $this->addToAssertionCount(1); // the never()/once() expectations are the assertions
    }

    public function test_driver_cancellation_takes_the_driver_path_and_not_the_staff_path(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        $wallet->shouldReceive('refundPassengersForDriverCancellation')->once();
        $wallet->shouldReceive('refundPassengersForStaffCancellation')->never();

        (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $this->set(), 'driver_cancellation');

        $this->addToAssertionCount(1);
    }

    // ── The whole SET is handed over, not one booking ─────────────────────────

    public function test_the_entire_set_is_passed_through_not_a_single_booking(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);

        $two = new EloquentCollection([
            new Booking(['seats' => 1, 'amount_paid' => 50.0]),
            new Booking(['seats' => 3, 'amount_paid' => 150.0]),
        ]);

        $wallet->shouldReceive('refundPassengersForDriverCancellation')
            ->once()
            ->with(Mockery::any(Ride::class), $two);

        (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $two, 'driver_cancellation');

        $this->addToAssertionCount(1);
    }

    // ── An unknown reason must REFUSE, never default ──────────────────────────

    public function test_an_unknown_reason_refuses_instead_of_defaulting_to_the_driver_path(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        // If a caller passes a reason this strategy does not know, guessing would mean refunding
        // 100% of someone's money on the wrong basis.
        $wallet->shouldReceive('refundPassengersForDriverCancellation')->never();
        $wallet->shouldReceive('refundPassengersForStaffCancellation')->never();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Refusing rather than guessing/');

        (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $this->set(), 'something_else');
    }

    public function test_a_reason_only_guessable_from_typo_does_not_refund_at_all(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        $wallet->shouldNotReceive('refundPassengersForDriverCancellation');
        $wallet->shouldNotReceive('refundPassengersForStaffCancellation');

        try {
            (new EPayPaymentStrategy($wallet))
                ->processRefund($this->ride(), $this->set(), 'driver_cancelation'); // typo
            $this->fail('a misspelled reason must not refund anything');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    // ── Empty set is a no-op, not a call with nothing in it ──────────────────

    public function test_an_empty_set_refunds_nothing(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        $wallet->shouldReceive('refundPassengersForDriverCancellation')->never();
        $wallet->shouldReceive('refundPassengersForStaffCancellation')->never();

        $result = (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), new EloquentCollection, 'driver_cancellation');

        $this->assertTrue($result->success);
    }

    // ── Staff summary is surfaced, including the manual-review count ──────────

    public function test_staff_cancellation_reports_the_amount_and_booking_count(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        $wallet->shouldReceive('refundPassengersForStaffCancellation')
            ->once()
            ->andReturn($this->staffSummary(['refunded' => 1234.5, 'bookings' => 7]));

        $result = (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $this->set(), 'staff_cancellation');

        $this->assertStringContainsString('1234.50', $result->message);
        $this->assertStringContainsString('7 booking', $result->message);
    }

    public function test_rows_needing_manual_review_are_surfaced_not_swallowed(): void
    {
        $wallet = Mockery::mock(WalletTransactionService::class);
        // Charged before the `amount_paid` snapshot existed. Silently refunding them would fabricate
        // an amount from today's price - the exact failure RV-40 warns about - so the count is
        // reported to the caller.
        $wallet->shouldReceive('refundPassengersForStaffCancellation')
            ->once()
            ->andReturn($this->staffSummary(['needs_review' => 3]));

        $result = (new EPayPaymentStrategy($wallet))
            ->processRefund($this->ride(), $this->set(), 'staff_cancellation');

        $this->assertStringContainsString('3 booking(s) need manual review', $result->message);
    }

    // ── Cash: still a no-op, and it must not move money either ────────────────

    public function test_cash_refund_moves_no_money_for_either_reason(): void
    {
        $strategy = new CashPaymentStrategy;

        foreach (['driver_cancellation', 'staff_cancellation'] as $reason) {
            $result = $strategy->processRefund($this->ride(), $this->set(), $reason);
            $this->assertTrue($result->success);
        }
    }

    // ── The interface itself, so the shape cannot drift back ─────────────────

    public function test_the_interface_signature_is_set_level(): void
    {
        $method = new \ReflectionMethod(PaymentStrategy::class, 'processRefund');
        $params = $method->getParameters();

        $this->assertCount(3, $params, 'processRefund must take exactly (ride, set, reason)');
        $this->assertSame('ride', $params[0]->getName());
        $this->assertSame('bookings', $params[1]->getName());
        $this->assertSame('reason', $params[2]->getName());
        $this->assertTrue($params[2]->isOptional() === false, 'the reason is required, never guessed');
    }
}
