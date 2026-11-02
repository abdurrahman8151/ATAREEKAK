<?php

namespace App\Modules\Money\Contracts;

use App\Models\Booking;
use App\Models\Ride;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Port over the cancellation-refund paths (batch 227; the time-based policy and per-booking
 * refund entry points were added for `BookingCancellationService` in batch 233). Implemented by
 * `App\Services\Payment\CancellationRefund`; the return shapes match that service exactly
 * (`refundPassengersForStaffCancellation()` returns the refund summary array).
 */
interface CancellationRefundInterface
{
    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void;

    public function refundPassengersForStaffCancellation(Ride $ride, Collection $bookings): array;

    /** Elapsed-time refund tier for a passenger cancellation. */
    public function calculateRefundPolicy(Carbon $departureTime, Carbon $bookingCreatedAt): array;

    /** Time-based cancellation: SyCash -> passenger (refund%) + SyCash -> driver (rest). */
    public function processTimeBasedCancellation(
        Booking $booking,
        Ride $ride,
        int $seatsCancelled,
        array $refundPolicy
    ): void;
}
