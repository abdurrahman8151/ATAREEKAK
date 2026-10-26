<?php

namespace App\Interfaces\Payment;

use App\Models\Ride;
use Illuminate\Database\Eloquent\Collection;

/**
 * Port over the two cancellation-refund paths (batch 227). Implemented by
 * `App\Services\Payment\CancellationRefund`; the return shapes match that service exactly
 * (`refundPassengersForStaffCancellation()` returns the refund summary array).
 */
interface CancellationRefundInterface
{
    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void;

    public function refundPassengersForStaffCancellation(Ride $ride, Collection $bookings): array;
}
