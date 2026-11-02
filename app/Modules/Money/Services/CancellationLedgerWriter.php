<?php

namespace App\Modules\Money\Services;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Records WHAT a cancellation refund moved: the wallet balance changes, the
 * `wallet_transactions` rows and the balanced double-entry legs.
 *
 * Split out of `CancellationRefund` (behaviour unchanged). It only writes the rows;
 * it never decides an amount, never acquires a lock on a wallet it is about to debit
 * through escrow, and never opens a transaction - the caller owns all three.
 */
class CancellationLedgerWriter
{
    public function __construct(
        private readonly WalletAccess $access,
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

    /** The single SyCash debit row covering every passenger refund of a driver cancellation. */
    public function recordDriverCancellationEscrowRow(
        Ride $ride,
        Wallet $syCashWallet,
        $totalRefund,
        string $escrowOutKey,
        string $txId
    ): void {
        $syCashPrev = $syCashWallet->balance;
        $syCashWallet->balance -= $totalRefund;
        $syCashWallet->save();

        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'driver_cancellation_refunds',
            'amount' => -$totalRefund,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "Refunds for driver-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $escrowOutKey,
        ]);
    }

    /** One passenger's full refund for a driver cancellation: credit the wallet and write the refund row. */
    public function refundPassengerForDriverCancellation(Ride $ride, Booking $booking, string $txId): void
    {
        $refundAmount = $booking->seats * $ride->price_per_seat;
        $passengerWallet = $this->access->lockWalletByUserId($booking->user_id);
        $passengerPrev = $passengerWallet->balance;

        $passengerWallet->balance += $refundAmount;
        $passengerWallet->save();

        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $booking->user_id,
            'type' => 'driver_cancellation_refund',
            'amount' => $refundAmount,
            'previous_balance' => $passengerPrev,
            'new_balance' => $passengerWallet->balance,
            'description' => "Full refund — driver cancelled: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'PASS_'.$txId.'_'.$booking->id,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        Log::info('Passenger refunded for driver cancellation', [
            'booking_id' => $booking->id,
            'passenger_id' => $booking->user_id,
            'amount' => $refundAmount,
        ]);
    }

    /** The refund fan-out as ONE transfer with N+1 legs, posted after the per-passenger loop. */
    public function postDriverCancellationRefundLegs(
        Ride $ride,
        Collection $bookings,
        Wallet $syCashWallet,
        $totalRefund
    ): void {
        // ── Decision un3: double-entry legs for the whole refund ───────────────
        // One transfer, N+1 legs - NOT one transfer per passenger. `postTransfer` checks the fan-out
        // sums to zero, so the refunds can never exceed what SyCash actually gave.
        //
        // The AMOUNTS here still come from `seats * ride.price_per_seat` (decision 3's territory,
        // owner-flagged "reconsider") - this records what the code actually did, it does not change
        // what that is. Changing the refund amount and recording it are separate decisions.
        if ($bookings->isNotEmpty()) {
            $legs = [[
                'wallet_id' => $syCashWallet->id,
                'amount' => -$totalRefund,
                'description' => "ride #{$ride->id}: driver cancellation refund out",
            ]];

            foreach ($bookings as $booking) {
                $legs[] = [
                    'wallet_id' => $this->access->lockWalletByUserId($booking->user_id)->id,
                    'amount' => (float) $booking->seats * (float) $ride->price_per_seat,
                    'description' => "ride #{$ride->id}: refund to booking #{$booking->id}",
                ];
            }

            $this->ledger->postTransfer($legs);
        }
    }

    /** One passenger's full refund for a staff cancellation: credit the wallet and write the refund row. */
    public function refundPassengerForStaffCancellation(Ride $ride, Booking $booking, string $txId): void
    {
        $refundAmount = (float) $booking->amount_paid;
        $passengerWallet = $this->access->lockWalletByUserId($booking->user_id);
        $passengerPrev = $passengerWallet->balance;

        $passengerWallet->balance += $refundAmount;
        $passengerWallet->save();

        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $booking->user_id,
            'type' => 'staff_cancellation_refund',
            'amount' => $refundAmount,
            'previous_balance' => $passengerPrev,
            'new_balance' => $passengerWallet->balance,
            'description' => "Full refund — cancelled by support: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'PASS_'.$txId.'_'.$booking->id,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        Log::info('Passenger refunded for staff cancellation', [
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'passenger_id' => $booking->user_id,
            'amount' => $refundAmount,
            'source' => 'booking.amount_paid snapshot',
        ]);
    }

    /** The single SyCash debit row covering every passenger refund of a staff cancellation. */
    public function recordStaffCancellationEscrowRow(
        Ride $ride,
        Wallet $syCashWallet,
        $totalRefund,
        string $escrowOutKey,
        string $txId
    ): void {
        $syCashPrev = $syCashWallet->balance;
        $syCashWallet->balance -= $totalRefund;
        $syCashWallet->save();

        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'staff_cancellation_refunds',
            'amount' => -$totalRefund,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "Full refunds for staff-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $escrowOutKey,
        ]);
    }

    /** The refund fan-out as ONE transfer with N+1 legs, posted after the per-passenger loop. */
    public function postStaffCancellationRefundLegs(
        Ride $ride,
        Collection $refundable,
        Wallet $syCashWallet,
        $totalRefund
    ): void {
        // ── Decision un3: double-entry legs for the whole refund ───────────────
        // N passengers, so N+1 legs: SyCash gives once, each passenger receives. The sum is checked,
        // so the fan-out can never refund more (or less) than SyCash actually gave. Note the legs
        // are posted ONCE after the loop, not per passenger - a refund to five people is ONE
        // transfer with six legs, not five transfers.
        if ($refundable->isNotEmpty()) {
            $legs = [[
                'wallet_id' => $syCashWallet->id,
                'amount' => -$totalRefund,
                'description' => "ride #{$ride->id}: staff cancellation refund out",
            ]];

            foreach ($refundable as $booking) {
                $legs[] = [
                    'wallet_id' => $this->access->lockWalletByUserId($booking->user_id)->id,
                    'amount' => (float) $booking->amount_paid,
                    'description' => "ride #{$ride->id}: refund to booking #{$booking->id}",
                ];
            }

            $this->ledger->postTransfer($legs);
        }
    }

    /** Passenger side of a time-based cancellation: a refund row, or an audit row when the refund is zero. */
    public function recordPassengerRefund(Booking $booking, int $seatsCancelled, array $refundPolicy, float $refundAmount, Wallet $passengerWallet, float $passengerPrev, string $txId): void
    {
        if ($refundAmount > 0) {
            WalletTransaction::create([
                'wallet_id' => $passengerWallet->id,
                'user_id' => $booking->user_id,
                'type' => 'time_based_refund',
                'amount' => $refundAmount,
                'previous_balance' => $passengerPrev,
                'new_balance' => $passengerWallet->balance,
                'description' => "Refund ({$refundPolicy['refund_percentage']}%) — {$seatsCancelled} seat(s) cancelled ({$refundPolicy['policy_tier']})",
                'transaction_id' => 'REFUND_'.$txId,
                'status' => 'completed',
                'reference' => "booking:{$booking->id}",
            ]);
        } else {
            // Audit trail for zero-refund so passenger sees it in history
            WalletTransaction::create([
                'wallet_id' => $passengerWallet->id,
                'user_id' => $booking->user_id,
                'type' => 'cancellation_no_refund',
                'amount' => 0,
                'previous_balance' => $passengerPrev,
                'new_balance' => $passengerWallet->balance,
                'description' => "No refund — late cancellation ({$refundPolicy['policy_tier']})",
                'transaction_id' => 'NO_REFUND_'.$txId,
                'status' => 'completed',
                'reference' => "booking:{$booking->id}",
            ]);
        }
    }

    /** Driver side of a time-based cancellation: the compensation row, when the driver is owed anything. */
    public function recordDriverCompensation(Ride $ride, Booking $booking, int $seatsCancelled, array $refundPolicy, float $driverAmount, Wallet $driverWallet, float $driverPrev, string $txId): void
    {
        if ($driverAmount > 0) {
            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $ride->driver_id,
                'type' => 'cancellation_fee_earnings',
                'amount' => $driverAmount,
                'previous_balance' => $driverPrev,
                'new_balance' => $driverWallet->balance,
                'description' => "Cancellation compensation — {$seatsCancelled} seat(s) ({$refundPolicy['policy_tier']})",
                'transaction_id' => 'DRIVER_'.$txId,
                'status' => 'completed',
                'reference' => "booking:{$booking->id}",
            ]);
        }
    }

    /**
     * Move SyCash's money out to the passenger and the driver, returning the previous balances.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    public function applyTimeBasedCancellationBalances(
        Wallet $syCashWallet,
        Wallet $passengerWallet,
        Wallet $driverWallet,
        $totalPaid,
        $refundAmount,
        $driverAmount
    ): array {
        $syCashPrev = $syCashWallet->balance;
        $passengerPrev = $passengerWallet->balance;
        $driverPrev = $driverWallet->balance;

        $syCashWallet->balance -= $totalPaid;
        if ($refundAmount > 0) {
            $passengerWallet->balance += $refundAmount;
        }
        if ($driverAmount > 0) {
            $driverWallet->balance += $driverAmount;
        }

        $syCashWallet->save();
        $passengerWallet->save();
        $driverWallet->save();

        return [$syCashPrev, $passengerPrev, $driverPrev];
    }

    /** The SyCash debit row for a time-based cancellation. */
    public function recordTimeBasedCancellationEscrowRow(
        Booking $booking,
        Wallet $syCashWallet,
        $totalPaid,
        $refundAmount,
        $driverAmount,
        $syCashPrev,
        string $escrowOutKey,
        string $txId
    ): void {
        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'cancellation_processing',
            'amount' => -$totalPaid,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => 'Cancellation: refund '.number_format($refundAmount, 0).' SYP to passenger, '.number_format($driverAmount, 0).' SYP to driver',
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
            'posting_key' => $escrowOutKey,
        ]);
    }

    /**
     * Double-entry legs for the three-party time-based cancellation.
     *
     * Only legs are built if the amounts are non-zero, so a transfer that (correctly) owes nothing
     * to one side does not post a 0.00 leg - a zero leg is noise, and `postTransfer` would still
     * balance it, which would be misleading rather than wrong.
     */
    public function postTimeBasedCancellationLegs(
        Booking $booking,
        Wallet $syCashWallet,
        Wallet $passengerWallet,
        Wallet $driverWallet,
        $totalPaid,
        $refundAmount,
        $driverAmount
    ): void {
        // ── Decision un3: double-entry legs (SyCash → passenger + driver) ────────
        // Three parties, one event. Only legs are built if the amounts are non-zero, so a transfer
        // that (correctly) owes nothing to one side does not post a 0.00 leg - a zero leg is noise,
        // and `postTransfer` would still balance it, which would be misleading rather than wrong.
        $legs = [['wallet_id' => $syCashWallet->id, 'amount' => -$totalPaid, 'description' => "booking #{$booking->id}: cancellation out"]];

        if ($refundAmount > 0) {
            $legs[] = ['wallet_id' => $passengerWallet->id, 'amount' => $refundAmount, 'description' => "booking #{$booking->id}: refund to passenger"];
        }

        if ($driverAmount > 0) {
            $legs[] = ['wallet_id' => $driverWallet->id, 'amount' => $driverAmount, 'description' => "booking #{$booking->id}: driver compensation"];
        }

        $this->ledger->postTransfer($legs);
    }
}
