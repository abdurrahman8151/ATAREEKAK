<?php

namespace App\Modules\Money\Contracts;

use App\Modules\Ride\Contracts\BookingView;
use App\Modules\Ride\Contracts\RideView;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Port over the cancellation-refund paths (batch 227; the time-based policy and per-booking
 * refund entry points were added for `BookingCancellationService` in batch 233). Implemented by
 * `App\Modules\Money\Services\CancellationRefund`; the return shapes match that service exactly
 * (`refundPassengersForStaffCancellation()` returns the refund summary array).
 */
interface CancellationRefundInterface
{
    public function refundPassengersForDriverCancellation(RideView $ride, Collection $bookings): void;

    public function refundPassengersForStaffCancellation(RideView $ride, Collection $bookings): array;

    /** Elapsed-time refund tier for a passenger cancellation. */
    public function calculateRefundPolicy(Carbon $departureTime, Carbon $bookingCreatedAt): array;

    /** Time-based cancellation: SyCash -> passenger (refund%) + SyCash -> driver (rest). */
    public function processTimeBasedCancellation(
        BookingView $booking,
        RideView $ride,
        int $seatsCancelled,
        array $refundPolicy
    ): void;
}
