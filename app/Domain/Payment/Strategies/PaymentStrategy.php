<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;

/**
 * Payment Strategy Interface
 *
 * Three operations:
 *   processBookingPayment  – called when passenger books (escrow / hold)
 *   processRideCompletion  – called when THAT passenger confirms (release to driver)
 *   processRefund          – called when a booking is cancelled
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
     * Refund a cancelled booking.
     */
    public function processRefund(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): RefundResult;

    public function canProcess(string $paymentMethod): bool;

    public function getPaymentMethod(): string;
}
