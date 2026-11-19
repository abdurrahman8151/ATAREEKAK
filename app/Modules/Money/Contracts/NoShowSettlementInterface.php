<?php

namespace App\Modules\Money\Contracts;

use App\Models\User;
use App\Modules\Ride\Contracts\BookingView;
use App\Modules\Ride\Contracts\RideView;

/**
 * Port over the no-show settlement paths (batch 233).
 *
 * RIDE decides WHEN a no-show is settled; MONEY owns HOW the money moves. Before this port
 * `NoShowPenaltyService` and `Noshowservice` type-hinted `App\Modules\Money\Services\NoShowSettlement`
 * directly. Implemented by that same service and bound to its singleton, so the object the
 * ride services receive is the identical instance they received before.
 */
interface NoShowSettlementInterface
{
    /** Passenger did not show on an e-pay ride: SyCash -> driver (95%) + platform (5%). */
    public function processPassengerNoShow(BookingView $booking, RideView $ride, User $passenger): void;

    /** Driver did not show on an e-pay ride: SyCash -> passenger (100% refund). */
    public function processDriverNoShowRefund(RideView $ride, BookingView $booking, User $passenger): void;
}
