<?php

namespace App\Services\Payment;

use App\Enums\BookingStatus;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CashRideFeeService
 *
 * Manages the platform-profit-percentage creation fee charged to drivers on CASH rides only.
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
    /** Cash rides before immediate fee enforcement kicks in. */
    private const DEFERRED_RIDES_ALLOWED = 2;

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
        $wallet = $driver->wallet;

        if (!$wallet) {
            return [
                'allowed'  => false,
                'deferred' => false,
                'fee'      => $feeAmount,
                'reason'   => 'You must create a wallet before creating a cash ride.',
            ];
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
            return [
                'allowed'  => true,
                'deferred' => false,
                'fee'      => $feeAmount,
                'reason'   => '',
            ];
        }

        // Balance insufficient → apply grace period for the first two cash rides.
        $cashRideCount = $this->countCashRides($driver->id);

        if ($cashRideCount < self::DEFERRED_RIDES_ALLOWED) {
            return [
                'allowed'  => true,
                'deferred' => true,
                'fee'      => $feeAmount,
                'reason'   => '',
            ];
        }

        // Ride 3+ with insufficient balance → block on existing debt first,
        // then block on insufficient balance.
        $debt = (float) $wallet->cash_ride_debt;

        if ($debt > 0) {
            return [
                'allowed'  => false,
                'deferred' => false,
                'fee'      => $feeAmount,
                'reason'   => "You have an outstanding debt of {$debt} SYP from previous cash rides. "
                    . 'Please top up your wallet to clear it before creating another ride.',
            ];
        }

        return [
            'allowed'  => false,
            'deferred' => false,
            'fee'      => $feeAmount,
            'reason'   => "Insufficient wallet balance. The creation fee for this ride is {$feeAmount} SYP. "
                . "Current balance: {$wallet->balance} SYP.",
        ];
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
        $feeAmount    = (float) $ride->cash_creation_fee;
        $driverWallet = Wallet::where('user_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($ride->cash_fee_deferred) {
            // ── Deferred: add to debt, no balance change ──────────────────────
            $prevDebt                     = (float) $driverWallet->cash_ride_debt;
            $driverWallet->cash_ride_debt = $prevDebt + $feeAmount;
            $driverWallet->save();

            WalletTransaction::create([
                'wallet_id'        => $driverWallet->id,
                'user_id'          => $driver->id,
                'type'             => 'cash_ride_fee_deferred',
                'amount'           => 0,
                'previous_balance' => (float) $driverWallet->balance,
                'new_balance'      => (float) $driverWallet->balance,
                'description'      => "Cash ride creation fee deferred ({$feeAmount} SYP) — ride #{$ride->id}. "
                    . "Balance unchanged; debt now " . $driverWallet->cash_ride_debt . ' SYP.',
                'transaction_id'   => 'CASH_DEFER_' . time() . '_' . Str::random(6),
                'status'           => 'completed',
                'reference'        => "ride:{$ride->id}",
            ]);

            Log::info('Cash ride fee deferred', [
                'ride_id'   => $ride->id,
                'driver_id' => $driver->id,
                'fee'       => $feeAmount,
                'new_debt'  => $driverWallet->cash_ride_debt,
            ]);

            return;
        }

        // ── Immediate: Driver wallet → Primary Admin wallet ──────────────────
        $primaryWallet = $this->lockPrimaryWallet();
        $txId          = 'CASH_FEE_' . time() . '_' . Str::random(6);
        $driverPrev    = (float) $driverWallet->balance;
        $primaryPrev   = (float) $primaryWallet->balance;

        $driverWallet->balance  -= $feeAmount;
        $primaryWallet->balance += $feeAmount;

        $driverWallet->save();
        $primaryWallet->save();

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $driver->id,
            'type'             => 'cash_ride_creation_fee',
            'amount'           => -$feeAmount,
            'previous_balance' => $driverPrev,
            'new_balance'      => (float) $driverWallet->balance,
            'description'      => "Cash ride creation fee — ride #{$ride->id}: "
                . "{$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'cash_ride_creation_fee_received',
            'amount'           => $feeAmount,
            'previous_balance' => $primaryPrev,
            'new_balance'      => (float) $primaryWallet->balance,
            'description'      => "Cash ride creation fee received from driver #{$driver->id} — ride #{$ride->id}",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        Log::info('Cash ride fee charged immediately', [
            'ride_id'   => $ride->id,
            'driver_id' => $driver->id,
            'fee'       => $feeAmount,
        ]);
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
     *   Elapsed ≥ 30% + $hadActiveBookings     →   0% (platform keeps fee)
     *   Elapsed ≥ 30% + !$hadActiveBookings    → 100% (empty ride, no penalty)
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

        if (!$driverWallet) {
            Log::warning('Cannot refund cash ride fee — driver wallet not found', [
                'ride_id'   => $ride->id,
                'driver_id' => $driver->id,
            ]);
            return;
        }

        // Determine refund %:
        //   Elapsed < 30%                      → always 100%
        //   Elapsed ≥ 30% + had passengers     → 0%  (platform keeps fee)
        //   Elapsed ≥ 30% + no passengers      → 100%
        $elapsedPct   = $this->calculateElapsedPct($ride);
        $refundPct    = ($elapsedPct < 30.0 || !$hadActiveBookings) ? 100 : 0;
        $refundAmount = round($feeAmount * $refundPct / 100, 2);

        // ── Deferred fee: adjust debt only, no money moves ───────────────────
        if ($ride->cash_fee_deferred) {
            $prevDebt                     = (float) $driverWallet->cash_ride_debt;
            $driverWallet->cash_ride_debt = max(0.0, $prevDebt - $refundAmount);
            $driverWallet->save();

            WalletTransaction::create([
                'wallet_id'        => $driverWallet->id,
                'user_id'          => $driver->id,
                'type'             => 'cash_ride_fee_debt_cancelled',
                'amount'           => 0,
                'previous_balance' => (float) $driverWallet->balance,
                'new_balance'      => (float) $driverWallet->balance,
                'description'      => "Deferred fee cancelled — ride #{$ride->id} cancelled. "
                    . "Debt reduced by {$refundAmount} SYP ({$refundPct}%) — was {$prevDebt} SYP.",
                'transaction_id'   => 'DEBT_CANCEL_' . time() . '_' . Str::random(6),
                'status'           => 'completed',
                'reference'        => "ride:{$ride->id}",
            ]);

            Log::info('Deferred cash ride fee cancelled', [
                'ride_id'            => $ride->id,
                'driver_id'          => $driver->id,
                'prev_debt'          => $prevDebt,
                'new_debt'           => $driverWallet->cash_ride_debt,
                'refund_pct'         => $refundPct,
                'elapsed_pct'        => round($elapsedPct, 2),
                'had_active_bookings'=> $hadActiveBookings,
            ]);

            return;
        }

        // ── Paid fee: money movement from Primary Admin → Driver wallet ───────
        $platformKeeps = round($feeAmount - $refundAmount, 2);

        if ($refundAmount <= 0) {
            // Audit record only — no money movement
            WalletTransaction::create([
                'wallet_id'        => $driverWallet->id,
                'user_id'          => $driver->id,
                'type'             => 'cash_ride_fee_no_refund',
                'amount'           => 0,
                'previous_balance' => (float) $driverWallet->balance,
                'new_balance'      => (float) $driverWallet->balance,
                'description'      => "No refund on cash ride creation fee — ride #{$ride->id} cancelled "
                    . "(late cancellation). Platform keeps {$feeAmount} SYP.",
                'transaction_id'   => 'CASH_NO_REFUND_' . time() . '_' . Str::random(6),
                'status'           => 'completed',
                'reference'        => "ride:{$ride->id}",
            ]);

            Log::info('Cash ride fee — no refund (late cancellation with passengers)', [
                'ride_id'            => $ride->id,
                'driver_id'          => $driver->id,
                'fee_paid'           => $feeAmount,
                'elapsed_pct'        => round($elapsedPct, 2),
                'had_active_bookings'=> $hadActiveBookings,
            ]);

            return;
        }

        // Partial or full refund: Primary Admin → Driver wallet
        $primaryWallet = $this->lockPrimaryWallet();

        if ((float) $primaryWallet->balance < $refundAmount) {
            Log::error('Cannot refund cash ride fee — insufficient Primary Admin balance', [
                'ride_id'         => $ride->id,
                'refund_needed'   => $refundAmount,
                'primary_balance' => $primaryWallet->balance,
            ]);
            throw new \RuntimeException(
                "Insufficient Primary Admin balance to refund cash ride creation fee. Required: {$refundAmount} SYP."
            );
        }

        $txId        = 'CASH_FEE_REFUND_' . time() . '_' . Str::random(6);
        $driverPrev  = (float) $driverWallet->balance;
        $primaryPrev = (float) $primaryWallet->balance;

        $driverWallet->balance  += $refundAmount;
        $primaryWallet->balance -= $refundAmount;

        $driverWallet->save();
        $primaryWallet->save();

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $driver->id,
            'type'             => 'cash_ride_fee_refund',
            'amount'           => $refundAmount,
            'previous_balance' => $driverPrev,
            'new_balance'      => (float) $driverWallet->balance,
            'description'      => "Cash ride creation fee refund ({$refundPct}%) — ride #{$ride->id} cancelled. "
                . "Refunded: {$refundAmount} SYP. Platform keeps: {$platformKeeps} SYP.",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'cash_ride_fee_refund_issued',
            'amount'           => -$refundAmount,
            'previous_balance' => $primaryPrev,
            'new_balance'      => (float) $primaryWallet->balance,
            'description'      => "Cash ride fee refund issued to driver #{$driver->id} — ride #{$ride->id}. "
                . "Platform retains {$platformKeeps} SYP.",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        Log::info('Cash ride creation fee refunded', [
            'ride_id'            => $ride->id,
            'driver_id'          => $driver->id,
            'fee_paid'           => $feeAmount,
            'refund_pct'         => $refundPct,
            'refund_amount'      => $refundAmount,
            'platform_keeps'     => $platformKeeps,
            'elapsed_pct'        => round($elapsedPct, 2),
            'had_active_bookings'=> $hadActiveBookings,
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

        // Re-lock the wallet inside a transaction to prevent races
        $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

        if (!$lockedWallet) {
            return;
        }

        $debt = (float) $lockedWallet->cash_ride_debt;

        if ($debt <= 0 || (float) $lockedWallet->balance < $debt) {
            // Balance still insufficient — leave debt in place, driver must top up more
            return;
        }

        $primaryWallet = $this->lockPrimaryWallet();
        $txId          = 'DEBT_CLEAR_' . time() . '_' . Str::random(6);
        $prevBalance   = (float) $lockedWallet->balance;
        $prevDebt      = $debt;
        $primaryPrev   = (float) $primaryWallet->balance;

        $lockedWallet->balance        -= $debt;
        $lockedWallet->cash_ride_debt  = 0;
        $primaryWallet->balance       += $debt;

        $lockedWallet->save();
        $primaryWallet->save();

        WalletTransaction::create([
            'wallet_id'        => $lockedWallet->id,
            'user_id'          => $driver->id,
            'type'             => 'cash_ride_debt_cleared',
            'amount'           => -$debt,
            'previous_balance' => $prevBalance,
            'new_balance'      => (float) $lockedWallet->balance,
            'description'      => "Cash ride creation fee debt auto-cleared ({$debt} SYP) after wallet top-up.",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => "wallet:{$lockedWallet->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'cash_ride_debt_received',
            'amount'           => $debt,
            'previous_balance' => $primaryPrev,
            'new_balance'      => (float) $primaryWallet->balance,
            'description'      => "Cash ride fee debt payment received from driver #{$driver->id} ({$debt} SYP).",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => "wallet:{$lockedWallet->id}",
        ]);

        Log::info('Cash ride debt auto-cleared after top-up', [
            'driver_id'    => $driver->id,
            'wallet_id'    => $lockedWallet->id,
            'debt_cleared' => $prevDebt,
            'new_balance'  => $lockedWallet->balance,
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
        return \App\Models\Ride::where('driver_id', $driverId)
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
        $now       = now();
        $created   = $ride->created_at;
        $departure = Carbon::parse($ride->departure_time);

        if ($now->greaterThanOrEqualTo($departure)) {
            return 100.0;
        }

        $totalMinutes   = $created->diffInMinutes($departure);
        $elapsedMinutes = $created->diffInMinutes($now);

        return $totalMinutes > 0
            ? min(100.0, ($elapsedMinutes / $totalMinutes) * 100)
            : 100.0;
    }

    private function lockPrimaryWallet(): Wallet
    {
        $wallet = Wallet::where('phone_number', config('admin.system_admin.phone'))
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \RuntimeException(
                'Primary Admin wallet not found. Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        return $wallet;
    }
}
