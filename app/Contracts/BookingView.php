<?php

namespace App\Contracts;

/**
 * Stage 3 A2: the READ-ONLY view of a booking that another module may type against.
 *
 * Why this exists: Money's services take a booking in nearly every money path (`BookingCharge`,
 * `CancellationRefund`, `NoShowSettlement`, `RidePayout`, the payment strategies). When the Booking MODEL moved
 * into Ride's model namespace (Stage 2 C2), naming it from Money became an ownership violation, so the type
 * hints were dropped and 113 parameters went untyped - the dependency disappeared from the type system, and a
 * wrong argument could only fail at runtime.
 *
 * This contract puts the dependency back without handing over the model: Money may type against `BookingView`,
 * Ride's `Booking` implements it, and nothing here can WRITE the row. The properties are declared read-only for
 * static analysis; there are no methods on purpose, because every current use is a property read and wrapping
 * them in getters would be churn with no safety gain.
 *
 * The one write path (Money's `BookingCharge::syncMoneySnapshot()`, which force-fills the money snapshot onto the
 * booking) is deliberately NOT typed against this interface: a read-only view cannot carry a write. It stays
 * untyped and is parked in `docs/audit/STATE.md` as its own design question.
 *
 * @property-read int $id
 * @property-read int $seats
 * @property-read int|null $user_id
 * @property-read mixed $amount_paid
 */
interface BookingView {}
