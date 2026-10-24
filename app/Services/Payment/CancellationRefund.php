<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Money path split out of WalletTransactionService (behaviour unchanged). */
class CancellationRefund
{
    /** The pre-movement escrow/funds guard of every cancellation path. */
    private readonly CancellationEscrowGuard $guard;

    /** Writes the balances, the `wallet_transactions` rows and the double-entry legs. */
    private readonly CancellationLedgerWriter $writer;

    public function __construct(
        WalletAccess $access,
        LedgerService $ledger = new LedgerService,
    ) {
        $this->guard = new CancellationEscrowGuard($access);
        $this->writer = new CancellationLedgerWriter($access, $ledger);
    }

    /**
     * Full refund to all confirmed passengers when driver cancels.
     * SyCash → each Passenger (100%).
     * No creation fee was charged, so nothing to refund to driver.
     */
    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $totalRefund = $bookings->sum(fn ($b) => $b->seats * $ride->price_per_seat);
        $syCashWallet = $this->guard->lockDriverCancellationSyCash($totalRefund);

        $escrowOutKey = $this->guard->guardDriverCancellationEscrow($ride, $bookings);

        $txId = 'DRIVER_CANCEL_'.time().'_'.Str::random(6);

        $this->writer->recordDriverCancellationEscrowRow($ride, $syCashWallet, $totalRefund, $escrowOutKey, $txId);

        foreach ($bookings as $booking) {
            $this->writer->refundPassengerForDriverCancellation($ride, $booking, $txId);
        }

        $this->writer->postDriverCancellationRefundLegs($ride, $bookings, $syCashWallet, $totalRefund);
    }

    /**
     * Decision 6 (owner, 2026-10-02): a staff-initiated cancellation refunds every affected
     * passenger IN FULL and applies NO driver score penalty.
     *
     * Rationale in the owner's words: "staff cancellation is rare; make it non-ad-hoc". So this is
     * one explicit, ledgered operation rather than the ad-hoc status flip the staff endpoints used
     * to perform (they marked bookings `cancelled` and moved no money at all, stranding escrow in
     * SyCash - RV-03).
     *
     * THE AMOUNT IS THE RV-40 SNAPSHOT (`amount_paid`), NOT `seats * ride.price_per_seat`.
     * `price_per_seat` is mutable: a price edit between charge and cancellation would silently
     * over- or under-refund, which is the exact divergence RV-40 was created to remove. The sibling
     * `refundPassengersForDriverCancellation()` still derives from the price; that is decision 3's
     * territory (cancellation money policy, owner-flagged "reconsider"), NOT this task.
     *
     * ONLY bookings with `amount_paid > 0` are refunded, because that is the exact moment money
     * moved (see `BookingService`: DIRECT+e-pay charges at booking, REQUEST+e-pay at driver accept;
     * cash never touches SyCash). A confirmed e-pay booking with `amount_paid = 0` is a pre-RV-40
     * legacy row - it is NOT guessed at, it is reported as `needs_review` so staff can run
     * `bookings:backfill-money-snapshot` and re-try. Guessing is what this whole task removes.
     *
     * Must be called INSIDE a DB transaction (the caller owns the booking-status update).
     *
     * @return array{refunded: float, bookings: int, needs_review: int, amount_source: string}
     */
    public function refundPassengersForStaffCancellation(Ride $ride, Collection $bookings): array
    {
        [$refundable, $needsReview] = $this->splitStaffCancellationBookings($ride, $bookings);

        if ($refundable->isEmpty()) {
            return [
                'refunded' => 0.0,
                'bookings' => 0,
                'needs_review' => $needsReview,
                'amount_source' => 'none',
            ];
        }

        $totalRefund = (float) $refundable->sum(fn (Booking $b) => (float) $b->amount_paid);
        $syCashWallet = $this->guard->lockStaffCancellationSyCash($totalRefund);

        $escrowOutKey = $this->guard->guardStaffCancellationEscrow($ride, $refundable);

        $txId = 'STAFF_CANCEL_'.time().'_'.Str::random(6);

        $this->writer->recordStaffCancellationEscrowRow($ride, $syCashWallet, $totalRefund, $escrowOutKey, $txId);

        foreach ($refundable as $booking) {
            $this->refundPassengerForStaffCancellation($ride, $booking, $txId);
        }

        $this->writer->postStaffCancellationRefundLegs($ride, $refundable, $syCashWallet, $totalRefund);

        return [
            'refunded' => $totalRefund,
            'bookings' => $refundable->count(),
            'needs_review' => $needsReview,
            'amount_source' => 'amount_paid',
        ];
    }

    /** One passenger's full refund for a staff cancellation: credit the wallet and write the refund row. */
    public function refundPassengerForStaffCancellation(Ride $ride, Booking $booking, string $txId): void
    {
        $this->writer->refundPassengerForStaffCancellation($ride, $booking, $txId);
    }

    /**
     * Calculate refund policy based on time elapsed.
     *
     * Returns refund_percentage for passenger (rest goes to driver).
     */
    public function calculateRefundPolicy(Carbon $departureTime, Carbon $bookingCreatedAt): array
    {
        $now = now();

        if ($now->greaterThanOrEqualTo($departureTime)) {
            return [
                'refund_percentage' => 0,
                'time_elapsed_percentage' => 100,
                'policy_tier' => 'No refund — departure time passed',
            ];
        }

        $totalMinutes = $bookingCreatedAt->diffInMinutes($departureTime);
        $elapsedMinutes = $bookingCreatedAt->diffInMinutes($now);
        $elapsedPct = $totalMinutes > 0
            ? min(100, ($elapsedMinutes / $totalMinutes) * 100)
            : 100;

        if ($elapsedPct <= 30) {
            $tier = ['refund_percentage' => 100, 'policy_tier' => 'Full refund (0–30% elapsed)'];
        } elseif ($elapsedPct <= 50) {
            $tier = ['refund_percentage' => 70,  'policy_tier' => 'Partial refund (30–50% elapsed)'];
        } elseif ($elapsedPct <= 70) {
            $tier = ['refund_percentage' => 50,  'policy_tier' => 'Partial refund (50–70% elapsed)'];
        } else {
            $tier = ['refund_percentage' => 0,   'policy_tier' => 'No refund (70–100% elapsed)'];
        }

        return array_merge($tier, [
            'time_elapsed_percentage' => $elapsedPct,
            'total_minutes_from_booking' => $totalMinutes,
            'minutes_elapsed' => $elapsedMinutes,
        ]);
    }

    /**
     * Process time-based cancellation.
     * SyCash → Passenger (refund%) + SyCash → Driver (non-refundable%).
     */
    public function processTimeBasedCancellation(
        Booking $booking,
        Ride $ride,
        int $seatsCancelled,
        array $refundPolicy
    ): void {
        $totalPaid = $seatsCancelled * $ride->price_per_seat;
        $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount = $totalPaid - $refundAmount;

        [$syCashWallet, $passengerWallet, $driverWallet] = $this->guard->lockTimeBasedCancellationWallets(
            $booking, $ride, $totalPaid
        );

        $escrowOutKey = $this->guard->guardTimeBasedCancellationEscrow($booking, $seatsCancelled, $totalPaid);

        [$syCashPrev, $passengerPrev, $driverPrev] = $this->writer->applyTimeBasedCancellationBalances(
            $syCashWallet, $passengerWallet, $driverWallet, $totalPaid, $refundAmount, $driverAmount
        );

        $txId = 'TIME_CANCEL_'.time().'_'.Str::random(6);

        $this->writer->recordTimeBasedCancellationEscrowRow(
            $booking, $syCashWallet, $totalPaid, $refundAmount, $driverAmount, $syCashPrev, $escrowOutKey, $txId
        );

        // Passenger refund (or an audit row for a zero refund)
        $this->recordPassengerRefund($booking, $seatsCancelled, $refundPolicy, $refundAmount, $passengerWallet, $passengerPrev, $txId);

        // Driver compensation
        $this->recordDriverCompensation($ride, $booking, $seatsCancelled, $refundPolicy, $driverAmount, $driverWallet, $driverPrev, $txId);

        $this->writer->postTimeBasedCancellationLegs(
            $booking, $syCashWallet, $passengerWallet, $driverWallet, $totalPaid, $refundAmount, $driverAmount
        );

        Log::info('Time-based cancellation processed', [
            'booking_id' => $booking->id,
            'seats_cancelled' => $seatsCancelled,
            'total_paid' => $totalPaid,
            'refund_amount' => $refundAmount,
            'driver_amount' => $driverAmount,
            'policy_tier' => $refundPolicy['policy_tier'],
        ]);
    }

    /** Passenger side of a time-based cancellation: a refund row, or an audit row when the refund is zero. */
    public function recordPassengerRefund(Booking $booking, int $seatsCancelled, array $refundPolicy, float $refundAmount, Wallet $passengerWallet, float $passengerPrev, string $txId): void
    {
        $this->writer->recordPassengerRefund($booking, $seatsCancelled, $refundPolicy, $refundAmount, $passengerWallet, $passengerPrev, $txId);
    }

    /** Driver side of a time-based cancellation: the compensation row, when the driver is owed anything. */
    public function recordDriverCompensation(Ride $ride, Booking $booking, int $seatsCancelled, array $refundPolicy, float $driverAmount, Wallet $driverWallet, float $driverPrev, string $txId): void
    {
        $this->writer->recordDriverCompensation($ride, $booking, $seatsCancelled, $refundPolicy, $driverAmount, $driverWallet, $driverPrev, $txId);
    }

    /**
     * Split the affected bookings into the refundable set and the needs-review count.
     *
     * Confirmed e-pay rows with no snapshot were charged before RV-40 exists; they are reported,
     * never guessed at.
     *
     * @return array{0: Collection, 1: int}
     */
    private function splitStaffCancellationBookings(Ride $ride, Collection $bookings): array
    {
        $refundable = $bookings->filter(fn (Booking $b) => (float) $b->amount_paid > 0.0);

        // Confirmed e-pay rows with no snapshot: charged before RV-40 exists. Never fabricate an
        // amount from the current price; surface them for manual review instead.
        $needsReview = $bookings->filter(function (Booking $b) use ($refundable) {
            return (float) $b->amount_paid <= 0.0
                && ! $refundable->contains($b)
                && $b->status === 'confirmed';
        })->count();

        if ($needsReview > 0) {
            Log::warning('Staff cancellation found bookings with no money snapshot', [
                'ride_id' => $ride->id,
                'needs_review' => $needsReview,
                'hint' => 'run: php artisan bookings:backfill-money-snapshot, then retry',
            ]);
        }

        return [$refundable, $needsReview];
    }
}
