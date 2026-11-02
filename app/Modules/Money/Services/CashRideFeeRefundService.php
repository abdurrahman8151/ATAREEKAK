<?php

namespace App\Modules\Money\Services;

use App\Domain\ValueObjects\Money;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The cash creation fee REFUND tiers, exactly as the class contract in CashRideFeeService
 * documents them: elapsed share first (under 30% is always full relief), then whether the ride had
 * active bookings when it was cancelled. A deferred fee reduces wallet.cash_ride_debt; a paid fee
 * moves money back from the primary admin wallet.
 *
 * Split out of CashRideFeeService (behaviour unchanged). Bodies moved verbatim, so the tiers, the
 * posting keys and the ledger legs are the same code that ran before.
 */
final class CashRideFeeRefundService
{
    public function __construct(
        private readonly PrimaryAdminWallet $primaryAdminWallet,
        private readonly LedgerService $ledgerService,
    ) {}

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
        $this->ledgerService->postTransfer([
            ['wallet_id' => $primaryWallet->id, 'amount' => -$refundAmount, 'description' => "ride #{$ride->id}: fee refund issued"],
            ['wallet_id' => $driverWallet->id, 'amount' => $refundAmount, 'description' => "ride #{$ride->id}: fee refund to driver"],
        ]);
    }
}
