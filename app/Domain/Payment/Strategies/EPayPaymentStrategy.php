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
 *
 * ── WHY THERE IS NO try/catch HERE (RV-09(a)) ──────────────────────────────────
 * This class used to wrap all three calls in `catch (\Exception) { return
 * PaymentResult::failure($e->getMessage()); }`. That silently converted every failure into a soft
 * result object that a caller could ignore - and after RV-02 L2 it would have been much worse,
 * because the guards D1 added are THROWN, not returned:
 *
 *   - a `posting_key` collision  (this movement was already posted)
 *   - an escrow guard refusing to overdraw `bookings.escrow_held`
 *
 * Caught, those become an ignorable `PaymentResult` on a booking row that has ALREADY been
 * written - the double-payment defect RV-02 L2 exists to prevent, returning through the front
 * door. Every caller already runs inside `DB::transaction`, so letting the exception propagate
 * unwinds cleanly and leaves nothing half-written.
 *
 * This class is now a faithful adapter: it forwards and it does not reinterpret failure. That is
 * also the precondition RV-20 needs - routing charge and refund through this factory is only
 * behaviour-preserving now that the strategies cannot swallow a refusal. See `R2 sec 83`.
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
        $this->walletService->chargePassengerForBooking($booking, $ride, $passenger);

        return PaymentResult::success('Payment held in escrow');
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
        $driver = $ride->driver;   // ← derive driver from ride; passenger is the confirmer
        $this->walletService->releaseEscrowToDriver($booking, $ride, $driver);

        return PaymentResult::success('Escrow released to driver');
    }
    // ── Refund ───────────────────────────────────────────────────────────────

    public function processRefund(
        Booking $booking,
        Ride $ride,
        User $passenger,
    ): RefundResult {
        $bookings = new EloquentCollection([$booking]);
        $this->walletService->refundPassengersForDriverCancellation($ride, $bookings);

        return RefundResult::success('Refund processed successfully');
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
