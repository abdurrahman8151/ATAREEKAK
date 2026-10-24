<?php

namespace App\Services\Payment;

use App\Domain\ValueObjects\Money;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\PostingKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
final class CashRideFeeService
{
    public function __construct(private readonly PrimaryAdminWallet $primaryAdminWallet) {}

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
        $driver->loadMissing('wallet');
        $wallet = $driver->wallet;

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
    public function chargeCashRideCreationFee(Ride $ride, User $driver): void
    {
        app(CashRideCreationFeeCharger::class)->chargeCashRideCreationFee($ride, $driver);
    }

    // =========================================================================
    // REFUND FEE ON CANCELLATION
    // =========================================================================

    /**
     * Refund the creation fee (fully or not at all) when a driver cancels a cash ride.
     *
     * $hadActiveBookings MUST be snapshotted by the caller BEFORE bookings are
     * cancelled in the DB. Passing the live DB state would always yield false
     * because all bookings are cancelled before this method is called.
     *
     * Refund rules (see class docblock for full policy):
     *   Elapsed < 30%                          → 100% (early cancellation)
     *   Elapsed 30–50% + $hadActiveBookings    →  70%
     *   Elapsed 50–70% + $hadActiveBookings    →  50%
     *   Elapsed ≥ 70%  + $hadActiveBookings    →   0% (platform keeps fee)
     *   Any elapsed    + !$hadActiveBookings   → 100% (empty ride, no penalty)
     *
     * Deferred fees: reduce debt by the resolved amount — no actual money movement.
     * Paid fees:     transfer the resolved amount from Primary Admin wallet to driver.
     */
    public function refundCashRideCreationFee(Ride $ride, User $driver, bool $hadActiveBookings = false): void
    {
        $feeAmount = (float) $ride->cash_creation_fee;

        if ($feeAmount <= 0) {
            return;
        }

        $driverWallet = Wallet::where('user_id', $driver->id)
            ->lockForUpdate()
            ->first();

        if (! $driverWallet) {
            Log::warning('Cannot refund cash ride fee — driver wallet not found', [
                'ride_id' => $ride->id,
                'driver_id' => $driver->id,
            ]);

            return;
        }

        $elapsedPct = $this->calculateElapsedPct($ride);
        $refundPct = $this->resolveRefundPct($hadActiveBookings, $elapsedPct);
        // `R2 sec 117`: the percentage of a fee is `Money`'s own operation now, so this is decided in
        // integer minor units instead of `round($feeAmount * $pct / 100, 2)` in float. Same value.
        $refundAmount = Money::from($feeAmount)->percentage((float) $refundPct)->amount();

        // RV-02 L2: a ride is cancelled once, so each of the three outcomes below is a once-only
        // posting. Distinct keys, because a ride takes exactly one of them.
        $debtCancelKey = PostingKey::build('ride:'.$ride->id, 'cash-fee', 'debt-cancel');
        $noRefundKey = PostingKey::build('ride:'.$ride->id, 'cash-fee', 'no-refund');
        $refundKey = PostingKey::build('ride:'.$ride->id, 'cash-fee', 'refund');

        // ── Deferred fee: adjust debt only, no money moves ───────────────────
        if ($ride->cash_fee_deferred) {
            $this->cancelDeferredDebt($ride, $driver, $driverWallet, $elapsedPct, $refundPct, $hadActiveBookings, $refundAmount, $debtCancelKey);

            return;
        }

        // ── Paid fee: money movement from Primary Admin → Driver wallet ───────
        // `R2 sec 117`: subtraction as well. Signed `Money` means this is allowed to be negative if it
        // ever were - the old float `round()` did not check either, so behaviour is unchanged.
        $platformKeeps = Money::from($feeAmount)->subtract(Money::from($refundAmount))->amount();

        $this->settlePaidFeeRefund($ride, $driver, $driverWallet, $feeAmount, $elapsedPct, $refundPct, $hadActiveBookings, $refundAmount, $platformKeeps, $refundKey, $noRefundKey);
    }

    /** Deferred fee: only the driver debt is reduced; no money moves. */
    private function cancelDeferredDebt(Ride $ride, User $driver, Wallet $driverWallet, float $elapsedPct, int $refundPct, bool $hadActiveBookings, float $refundAmount, string $debtCancelKey): void
    {
        PostingKey::assertUnused($debtCancelKey);

        $prevDebt = (float) $driverWallet->cash_ride_debt;
        $driverWallet->cash_ride_debt = max(0.0, $prevDebt - $refundAmount);
        $driverWallet->save();

        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_fee_debt_cancelled',
            'amount' => 0,
            'previous_balance' => (float) $driverWallet->balance,
            'new_balance' => (float) $driverWallet->balance,
            'description' => "Deferred fee cancelled — ride #{$ride->id} cancelled. "
                ."Debt reduced by {$refundAmount} SYP ({$refundPct}%) — was {$prevDebt} SYP.",
            'transaction_id' => 'DEBT_CANCEL_'.time().'_'.Str::random(6),
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $debtCancelKey,
        ]);

        Log::info('Deferred cash ride fee cancelled', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'prev_debt' => $prevDebt,
            'new_debt' => $driverWallet->cash_ride_debt,
            'refund_pct' => $refundPct,
            'elapsed_pct' => round($elapsedPct, 2),
            'had_active_bookings' => $hadActiveBookings,
        ]);

    }

    /** No refund (late cancellation with passengers): an audit row only; no money moves. */
    private function recordNoRefund(Ride $ride, User $driver, Wallet $driverWallet, float $feeAmount, float $elapsedPct, int $refundPct, bool $hadActiveBookings, float $refundAmount, string $noRefundKey): void
    {
        // Audit record only — no money movement
        PostingKey::assertUnused($noRefundKey);

        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_fee_no_refund',
            'amount' => 0,
            'previous_balance' => (float) $driverWallet->balance,
            'new_balance' => (float) $driverWallet->balance,
            'description' => "No refund on cash ride creation fee — ride #{$ride->id} cancelled "
                ."(late cancellation). Platform keeps {$feeAmount} SYP.",
            'transaction_id' => 'CASH_NO_REFUND_'.time().'_'.Str::random(6),
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $noRefundKey,
        ]);

        Log::info('Cash ride fee — no refund (late cancellation with passengers)', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'fee_paid' => $feeAmount,
            'elapsed_pct' => round($elapsedPct, 2),
            'had_active_bookings' => $hadActiveBookings,
        ]);

    }

    /** Partial or full refund: Primary Admin to Driver wallet, as two double-entry legs. */
    private function refundToDriver(Ride $ride, User $driver, Wallet $driverWallet, float $feeAmount, float $elapsedPct, int $refundPct, bool $hadActiveBookings, float $refundAmount, float $platformKeeps, string $refundKey): void
    {
        PostingKey::assertUnused($refundKey);

        $primaryWallet = $this->primaryAdminWallet->lock();
        $this->ensurePrimaryCanRefund($ride, $primaryWallet, $refundAmount);

        $txId = 'CASH_FEE_REFUND_'.time().'_'.Str::random(6);
        $driverPrev = (float) $driverWallet->balance;
        $primaryPrev = (float) $primaryWallet->balance;

        $driverWallet->balance += $refundAmount;
        $primaryWallet->balance -= $refundAmount;

        $driverWallet->save();
        $primaryWallet->save();

        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_fee_refund',
            'amount' => $refundAmount,
            'previous_balance' => $driverPrev,
            'new_balance' => (float) $driverWallet->balance,
            'description' => "Cash ride creation fee refund ({$refundPct}%) — ride #{$ride->id} cancelled. "
                ."Refunded: {$refundAmount} SYP. Platform keeps: {$platformKeeps} SYP.",
            'transaction_id' => $txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $refundKey,
        ]);

        $this->recordPrimaryRefundTransaction($ride, $driver, $primaryWallet, $refundAmount, $platformKeeps, $primaryPrev, $txId);

        $this->postRefundLegs($ride, $primaryWallet, $driverWallet, $refundAmount);

        Log::info('Cash ride creation fee refunded', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'fee_paid' => $feeAmount,
            'refund_pct' => $refundPct,
            'refund_amount' => $refundAmount,
            'platform_keeps' => $platformKeeps,
            'elapsed_pct' => round($elapsedPct, 2),
            'had_active_bookings' => $hadActiveBookings,
        ]);
    }

    // =========================================================================
    // AUTO-CLEAR DEBT  (called after admin approves a top-up)
    // =========================================================================

    /**
     * If wallet.balance >= wallet.cash_ride_debt, deduct the debt automatically
     * and transfer it to the Primary Admin wallet as platform revenue.
     *
     * Fails silently when balance is still insufficient — no exception thrown
     * so the top-up approval response is never blocked.
     */
    public function autoClearDebt(Wallet $wallet, User $driver): void
    {
        $debt = (float) $wallet->cash_ride_debt;

        if ($debt <= 0) {
            return;
        }

        $lockedWallet = $this->lockPayableWallet($wallet);

        if (! $lockedWallet) {
            return;
        }

        $debt = (float) $lockedWallet->cash_ride_debt;
        $primaryWallet = $this->primaryAdminWallet->lock();
        $txId = 'DEBT_CLEAR_'.time().'_'.Str::random(6);
        $prevBalance = (float) $lockedWallet->balance;
        $prevDebt = $debt;
        $primaryPrev = (float) $primaryWallet->balance;

        $lockedWallet->balance -= $debt;
        $lockedWallet->cash_ride_debt = 0;
        $primaryWallet->balance += $debt;

        $lockedWallet->save();
        $primaryWallet->save();

        $this->recordDebtClearedTransactions($lockedWallet, $primaryWallet, $driver, $debt, $prevBalance, $prevDebt, $primaryPrev, $txId);

        $this->postDebtClearLegs($lockedWallet, $primaryWallet, $driver, $debt);

        Log::info('Cash ride debt auto-cleared after top-up', [
            'driver_id' => $driver->id,
            'wallet_id' => $lockedWallet->id,
            'debt_cleared' => $prevDebt,
            'new_balance' => $lockedWallet->balance,
        ]);
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
        return Ride::where('driver_id', $driverId)
            ->where('payment_method', 'cash')
            ->count();
    }

    /**
     * Calculate how far through the ride window we currently are, as a percentage.
     *
     * Elapsed % = (now − created_at) / (departure_time − created_at) × 100
     *
     * Returns 100.0 if departure_time has already passed (fully elapsed).
     * Returns 100.0 if the ride window has zero duration (edge case).
     */
    private function calculateElapsedPct(Ride $ride): float
    {
        $now = now();
        $created = $ride->created_at;
        $departure = Carbon::parse($ride->departure_time);

        if ($now->greaterThanOrEqualTo($departure)) {
            return 100.0;
        }

        $totalMinutes = $created->diffInMinutes($departure);
        $elapsedMinutes = $created->diffInMinutes($now);

        return $totalMinutes > 0
            ? min(100.0, ($elapsedMinutes / $totalMinutes) * 100)
            : 100.0;
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

    // =========================================================================
    // PRIVATE HELPERS — REFUND FEE ON CANCELLATION
    // =========================================================================

    /**
     * Determine refund %:
     *   Elapsed < 30%                      → always 100%
     *   Elapsed ≥ 30% + had passengers     → 0%  (platform keeps fee)
     *   Elapsed ≥ 30% + no passengers      → 100%
     *
     * RV-46 (owner ruling 2026-10-13): graduated tiers. A ride with no bookings is always a full
     * refund. With bookings: <30% elapsed 100, <50% 70, <70% 50, otherwise 0.
     */
    private function resolveRefundPct(bool $hadActiveBookings, float $elapsedPct): int
    {
        if (! $hadActiveBookings || $elapsedPct < 30.0) {
            return 100;
        }

        if ($elapsedPct < 50.0) {
            return 70;
        }

        if ($elapsedPct < 70.0) {
            return 50;
        }

        return 0;
    }

    /** Paid fee: either the audit-only no-refund row, or the transfer back to the driver. */
    private function settlePaidFeeRefund(Ride $ride, User $driver, Wallet $driverWallet, float $feeAmount, float $elapsedPct, int $refundPct, bool $hadActiveBookings, float $refundAmount, float $platformKeeps, string $refundKey, string $noRefundKey): void
    {
        if ($refundAmount <= 0) {
            $this->recordNoRefund($ride, $driver, $driverWallet, $feeAmount, $elapsedPct, $refundPct, $hadActiveBookings, $refundAmount, $noRefundKey);

            return;
        }

        $this->refundToDriver($ride, $driver, $driverWallet, $feeAmount, $elapsedPct, $refundPct, $hadActiveBookings, $refundAmount, $platformKeeps, $refundKey);
    }

    /** The Primary Admin wallet must cover the refund before any money moves. */
    private function ensurePrimaryCanRefund(Ride $ride, Wallet $primaryWallet, float $refundAmount): void
    {
        if ((float) $primaryWallet->balance < $refundAmount) {
            Log::error('Cannot refund cash ride fee — insufficient Primary Admin balance', [
                'ride_id' => $ride->id,
                'refund_needed' => $refundAmount,
                'primary_balance' => $primaryWallet->balance,
            ]);
            throw new \RuntimeException(
                "Insufficient Primary Admin balance to refund cash ride creation fee. Required: {$refundAmount} SYP."
            );
        }
    }

    /** Platform-side leg of the refund: the matching wallet transaction (no posting key). */
    private function recordPrimaryRefundTransaction(Ride $ride, User $driver, Wallet $primaryWallet, float $refundAmount, float $platformKeeps, float $primaryPrev, string $txId): void
    {
        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'cash_ride_fee_refund_issued',
            'amount' => -$refundAmount,
            'previous_balance' => $primaryPrev,
            'new_balance' => (float) $primaryWallet->balance,
            'description' => "Cash ride fee refund issued to driver #{$driver->id} — ride #{$ride->id}. "
                ."Platform retains {$platformKeeps} SYP.",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
        ]);
    }

    private function postRefundLegs(Ride $ride, Wallet $primaryWallet, Wallet $driverWallet, float $refundAmount): void
    {
        // ── Decision un3: double-entry legs (platform → driver, partial) ────────
        // A PARTIAL refund: only `$refundAmount` moves back to the driver; the platform keeps
        // `$platformKeeps`. The legs cover what actually moved, not the original fee - which is why
        // the sum is the refund and not the fee.
        app(LedgerService::class)->postTransfer([
            ['wallet_id' => $primaryWallet->id, 'amount' => -$refundAmount, 'description' => "ride #{$ride->id}: fee refund issued"],
            ['wallet_id' => $driverWallet->id, 'amount' => $refundAmount, 'description' => "ride #{$ride->id}: fee refund to driver"],
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS — AUTO-CLEAR DEBT
    // =========================================================================

    /**
     * Re-lock the wallet inside a transaction to prevent races, and return it only
     * when it still exists and the debt is covered by the locked balance.
     */
    private function lockPayableWallet(Wallet $wallet): ?Wallet
    {
        $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

        if (! $lockedWallet) {
            return null;
        }

        $debt = (float) $lockedWallet->cash_ride_debt;

        if ($debt <= 0 || (float) $lockedWallet->balance < $debt) {
            // Balance still insufficient — leave debt in place, driver must top up more
            return null;
        }

        return $lockedWallet;
    }

    /** Both legs of the debt settlement: driver wallet debit and platform credit. */
    private function recordDebtClearedTransactions(Wallet $lockedWallet, Wallet $primaryWallet, User $driver, float $debt, float $prevBalance, float $prevDebt, float $primaryPrev, string $txId): void
    {
        WalletTransaction::create([
            'wallet_id' => $lockedWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_debt_cleared',
            'amount' => -$debt,
            'previous_balance' => $prevBalance,
            'new_balance' => (float) $lockedWallet->balance,
            'description' => "Cash ride creation fee debt auto-cleared ({$debt} SYP) after wallet top-up.",
            'transaction_id' => $txId,
            'status' => 'completed',
            'reference' => "wallet:{$lockedWallet->id}",
        ]);

        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'cash_ride_debt_received',
            'amount' => $debt,
            'previous_balance' => $primaryPrev,
            'new_balance' => (float) $primaryWallet->balance,
            'description' => "Cash ride fee debt payment received from driver #{$driver->id} ({$debt} SYP).",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "wallet:{$lockedWallet->id}",
        ]);
    }

    private function postDebtClearLegs(Wallet $lockedWallet, Wallet $primaryWallet, User $driver, float $debt): void
    {
        // ── Decision un3: double-entry legs (driver → platform) ──────────────────
        // This is where the DEFERRED fee from `chargeCashRideCreationFee` finally becomes a real
        // movement: the driver topped up, so the debt is collected now. The deferred branch posted no
        // legs because nothing moved then; this is the leg pair for that obligation being settled.
        app(LedgerService::class)->postTransfer([
            ['wallet_id' => $lockedWallet->id, 'amount' => -$debt, 'description' => "driver #{$driver->id}: deferred fee debt cleared"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $debt, 'description' => "driver #{$driver->id}: deferred fee received"],
        ]);
    }
}
