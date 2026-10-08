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

    /**
     * RV-20. Dispatched on an EXPLICIT reason, because the two real refund paths have genuinely
     * different money semantics and picking the wrong one moves the wrong amount:
     *
     *   driver_cancellation → refund 100% of every confirmed passenger
     *   staff_cancellation  → refund the policy portion off the `amount_paid` snapshot, and return
     *                          the needs-review count for rows charged before that snapshot existed
     *
     * The previous shape wrapped ONE booking in a one-element collection and called the driver path -
     * so a staff cancellation routed through here would have refunded a single passenger in full
     * instead of the policy amount. It was never called in production, which is the only reason the bug
     * never cost anyone money. An unrecognised reason is REFUSED, not defaulted: defaulting to the
     * driver path would over-refund anyone whose reason string drifted.
     */
    public function processRefund(
        Ride $ride,
        EloquentCollection $bookings,
        string $reason,
    ): RefundResult {
        if ($bookings->isEmpty()) {
            return RefundResult::success('No bookings to refund');
        }

        return match ($reason) {
            'driver_cancellation' => $this->refundForDriverCancellation($ride, $bookings),
            'staff_cancellation' => $this->refundForStaffCancellation($ride, $bookings),
            default => throw new \InvalidArgumentException(
                "Unknown refund reason '{$reason}'. Expected 'driver_cancellation' or "
                ."'staff_cancellation'. Refusing rather than guessing which money path to run."
            ),
        };
    }

    /**
     * Driver cancellation refunds 100% of every confirmed passenger, so there is no per-booking
     * arithmetic to summarise: the set path already did the whole set in one combined movement.
     */
    private function refundForDriverCancellation(Ride $ride, EloquentCollection $bookings): RefundResult
    {
        $this->walletService->refundPassengersForDriverCancellation($ride, $bookings);

        return RefundResult::success(sprintf(
            'Refunded %d booking(s) in full (driver cancellation)',
            $bookings->count(),
        ));
    }

    /**
     * Staff cancellation is the POLICY path, so the per-booking refund amounts are not a constant
     * fraction of the total and cannot be reduced to a single "success". The summary is returned in
     * the result so the caller can see how much moved and how many rows need manual review.
     */
    private function refundForStaffCancellation(Ride $ride, EloquentCollection $bookings): RefundResult
    {
        $summary = $this->walletService->refundPassengersForStaffCancellation($ride, $bookings);

        $message = sprintf(
            'Refunded %.2f across %d booking(s)',
            (float) $summary['refunded'],
            (int) $summary['bookings'],
        );

        if (($summary['needs_review'] ?? 0) > 0) {
            $message .= sprintf(
                '; %d booking(s) need manual review (charged before the money snapshot existed)',
                (int) $summary['needs_review']
            );
        }

        return RefundResult::success($message);
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
