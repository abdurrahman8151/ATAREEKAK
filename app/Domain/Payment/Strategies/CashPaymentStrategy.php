<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    /**
     * RV-20: set-level, matching the real refund flows. Cash was never escrowed, so there is nothing
     * to move back - the passenger's money was never taken - and this stays a recorded no-op.
     *
     * It logs the SET, not a single booking, because the unit of cancellation is the set: logging one
     * booking id per call would have implied this ran per-passenger, which is exactly the shape
     * `R2 sec 87` pinned as wrong.
     */
    public function processRefund(
        Ride $ride,
        EloquentCollection $bookings,
        string $reason,
    ): RefundResult {
        Log::info('Cash refund recorded – will be processed offline', [
            'ride_id' => $ride->id,
            'reason' => $reason,
            'booking_ids' => $bookings->pluck('id')->all(),
            'booking_count' => $bookings->count(),
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
