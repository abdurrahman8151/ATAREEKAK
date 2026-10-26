<?php

namespace App\Interfaces\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;

/**
 * Port over the passenger-charge step of the payment pipeline (batch 227).
 *
 * `App\Domain\Payment\Strategies\EPayPaymentStrategy` used to type-hint the concrete
 * `App\Services\Payment\BookingCharge`, which is what the `domain_to_services` boundary counts. The
 * port is implemented by that same service and bound to it in `ContainerBindings`, so the strategy
 * still receives the identical singleton.
 */
interface BookingChargeInterface
{
    public function chargePassengerForBooking(Booking $booking, Ride $ride, User $passenger): void;
}
