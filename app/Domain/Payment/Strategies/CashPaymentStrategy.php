<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Cash Payment Strategy  (offline)
 *
 * Money flow:
 *   booking  → no-op   (5% creation fee already deducted from driver at ride creation)
 *   confirm  → no-op   (driver collected cash in person; nothing digital to release)
 *   cancel   → no-op   (refund handled offline)
 */
final class CashPaymentStrategy implements PaymentStrategy
{
    // ── Book ─────────────────────────────────────────────────────────────────

    public function processBookingPayment(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): PaymentResult {
        Log::info('Cash booking recorded – payment will be collected offline', [
            'booking_id' => $booking->id,
            'ride_id' => $ride->id,
            'passenger_id' => $passenger->id,
            'amount' => $booking->seats * $ride->price_per_seat,
        ]);

        return PaymentResult::success('Cash payment will be collected offline');
    }

    // ── Confirm (per-passenger) ──────────────────────────────────────────────

    /**
     * Cash rides: driver already has the money from the passenger.
     * The creation fee was taken from the driver's wallet when the ride was
     * created (CashRideFeeService). Nothing more to do digitally.
     */
    public function processRideCompletionPayment(
        Booking $booking,
        Ride $ride,
        User $driver,
    ): PaymentResult {
        Log::info('Cash ride completion acknowledged – no digital transfer required', [
            'booking_id' => $booking->id,
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
        ]);

        return PaymentResult::success('Cash ride completed – no digital transfer required');
    }

    // ── Refund ───────────────────────────────────────────────────────────────

    public function processRefund(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): RefundResult {
        Log::info('Cash refund recorded – will be processed offline', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
        ]);

        return RefundResult::success('Cash refund will be processed offline');
    }

    // ── Meta ─────────────────────────────────────────────────────────────────

    public function canProcess(string $paymentMethod): bool
    {
        return $paymentMethod === 'cash';
    }

    public function getPaymentMethod(): string
    {
        return 'cash';
    }
}
