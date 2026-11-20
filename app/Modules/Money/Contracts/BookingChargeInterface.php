<?php

namespace App\Modules\Money\Contracts;

use App\DTOs\Money\BookingMoneySnapshot;
use App\Models\User;
use App\Modules\Ride\Contracts\BookingView;
use App\Modules\Ride\Contracts\RideView;

/**
 * Port over the passenger-charge step of the payment pipeline (batch 227).
 *
 * `App\Modules\Money\Domain\Strategies\EPayPaymentStrategy` used to type-hint the concrete
 * `App\Modules\Money\Services\BookingCharge`, which is what the `domain_to_services` boundary counts. The
 * port is implemented by that same service and bound to it in `ContainerBindings`, so the strategy
 * still receives the identical singleton.
 */
interface BookingChargeInterface
{
    /**
     * Charge the passenger and RETURN the booking's money snapshot - the caller's module owns `bookings` and
     * persists it (stage 4 A2: the owner of a row is the only writer of it).
     */
    public function chargePassengerForBooking(BookingView $booking, RideView $ride, User $passenger): BookingMoneySnapshot;
}
