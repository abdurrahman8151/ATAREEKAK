<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Payment Strategy Interface
 *
 * Three operations:
 *   processBookingPayment  – called when passenger books (escrow / hold)
 *   processRideCompletion  – called when THAT passenger confirms (release to driver)
 *   processRefund          – called when a SET of bookings is cancelled (escrow → passengers)
 */
interface PaymentStrategy
{
    /**
     * Charge the passenger at booking time.
     * For e-pay: deduct from passenger wallet → platform escrow.
     * For cash:  no-op (payment will be collected offline).
     */
    public function processBookingPayment(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): PaymentResult;

    /**
     * Release payment to the driver when a specific passenger confirms.
     *
     * Called ONCE PER BOOKING, not once per ride.
     * For e-pay: release this passenger's escrow share → driver wallet.
     * For cash:  no-op (driver already collected cash in person).
     */
    public function processRideCompletionPayment(
        Booking $booking,
        Ride $ride,
        User $driver,
    ): PaymentResult;

    /**
     * Refund a CANCELLED SET OF BOOKINGS.
     *
     * RV-20: this was `processRefund(Booking, Ride, User)` - ONE booking at a time - and that shape
     * matches NEITHER real refund flow, so nothing could call it correctly. Both real paths are
     * SET-LEVEL and both take the whole set at once:
     *
     *   - driver cancellation  → 100% of every confirmed passenger
     *   - staff cancellation   → policy-based, partial, `amount_paid` snapshot
     *
     * Calling either of those with a one-element collection would have been quietly wrong: the driver
     * path refunds that passenger 100% when the truth may be a policy partial, and both would post one
     * ledger row PER BOOKING where the real path posts ONE combined row for the set, breaking the
     * aggregate SyCash guard and the set-scoped `posting_key` idempotency. Sec 87 pinned all three of
     * those properties before this signature existed, precisely so a naive rewire could not ship green.
     *
     * So the reason is a REQUIRED argument, not a hint: it selects which real refund path runs, and an
     * unrecognised reason is REFUSED rather than guessed. Guessing here would mean moving money on the
     * wrong basis.
     *
     * `processDriverNoShowRefund` is deliberately NOT folded in: a no-show penalty is per-booking by
     * nature and is not a cancellation.
     *
     * @param  EloquentCollection<int,Booking>  $bookings  the set being cancelled, all of it
     * @param  string  $reason  'driver_cancellation' | 'staff_cancellation'
     */
    public function processRefund(
        Ride $ride,
        EloquentCollection $bookings,
        string $reason,
    ): RefundResult;

    public function canProcess(string $paymentMethod): bool;

    public function getPaymentMethod(): string;
}
