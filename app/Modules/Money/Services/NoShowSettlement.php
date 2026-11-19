<?php

namespace App\Modules\Money\Services;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Modules\Money\Contracts\NoShowSettlementInterface;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletTransaction;
use App\Modules\Ride\Contracts\BookingView;
use App\Modules\Ride\Contracts\RideView;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Money path split out of WalletTransactionService (behaviour unchanged). */
class NoShowSettlement implements NoShowSettlementInterface
{
    public function __construct(
        private readonly WalletAccess $access,
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

    /**
     * Passenger didn't show — E-PAY ride.
     * SyCash → Driver (95%) + SyCash → Primary (5%).
     */
    public function processPassengerNoShow(BookingView $booking, RideView $ride, User $passenger): void
    {
        $total = $booking->seats * $ride->price_per_seat;
        // RV-19: one definition of the 95/5 split. This used to round each share independently,
        // which produced legs summing to one cent MORE than the escrow debit for any total ending
        // in an odd tenth (1000.50 -> 950.48 + 50.03 = 1000.51), so postTransfer() refused the
        // transfer and the ride could never complete. See App\Modules\Money\Services\FeeSplit.
        ['driver' => $driverShare, 'platform' => $primaryShare] = FeeSplit::driverAndPlatform($total);

        [$syCashWallet, $driverWallet, $primaryWallet] = $this->lockPassengerNoShowWallets($ride, $total);

        $escrowOutKey = $this->releaseEscrowOnce($booking, $total, 'passenger-no-show');

        [$syCashPrev, $driverPrev, $primaryPrev] = $this->applyPassengerNoShowBalances(
            $syCashWallet, $driverWallet, $primaryWallet, $total, $driverShare, $primaryShare
        );

        $txId = 'PASS_NOSHOW_'.time().'_'.Str::random(6);

        // Stage 2 B2: keep the rows so each leg can name its own side.
        $syCashTransaction = $this->recordEscrowOutTransaction($booking, $syCashWallet, $total, $syCashPrev, $txId, $escrowOutKey);

        $shareTransactions = $this->recordPassengerNoShowShares(
            $booking, $ride, $driverWallet, $primaryWallet,
            $driverShare, $primaryShare, $driverPrev, $primaryPrev, $txId
        );

        $this->postPassengerNoShowLegs(
            $booking, $syCashWallet, $driverWallet, $primaryWallet, $total, $driverShare, $primaryShare, [
                $syCashWallet->id => $syCashTransaction,
                $driverWallet->id => $shareTransactions[$driverWallet->id] ?? null,
                $primaryWallet->id => $shareTransactions[$primaryWallet->id] ?? null,
            ]
        );

        Log::info('Passenger no-show settled', [
            'booking_id' => $booking->id,
            'driver_share' => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }

    /**
     * Driver didn't show — E-PAY ride.
     * SyCash → Passenger (100% refund).
     */
    public function processDriverNoShowRefund(RideView $ride, BookingView $booking, User $passenger): void
    {
        $refundAmount = $booking->seats * $ride->price_per_seat;

        [$syCashWallet, $passengerWallet] = $this->lockDriverNoShowRefundWallets($passenger, $refundAmount);

        $escrowOutKey = $this->releaseEscrowOnce($booking, $refundAmount, 'driver-no-show');

        [$syCashPrev, $passengerPrev] = $this->applyDriverNoShowRefundBalances(
            $syCashWallet, $passengerWallet, $refundAmount
        );

        $txId = 'DRIVER_NOSHOW_'.time().'_'.Str::random(6);

        // Stage 2 B2: keep the rows so each leg can name its own side.
        $refundTransactions = $this->recordDriverNoShowRefundRows(
            $booking, $ride, $passenger, $syCashWallet, $passengerWallet,
            $refundAmount, $syCashPrev, $passengerPrev, $txId, $escrowOutKey
        );

        $this->postDriverNoShowRefundLegs($booking, $syCashWallet, $passengerWallet, $refundAmount, $refundTransactions);

        Log::info('Driver no-show refund processed', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'refund_amount' => $refundAmount,
        ]);
    }

    /**
     * Lock SyCash, the driver and the platform wallet for a passenger no-show, and check SyCash.
     *
     * @return array{0: Wallet, 1: Wallet, 2: Wallet}
     */
    private function lockPassengerNoShowWallets(RideView $ride, $total): array
    {
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $driverWallet = $this->access->lockWalletByUserId($ride->driver_id);
        $primaryWallet = $this->access->lockWalletByPhone(config('admin.system_admin.phone'));

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $total,
            "Insufficient SyCash balance for no-show settlement. Required: {$total}"
        );

        return [$syCashWallet, $driverWallet, $primaryWallet];
    }

    /**
     * Lock SyCash and the passenger wallet for a driver no-show refund, and check SyCash.
     *
     * @return array{0: Wallet, 1: Wallet}
     */
    private function lockDriverNoShowRefundWallets(User $passenger, $refundAmount): array
    {
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->access->lockWalletByUserId($passenger->id);

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $refundAmount,
            "Insufficient SyCash balance for driver no-show refund. Required: {$refundAmount}"
        );

        return [$syCashWallet, $passengerWallet];
    }

    /** The escrow guard pair, before any balance moves: build the key, assert it is unused, debit. */
    private function releaseEscrowOnce(BookingView $booking, $amount, string $reason): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        $escrowOutKey = $this->access->postingKey('booking:'.$booking->id, 'escrow-out', $reason);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrow($booking, (float) $amount);

        return $escrowOutKey;
    }

    /**
     * Move SyCash's money out to the driver and the platform, returning the previous balances.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function applyPassengerNoShowBalances(
        Wallet $syCashWallet,
        Wallet $driverWallet,
        Wallet $primaryWallet,
        $total,
        $driverShare,
        $primaryShare
    ): array {
        $syCashPrev = $syCashWallet->balance;
        $driverPrev = $driverWallet->balance;
        $primaryPrev = $primaryWallet->balance;

        $syCashWallet->balance -= $total;
        $driverWallet->balance += $driverShare;
        $primaryWallet->balance += $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        return [$syCashPrev, $driverPrev, $primaryPrev];
    }

    /**
     * Move SyCash's money back to the passenger, returning both previous balances.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function applyDriverNoShowRefundBalances(Wallet $syCashWallet, Wallet $passengerWallet, $refundAmount): array
    {
        $syCashPrev = $syCashWallet->balance;
        $passengerPrev = $passengerWallet->balance;

        $syCashWallet->balance -= $refundAmount;
        $passengerWallet->balance += $refundAmount;

        $syCashWallet->save();
        $passengerWallet->save();

        return [$syCashPrev, $passengerPrev];
    }

    /** The SyCash escrow debit row, carrying the posting key that makes the movement single-shot. */
    private function recordEscrowOutTransaction(
        BookingView $booking,
        Wallet $syCashWallet,
        $total,
        $syCashPrev,
        string $txId,
        string $escrowOutKey
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'passenger_no_show_settlement',
            'amount' => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "No-show settlement — booking #{$booking->id}",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
            'posting_key' => $escrowOutKey,
        ]);
    }

    /** The driver's 95% earning row and the platform's 5% fee row for a passenger no-show. */
    private function recordPassengerNoShowShares(
        BookingView $booking,
        RideView $ride,
        Wallet $driverWallet,
        Wallet $primaryWallet,
        $driverShare,
        $primaryShare,
        $driverPrev,
        $primaryPrev,
        string $txId
    ): array {
        $driverTransaction = WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $ride->driver_id,
            'type' => 'passenger_no_show_earning',
            'amount' => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance' => $driverWallet->balance,
            'description' => "No-show compensation (95%) — passenger absent, {$booking->seats} seat(s)",
            'transaction_id' => 'DRIVER_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        $primaryTransaction = WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'platform_fee',
            'amount' => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance' => $primaryWallet->balance,
            'description' => "Platform fee (5%) — no-show booking #{$booking->id}",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        return [
            $driverWallet->id => $driverTransaction,
            $primaryWallet->id => $primaryTransaction,
        ];
    }

    /** The SyCash escrow debit row plus the full refund row back to the passenger. */
    private function recordDriverNoShowRefundRows(
        BookingView $booking,
        RideView $ride,
        User $passenger,
        Wallet $syCashWallet,
        Wallet $passengerWallet,
        $refundAmount,
        $syCashPrev,
        $passengerPrev,
        string $txId,
        string $escrowOutKey
    ): array {
        $syCashTransaction = WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'driver_no_show_refund',
            'amount' => -$refundAmount,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "Driver no-show — full refund to passenger, booking #{$booking->id}",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
            'posting_key' => $escrowOutKey,
        ]);

        $passengerTransaction = WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $passenger->id,
            'type' => 'driver_no_show_refund',
            'amount' => $refundAmount,
            'previous_balance' => $passengerPrev,
            'new_balance' => $passengerWallet->balance,
            'description' => "Full refund — driver no-show: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => 'PASS_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        // Stage 2 B2 added the capture at the call site ("keep the rows so each leg can name its own side")
        // but this method never returned them, so the declared `: array` made every driver-no-show refund
        // throw a TypeError - caught upstream as "Failed to resolve no-show report", which left the report
        // pending and the booking `confirmed`. The rows exist and are posted either way; returning them is
        // what makes the legs name their own transaction, exactly as the sibling passenger-no-show path does.
        return [
            $syCashWallet->id => $syCashTransaction,
            $passengerWallet->id => $passengerTransaction,
        ];
    }

    /** Double-entry legs for the passenger no-show fan-out (same 95/5 shape as ride completion). */
    private function postPassengerNoShowLegs(
        BookingView $booking,
        Wallet $syCashWallet,
        Wallet $driverWallet,
        Wallet $primaryWallet,
        $total,
        $driverShare,
        $primaryShare,
        array $transactions = []
    ): void {
        // ── Decision un3: double-entry legs (same 95/5 shape as ride completion) ─
        $this->ledger->postTransfer([
            ['wallet_id' => $syCashWallet->id, 'amount' => -$total, 'description' => "booking #{$booking->id}: no-show escrow released", 'wallet_transaction_id' => $transactions[$syCashWallet->id]->id ?? null],
            ['wallet_id' => $driverWallet->id, 'amount' => $driverShare, 'description' => "booking #{$booking->id}: driver share 95%", 'wallet_transaction_id' => $transactions[$driverWallet->id]->id ?? null],
            ['wallet_id' => $primaryWallet->id, 'amount' => $primaryShare, 'description' => "booking #{$booking->id}: platform fee 5%", 'wallet_transaction_id' => $transactions[$primaryWallet->id]->id ?? null],
        ]);
    }

    /** Double-entry legs for the driver no-show refund (SyCash → passenger, 100%). */
    private function postDriverNoShowRefundLegs(
        BookingView $booking,
        Wallet $syCashWallet,
        Wallet $passengerWallet,
        $refundAmount,
        array $transactions = []
    ): void {
        // ── Decision un3: double-entry legs (SyCash → passenger, 100%) ───────────
        $this->ledger->postTransfer([
            ['wallet_id' => $syCashWallet->id, 'amount' => -$refundAmount, 'description' => "booking #{$booking->id}: driver no-show refund out", 'wallet_transaction_id' => $transactions[$syCashWallet->id]->id ?? null],
            ['wallet_id' => $passengerWallet->id, 'amount' => $refundAmount, 'description' => "booking #{$booking->id}: refund to passenger", 'wallet_transaction_id' => $transactions[$passengerWallet->id]->id ?? null],
        ]);
    }
}
