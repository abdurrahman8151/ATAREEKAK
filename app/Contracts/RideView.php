<?php

namespace App\Contracts;

use App\Models\User;
use Carbon\Carbon;

/**
 * Stage 3 A2: the READ-ONLY view of a ride that another module may type against.
 *
 * The same story as {@see BookingView}: Money's pricing, cancellation, no-show and payout paths all read a ride,
 * and the Ride model's move into the Ride module's own models namespace made naming it from Money an ownership violation, so
 * the hints were dropped. This contract restores the type without the model, and Ride's `Ride` implements it.
 *
 * The members are exactly the ones Money reads (measured, not guessed): id, the per-seat price, the two
 * addresses, the driver and its id, the two cash-fee columns, the payment method and the two timestamps.
 *
 * @property-read int $id
 * @property-read mixed $price_per_seat
 * @property-read string|null $pickup_address
 * @property-read string|null $destination_address
 * @property-read int|null $driver_id
 * @property-read User|null $driver
 * @property-read mixed $cash_creation_fee
 * @property-read bool $cash_fee_deferred
 * @property-read string|null $payment_method
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $departure_time
 */
interface RideView {}
