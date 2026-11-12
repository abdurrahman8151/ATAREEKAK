<?php

namespace App\Modules\Money\Contracts;

use App\Models\User;

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
    public function chargePassengerForBooking($booking, $ride, User $passenger): void;
}
