<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * E-Pay Payment Strategy  (wallet-based)
 *
 * Money flow:
 *   booking   → chargePassengerForBooking   : passenger wallet → escrow
 *   confirm   → releaseEscrowToDriver       : escrow → driver wallet (minus cut)
 *   cancel    → refundPassengersFor…        : escrow → passenger wallet
 */
final class EPayPaymentStrategy implements PaymentStrategy
{
    public function __construct(
        private readonly WalletTransactionService $walletService,
    ) {}

    // ── Book ─────────────────────────────────────────────────────────────────

    public function processBookingPayment(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): PaymentResult {
        try {
            $this->walletService->chargePassengerForBooking($booking, $ride, $passenger);

            return PaymentResult::success('Payment held in escrow');
        } catch (\Exception $e) {
            return PaymentResult::failure($e->getMessage());
        }
    }

    // ── Confirm (per-passenger) ──────────────────────────────────────────────

    /**
     * Called once for each booking when THAT passenger confirms.
     * Releases this passenger's share from escrow to the driver's wallet.
     */
    public function processRideCompletionPayment(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): PaymentResult {
        try {
            $driver = $ride->driver;   // ← derive driver from ride; passenger is the confirmer
            $this->walletService->releaseEscrowToDriver($booking, $ride, $driver);

            return PaymentResult::success('Escrow released to driver');
        } catch (\Exception $e) {
            return PaymentResult::failure($e->getMessage());
        }
    }
    // ── Refund ───────────────────────────────────────────────────────────────

    public function processRefund(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): RefundResult {
        try {
            $bookings = new EloquentCollection([$booking]);
            $this->walletService->refundPassengersForDriverCancellation($ride, $bookings);

            return RefundResult::success('Refund processed successfully');
        } catch (\Exception $e) {
            return RefundResult::failure($e->getMessage());
        }
    }

    // ── Meta ─────────────────────────────────────────────────────────────────

    public function canProcess(string $paymentMethod): bool
    {
        return $paymentMethod === 'e-pay';
    }

    public function getPaymentMethod(): string
    {
        return 'e-pay';
    }
}
