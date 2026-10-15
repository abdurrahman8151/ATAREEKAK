<?php

namespace App\Services\Payment;

use App\Domain\ValueObjects\Money;
use App\Enums\WalletKind;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\FeeSplit;
use App\Support\PostingKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Money path split out of WalletTransactionService (behaviour unchanged). */
class RidePayout
{

    public function __construct(
        private readonly WalletAccess $access,
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

/**
     * Release escrow after all parties confirm ride completion.
     *
     * SyCash → Driver  (95%)
     * SyCash → Primary ( 5%)
     */
    public function releaseEarningsToDriver(Ride $ride, Collection $confirmedBookings): void
    {
        $total = $confirmedBookings->sum(fn ($b) => $b->seats * $ride->price_per_seat);

        if ($total <= 0) {
            Log::info('No e-pay bookings to release', ['ride_id' => $ride->id]);

            return;
        }

        // RV-19: one definition of the 95/5 split. This used to round each share independently,
        // which produced legs summing to one cent MORE than the escrow debit for any total ending
        // in an odd tenth (1000.50 -> 950.48 + 50.03 = 1000.51), so postTransfer() refused the
        // transfer and the ride could never complete. See App\Support\FeeSplit.
        ['driver' => $driverShare, 'platform' => $primaryShare] = FeeSplit::driverAndPlatform($total);

        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->access->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet = $this->access->lockWalletByUserId($ride->driver_id);

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $total,
            "Insufficient SyCash balance for payout. Required: {$total}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // One wallet debit here, but the escrow is PER BOOKING, so each confirmed booking is
        // decremented on its own row for exactly the share of `$total` it contributed
        // (`$total` is the sum of those same per-booking amounts). The posting key names the ride
        // AND the settled set, so re-running this same completion cannot pay again, while
        // cancelling a different subset of the same ride is still a distinct movement.
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'ride-complete', $confirmedBookings);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrowForSet(
            $confirmedBookings,
            fn (Booking $b) => (float) $b->seats * (float) $ride->price_per_seat
        );

        $syCashPrev = $syCashWallet->balance;
        $driverPrev = $driverWallet->balance;
        $primaryPrev = $primaryWallet->balance;

        $syCashWallet->balance -= $total;
        $driverWallet->balance += $driverShare;
        $primaryWallet->balance += $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        $txId = 'COMPLETE_'.time().'_'.Str::random(6);

        // SyCash debit
        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'escrow_released',
            'amount' => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "Escrow released for completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $escrowOutKey,
        ]);

        // Driver receives 95% and the platform 5%: one row each, written in helpers.
        $this->recordDriverShare($ride, $driverShare, $driverWallet, $driverPrev, $txId);

        $this->recordPlatformShare($ride, $primaryShare, $primaryWallet, $primaryPrev, $txId);

        // ── Decision un3: double-entry legs for the 95/5 split ─────────────────
        // THIS is the transfer that most needed a ledger. One wallet gives, TWO receive: the split
        // is not two independent events, it is one event with two outcomes, and the single-sided rows
        // above cannot state that. A `from`/`to` pair of columns could not express it either, which
        // is why the ledger is N legs per transfer.
        //
        // The sum is checked by `postTransfer`, so if the 95/5 arithmetic ever drifts from `$total`
        // the transfer is REFUSED rather than silently creating or destroying money.
        $this->ledger->postTransfer([
            [
                'wallet_id' => $syCashWallet->id,
                'amount' => -$total,
                'description' => "ride #{$ride->id}: escrow released",
            ],
            [
                'wallet_id' => $driverWallet->id,
                'amount' => $driverShare,
                'description' => "ride #{$ride->id}: driver share 95%",
            ],
            [
                'wallet_id' => $primaryWallet->id,
                'amount' => $primaryShare,
                'description' => "ride #{$ride->id}: platform fee 5%",
            ],
        ]);

        Log::info('Ride earnings released', [
            'ride_id' => $ride->id,
            'driver_share' => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }

/** Driver share of a completed ride (95%): the earnings row. */
    public function recordDriverShare(Ride $ride, float $driverShare, Wallet $driverWallet, float $driverPrev, string $txId): void
    {
        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $ride->driver_id,
            'type' => 'ride_earnings',
            'amount' => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance' => $driverWallet->balance,
            'description' => "Earnings (95%) — completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'DRIVER_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
        ]);
    }

/** Platform share of a completed ride (5%): the platform fee row. */
    public function recordPlatformShare(Ride $ride, float $primaryShare, Wallet $primaryWallet, float $primaryPrev, string $txId): void
    {
        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'platform_fee',
            'amount' => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance' => $primaryWallet->balance,
            'description' => "Platform fee (5%) — completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
        ]);
    }

/**
     * Release ONE passenger's escrowed share to the driver.
     *
     * Called by BookingService::passengerConfirmCompletion() once per booking
     * when that specific passenger confirms the ride.
     *
     * Must be called inside a DB::transaction() — BookingService already does this.
     *
     * @throws \RuntimeException if SyCash has insufficient balance,
     *                           or if any required wallet is missing.
     */
    public function releaseEscrowToDriver(Booking $booking, Ride $ride, User $driver): void
    {
        // `R2 sec 117`: seats x price is a MONEY product, so it is computed in integer minor units.
        // Multiplying the float first and rounding after could land on the wrong side of a half-fils.
        $total = Money::from((float) $ride->price_per_seat)->multiply((float) $booking->seats)->amount();
        // RV-19: routed through the same helper as the other two sites. This one already
        // subtracted, which is why it was the only path that worked for the affected totals.
        ['driver' => $driverShare, 'platform' => $primaryShare] = FeeSplit::driverAndPlatform($total);

        // ── Lock all three wallets (same order as everywhere else to prevent deadlock) ──
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->access->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet = $this->access->lockWalletByUserId($driver->id);

        // ── Guard: SyCash must have enough to release ─────────────────────────────
        $this->access->assertSufficientBalance(
            $syCashWallet,
            $total,
            "SyCash escrow has insufficient balance to release booking #{$booking->id}. "
            ."Required: {$total} SYP. Available: {$syCashWallet->balance} SYP."
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // This is the per-passenger counterpart of releaseEarningsToDriver(). The two cannot both
        // pay for the same booking — `passengerConfirmCompletion` marks the booking COMPLETED
        // before calling here, and `checkAndCompleteRide` only settles bookings still CONFIRMED —
        // but that exclusion lives in the CALLER. These guards make the guarantee hold at the
        // money layer itself, so a caller that gets that filter wrong can no longer pay twice.
        $escrowOutKey = $this->access->postingKey('booking:'.$booking->id, 'escrow-out', 'release');
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrow($booking, $total);

        // ── Snapshot previous balances ────────────────────────────────────────────
        $syCashPrev = (float) $syCashWallet->balance;
        $driverPrev = (float) $driverWallet->balance;
        $primaryPrev = (float) $primaryWallet->balance;

        // ── Apply balance changes ─────────────────────────────────────────────────
        $syCashWallet->balance = $syCashPrev - $total;
        $driverWallet->balance = $driverPrev + $driverShare;
        $primaryWallet->balance = $primaryPrev + $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        // ── Transaction IDs ───────────────────────────────────────────────────────
        $ts = now()->timestamp;
        $txId = 'ESC_REL_'.$booking->id.'_'.$ts;
        $txRef = "booking:{$booking->id}";

        // ── 1. SyCash debit (escrow released) ────────────────────────────────────
        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'escrow_release',
            'amount' => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance' => (float) $syCashWallet->balance,
            'description' => "Escrow released — booking #{$booking->id}, {$booking->seats} seat(s)",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => $txRef,
            'posting_key' => $escrowOutKey,
        ]);

        // ── 2. Driver credit (95%) ────────────────────────────────────────────────
        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'type' => 'ride_earning',
            'amount' => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance' => (float) $driverWallet->balance,
            'description' => "Earnings (95%) — booking #{$booking->id}, {$booking->seats} seat(s)",
            'transaction_id' => 'DRIVER_'.$txId,
            'status' => 'completed',
            'reference' => $txRef,
        ]);

        // ── 3. Primary Admin credit (5%) ──────────────────────────────────────────
        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'platform_fee',
            'amount' => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance' => (float) $primaryWallet->balance,
            'description' => "Platform fee (5%) — booking #{$booking->id}",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => $txRef,
        ]);

        // ── Decision un3: double-entry legs (95/5, per booking) ─────────────────
        // This method derives `$primaryShare` by SUBTRACTING rather than multiplying by 0.05, to
        // avoid float drift. That care is invisible in the balances, but the ledger is where it
        // becomes checkable: if the two shares ever stop summing to `$total`, the legs do not
        // balance and the transfer is refused instead of quietly creating or destroying money.
        $this->ledger->postTransfer([
            ['wallet_id' => $syCashWallet->id, 'amount' => -$total, 'description' => "booking #{$booking->id}: escrow released"],
            ['wallet_id' => $driverWallet->id, 'amount' => $driverShare, 'description' => "booking #{$booking->id}: driver share 95%"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $primaryShare, 'description' => "booking #{$booking->id}: platform fee 5%"],
        ]);

        Log::info('Escrow released per passenger confirmation', [
            'booking_id' => $booking->id,
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'total' => $total,
            'driver_share' => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }
}
