<?php

namespace App\Modules\Money\Services;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Modules\Money\Contracts\CashRideFeeServiceInterface;
use App\Modules\Money\Models\Wallet;
use App\Modules\Ride\Contracts\CashRideCounter;
use App\Modules\Ride\Contracts\RideView;

/**
 * CashRideFeeService
 *
 * Manages the 5% creation fee charged to drivers on CASH rides only.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  RULES
 * ═══════════════════════════════════════════════════════════════════
 *
 *  CREATION:
 *    - Driver must have a wallet (even if empty) to create a cash ride.
 *    - Rides 1 & 2 (DEFERRED_RIDES_ALLOWED = 2):
 *        Fee is NOT deducted. It is added to wallet.cash_ride_debt.
 *    - Ride 3+ :
 *        Driver must clear any outstanding debt first.
 *        Fee is deducted immediately: Driver wallet → Primary Admin wallet.
 *
 *  CANCELLATION (driver cancels cash ride):
 *    Two factors determine the outcome: elapsed time and whether there were
 *    active bookings when cancelRide() was invoked (snapshotted BEFORE bookings
 *    are cancelled and passed in as $hadActiveBookings).
 *
 *    Elapsed % = (now − created_at) / (departure_time − created_at) × 100
 *
 *    Rule 1 — Elapsed < 30%:
 *        Always 100% relief regardless of bookings (early cancellation).
 *
 *    Rule 2 — Elapsed ≥ 30%, had active bookings:
 *        0% refunded — platform keeps the full fee.
 *        Score penalty still applies.
 *
 *    Rule 3 — Elapsed ≥ 30%, NO active bookings:
 *        100% relief — driver is not punished for an empty ride.
 *
 *    - If fee was deferred (cash_fee_deferred = true):
 *        Reduce debt by the resolved amount — no money actually moves.
 *    - If fee was paid immediately:
 *        Refund resolved amount from Primary Admin wallet back to driver.
 *
 *  DEBT AUTO-CLEAR:
 *    Called by AdminWalletRequestController after every approved top-up.
 *    If wallet.balance >= wallet.cash_ride_debt, the debt is deducted
 *    automatically and transferred to the Primary Admin wallet as revenue.
 *
 * ═══════════════════════════════════════════════════════════════════
 */
final class CashRideFeeService implements CashRideFeeServiceInterface
{
    private readonly CashRideFeeRefundService $refunds;

    private readonly CashRideDebtClearanceService $debtClearance;

    public function __construct(private readonly PrimaryAdminWallet $primaryAdminWallet, private readonly LedgerService $ledgerService, private readonly CashRideCreationFeeCharger $creationFeeCharger)
    {
        $this->refunds = new CashRideFeeRefundService($primaryAdminWallet, $ledgerService);
        $this->debtClearance = new CashRideDebtClearanceService($primaryAdminWallet, $ledgerService);
    }

    // =========================================================================
    // DELEGATED ENTRY POINTS - the implementations live in the two collaborators
    // =========================================================================

    /** Delegates to CashRideFeeRefundService. */
    public function refundCashRideCreationFee(RideView $ride, User $driver, bool $hadActiveBookings = false): void
    {
        $this->refunds->refundCashRideCreationFee($ride, $driver, $hadActiveBookings);
    }

    /** Delegates to CashRideDebtClearanceService. */
    public function autoClearDebt(Wallet $wallet, User $driver): void
    {
        $this->debtClearance->autoClearDebt($wallet, $driver);
    }

    /** Cash rides before immediate fee enforcement kicks in. */
    private const DEFERRED_RIDES_ALLOWED = 2;

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * RV-02 L2 (owner decision D1 = A) — scope note, read this before assuming
     * every method here is covered.
     * ══════════════════════════════════════════════════════════════════════════
     *
     * THIS SERVICE IS NOT ESCROW. Every movement in it is driver ↔ platform: the 5% cash-ride
     * creation fee, its refund, and the deferred-fee debt. None of them touches SyCash and none
     * of them has a booking, so none of them participates in `bookings.escrow_held` — cash
     * bookings deliberately hold 0 there ("cash never holds SyCash"). The audit's execution map
     * listed this service among the escrow sites; that came from grepping `lockForUpdate`, and it
     * over-counted. Nothing below is instrumented against escrow, and nothing needed to be.
     *
     * WHAT IS INSTRUMENTED, AND WHY ONLY PART OF IT
     *
     * `posting_key` is for ONCE-ONLY postings, and only two of this service's movements are:
     *
     *   chargeCashRideCreationFee  once per ride — a ride is created once
     *   refundCashRideCreationFee  once per cancellation — a ride is cancelled once
     *
     * `autoClearDebt` is deliberately NOT keyed. It runs after every approved wallet top-up and
     * is a REPEABLE movement: a driver can top up, clear, top up more and clear again. Giving it
     * a per-wallet key would make the second legitimate debt clear collide with the first and throw
     * — a regression introduced by the fix itself, on the one path that has no escrow to protect.
     * There is no counter in the schema that would make such a key both unique and meaningful, and
     * inventing one (the amount cleared) still collides whenever the same amount recurs. Leaving
     * it unkeyed is the honest choice, and it is recorded rather than silently skipped.
     *
     * NO AMOUNT CHANGES. The fee, the tiers and the debt arithmetic are untouched — this is the
     * same bookkeeping-only constraint the owner set for the escrow half.
     */

    // =========================================================================
    // ELIGIBILITY CHECK
    // =========================================================================

    /**
     * Check whether a driver may create a new cash ride.
     *
     * Returns:
     *   ['allowed' => bool, 'deferred' => bool, 'fee' => float, 'reason' => string]
     *
     * 'deferred' = true  → fee will be added to debt, not deducted from balance.
     * 'deferred' = false → fee will be deducted immediately on creation.
     */
    public function canCreateCashRide(User $driver, float $feeAmount): array
    {
        // RV-37 / un9: explicit load instead of a lazy read. Same single query, but it states
        // the dependency and survives the armed lazy-loading guard (this runs on every cash-ride
        // creation, so the query happened anyway).
        $wallet = Wallet::forUser((int) $driver->id);

        if (! $wallet) {
            return $this->cashRideDenied($feeAmount, 'You must create a wallet before creating a cash ride.');
        }

        // ── Balance check is FIRST, ride-count is SECOND ────────────────────
        //
        // A driver who already has money in their wallet should never accumulate
        // debt. If the balance covers the fee, charge immediately — regardless of
        // how many cash rides they have created so far.
        //
        // Deferral (grace period) only exists as a fallback for drivers who
        // genuinely cannot pay on rides 1 & 2.

        if ((float) $wallet->balance >= $feeAmount) {
            return $this->cashRideAllowed($feeAmount, false);
        }

        // Balance insufficient → apply grace period for the first two cash rides.
        $cashRideCount = $this->countCashRides($driver->id);

        if ($cashRideCount < self::DEFERRED_RIDES_ALLOWED) {
            return $this->cashRideAllowed($feeAmount, true);
        }

        return $this->cashRideBlockedByDebtOrBalance($wallet, $feeAmount);
    }

    // =========================================================================
    // CHARGE FEE ON CREATION
    // =========================================================================

    /**
     * Charge or defer the creation fee immediately after the Ride row is saved.
     *
     * MUST be called inside the same DB transaction as ride creation so that
     * if the wallet deduction fails the ride row is also rolled back.
     *
     * The ride must already have cash_creation_fee and cash_fee_deferred set.
     */
    public function chargeCashRideCreationFee(RideView $ride, User $driver): void
    {
        $this->creationFeeCharger->chargeCashRideCreationFee($ride, $driver);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Count how many cash rides this driver has created so far
     * (including the current one if already saved).
     */
    private function countCashRides(int $driverId): int
    {
        // Stage 2 C2: the count over the rides table is Ride's; the tier logic below is unchanged.
        return app(CashRideCounter::class)->countForDriver($driverId);
    }

    // =========================================================================
    // PRIVATE HELPERS — ELIGIBILITY CHECK
    // =========================================================================

    /** Allowed result: charged now, or deferred while inside the grace period. */
    private function cashRideAllowed(float $feeAmount, bool $deferred): array
    {
        return [
            'allowed' => true,
            'deferred' => $deferred,
            'fee' => $feeAmount,
            'reason' => '',
        ];
    }

    /** Blocked result: the ride may not be created, for the given reason. */
    private function cashRideDenied(float $feeAmount, string $reason): array
    {
        return [
            'allowed' => false,
            'deferred' => false,
            'fee' => $feeAmount,
            'reason' => $reason,
        ];
    }

    /**
     * Ride 3+ with insufficient balance → block on existing debt first,
     * then block on insufficient balance.
     */
    private function cashRideBlockedByDebtOrBalance(Wallet $wallet, float $feeAmount): array
    {
        $debt = (float) $wallet->cash_ride_debt;

        if ($debt > 0) {
            return $this->cashRideDenied($feeAmount, "You have an outstanding debt of {$debt} SYP from previous cash rides. "
                .'Please top up your wallet to clear it before creating another ride.');
        }

        return $this->cashRideDenied($feeAmount, "Insufficient wallet balance. The creation fee for this ride is {$feeAmount} SYP. "
            ."Current balance: {$wallet->balance} SYP.");
    }
}
