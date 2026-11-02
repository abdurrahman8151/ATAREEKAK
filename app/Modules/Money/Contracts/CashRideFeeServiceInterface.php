<?php

namespace App\Modules\Money\Contracts;

use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;

/**
 * Port over the 5% cash-ride creation fee (batch 233).
 *
 * RIDE decides WHEN the fee is charged, refused or refunded; MONEY owns the wallets, the debt
 * and the ledger rows. Before this port `RideService`, `RideCancellationService` and
 * `RideCompletionService` name-hinted `App\Services\Payment\CashRideFeeService` directly. The
 * surface below is exactly the four methods its callers use - step (c) added `autoClearDebt` for
 * the CONSOLE top-up path; implemented by that same service and bound to its singleton, so no
 * amount, tier or posting key can change.
 */
interface CashRideFeeServiceInterface
{
    /**
     * Refund (or relieve) the creation fee after a driver cancels a cash ride.
     *
     * @param  bool  $hadActiveBookings  snapshotted BEFORE the bookings were cancelled
     */
    public function refundCashRideCreationFee(Ride $ride, User $driver, bool $hadActiveBookings = false): void;

    /**
     * Can this driver create a cash ride, and what does the caller need to know about the fee?
     *
     * @return array{allowed: bool, deferred: bool, fee: float, reason: string}
     */
    public function canCreateCashRide(User $driver, float $feeAmount): array;

    /** Charge the 5% creation fee for a newly created cash ride (immediate or deferred). */
    public function chargeCashRideCreationFee(Ride $ride, User $driver): void;

    /** After an approved top-up: clear any outstanding cash-ride debt on this wallet. */
    public function autoClearDebt(Wallet $wallet, User $driver): void;
}
