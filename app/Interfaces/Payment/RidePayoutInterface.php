<?php

namespace App\Interfaces\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;

/**
 * Port over the driver-payout / escrow-release step (batch 227). Implemented by
 * `App\Services\Payment\RidePayout`, which the domain strategies receive unchanged.
 */
interface RidePayoutInterface
{
    public function releaseEscrowToDriver(Booking $booking, Ride $ride, User $driver): void;
}
