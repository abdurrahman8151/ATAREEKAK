<?php

namespace App\Modules\Money\Contracts;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Port over the driver-payout / escrow-release steps (batch 227; the whole-set release was added
 * for `RideCompletionService` in batch 233). Implemented by `App\Services\Payment\RidePayout`,
 * which the domain strategies and the ride services receive unchanged.
 */
interface RidePayoutInterface
{
    /** Release escrow for ONE passenger on that passenger's confirmation. */
    public function releaseEscrowToDriver(Booking $booking, Ride $ride, User $driver): void;

    /** Release escrow for the WHOLE confirmed set after a ride completes. */
    public function releaseEarningsToDriver(Ride $ride, Collection $confirmedBookings): void;
}
