<?php

namespace App\Modules\Money\Services;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;

/**
 * The pre-movement guard of every cancellation refund: lock the wallets, prove the
 * posting key is unused and take the escrow out - all BEFORE any balance moves.
 *
 * Split out of `CancellationRefund` (behaviour unchanged). It owns the "may this
 * movement happen once" half of a cancellation; it never writes a refund row and it
 * never decides a refund amount.
 */
class CancellationEscrowGuard
{
    public function __construct(
        private readonly WalletAccess $access,
    ) {}

    /** Lock SyCash for a driver-cancellation refund and check it holds the whole refund. */
    public function lockDriverCancellationSyCash($totalRefund): Wallet
    {
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $totalRefund,
            "Insufficient SyCash balance for passenger refunds. Required: {$totalRefund}"
        );

        return $syCashWallet;
    }

    /**
     * The escrow guard pair over the whole booking set, before any balance moves: 100% of each
     * booking's escrow goes back, so every booking's row is cleared to zero by this call.
     */
    public function guardDriverCancellationEscrow($ride, Collection $bookings): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // 100% of each booking's escrow goes back, so every booking's row is cleared to zero by
        // this call. The refund TIER is untouched — this only decides whether it may happen once.
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'driver-cancel', $bookings);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrowForSet(
            $bookings,
            fn ($b) => (float) $b->seats * (float) $ride->price_per_seat
        );

        return $escrowOutKey;
    }

    /** Lock SyCash for a staff-cancellation refund and check it holds the whole refund. */
    public function lockStaffCancellationSyCash($totalRefund): Wallet
    {
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $totalRefund,
            "Insufficient SyCash balance for staff-cancellation refunds. Required: {$totalRefund}"
        );

        return $syCashWallet;
    }

    /**
     * The escrow guard pair over the refundable set, before any balance moves.
     *
     * Debited against the SAME `amount_paid` snapshot this method already refunds from, so the
     * escrow row can never disagree with the money about how much this booking held. Only
     * `$refundable` is touched: the needs-review rows were never charged, so they hold no escrow,
     * and this is deliberately not a second place to guess an amount at them.
     */
    public function guardStaffCancellationEscrow($ride, Collection $refundable): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'staff-cancel', $refundable);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrowForSet(
            $refundable,
            fn ($b) => (float) $b->amount_paid
        );

        return $escrowOutKey;
    }

    /**
     * Lock SyCash, the passenger and the driver for a time-based cancellation, and check SyCash.
     *
     * @return array{0: Wallet, 1: Wallet, 2: Wallet}
     */
    public function lockTimeBasedCancellationWallets($booking, $ride, $totalPaid): array
    {
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->access->lockWalletByUserId($booking->user_id);
        $driverWallet = $this->access->lockWalletByUserId($ride->driver_id);

        $this->access->assertSufficientBalance(
            $syCashWallet,
            $totalPaid,
            "Insufficient SyCash balance for cancellation refund. Required: {$totalPaid}"
        );

        return [$syCashWallet, $passengerWallet, $driverWallet];
    }

    /**
     * The escrow guard pair for a (possibly partial) time-based cancellation, before any balance
     * moves.
     *
     * The refund TIER is untouched — this guards whether the movement may happen, not what it pays.
     * `$totalPaid` is debited, not the full booking: the seats that stay booked keep their escrow,
     * and `BookingService` reduces `$booking->seats` immediately afterwards, so a later completion
     * of the same booking releases exactly what is left here.
     */
    public function guardTimeBasedCancellationEscrow($booking, int $seatsCancelled, $totalPaid): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // THE KEY MUST DISTINGUISH A PARTIAL CANCEL FROM THE FULL SETTLEMENT — and successive
        // partial cancels from each other, which is why it names the REMAINING seat count rather
        // than the cancelled one or the amount. Cancelling one of two seats then the other is two
        // movements (`:cancel:1`, then `:cancel:0`); a single full cancel of two seats is the same
        // end state and correctly shares the final key. The caller still holds the PRE-cancel
        // `$booking->seats` at this point, so the arithmetic is exact and deterministic.
        $seatsRemaining = max(0, (int) $booking->seats - $seatsCancelled);
        $escrowOutKey = $this->access->postingKey('booking:'.$booking->id, 'escrow-out', 'cancel:'.$seatsRemaining);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrow($booking, (float) $totalPaid);

        return $escrowOutKey;
    }
}
