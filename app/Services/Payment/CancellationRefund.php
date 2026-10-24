<?php

namespace App\Services\Payment;

use App\Domain\ValueObjects\Money;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\PostingKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Money path split out of WalletTransactionService (behaviour unchanged). */
class CancellationRefund
{
    public function __construct(
        private readonly WalletAccess $access,
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

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
        $syCashWallet = $this->lockDriverCancellationSyCash($totalRefund);

        $escrowOutKey = $this->guardDriverCancellationEscrow($ride, $bookings);

        $txId = 'DRIVER_CANCEL_'.time().'_'.Str::random(6);

        $this->recordDriverCancellationEscrowRow($ride, $syCashWallet, $totalRefund, $escrowOutKey, $txId);

        foreach ($bookings as $booking) {
            $this->refundPassengerForDriverCancellation($ride, $booking, $txId);
        }

        $this->postDriverCancellationRefundLegs($ride, $bookings, $syCashWallet, $totalRefund);
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
        $syCashWallet = $this->lockStaffCancellationSyCash($totalRefund);

        $escrowOutKey = $this->guardStaffCancellationEscrow($ride, $refundable);

        $txId = 'STAFF_CANCEL_'.time().'_'.Str::random(6);

        $this->recordStaffCancellationEscrowRow($ride, $syCashWallet, $totalRefund, $escrowOutKey, $txId);

        foreach ($refundable as $booking) {
            $this->refundPassengerForStaffCancellation($ride, $booking, $txId);
        }

        $this->postStaffCancellationRefundLegs($ride, $refundable, $syCashWallet, $totalRefund);

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

        [$syCashWallet, $passengerWallet, $driverWallet] = $this->lockTimeBasedCancellationWallets(
            $booking, $ride, $totalPaid
        );

        $escrowOutKey = $this->guardTimeBasedCancellationEscrow($booking, $seatsCancelled, $totalPaid);

        [$syCashPrev, $passengerPrev, $driverPrev] = $this->applyTimeBasedCancellationBalances(
            $syCashWallet, $passengerWallet, $driverWallet, $totalPaid, $refundAmount, $driverAmount
        );

        $txId = 'TIME_CANCEL_'.time().'_'.Str::random(6);

        $this->recordTimeBasedCancellationEscrowRow(
            $booking, $syCashWallet, $totalPaid, $refundAmount, $driverAmount, $syCashPrev, $escrowOutKey, $txId
        );

        // Passenger refund (or an audit row for a zero refund)
        $this->recordPassengerRefund($booking, $seatsCancelled, $refundPolicy, $refundAmount, $passengerWallet, $passengerPrev, $txId);

        // Driver compensation
        $this->recordDriverCompensation($ride, $booking, $seatsCancelled, $refundPolicy, $driverAmount, $driverWallet, $driverPrev, $txId);

        $this->postTimeBasedCancellationLegs(
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

    /** Lock SyCash for a driver-cancellation refund and check it holds the whole refund. */
    private function lockDriverCancellationSyCash($totalRefund): Wallet
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
    private function guardDriverCancellationEscrow(Ride $ride, Collection $bookings): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // 100% of each booking's escrow goes back, so every booking's row is cleared to zero by
        // this call. The refund TIER is untouched — this only decides whether it may happen once.
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'driver-cancel', $bookings);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrowForSet(
            $bookings,
            fn (Booking $b) => (float) $b->seats * (float) $ride->price_per_seat
        );

        return $escrowOutKey;
    }

    /** The single SyCash debit row covering every passenger refund of a driver cancellation. */
    private function recordDriverCancellationEscrowRow(
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
    private function refundPassengerForDriverCancellation(Ride $ride, Booking $booking, string $txId): void
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
    private function postDriverCancellationRefundLegs(
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

    /** Lock SyCash for a staff-cancellation refund and check it holds the whole refund. */
    private function lockStaffCancellationSyCash($totalRefund): Wallet
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
    private function guardStaffCancellationEscrow(Ride $ride, Collection $refundable): string
    {
        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'staff-cancel', $refundable);
        $this->access->assertPostingKeyUnused($escrowOutKey);
        $this->access->debitEscrowForSet(
            $refundable,
            fn (Booking $b) => (float) $b->amount_paid
        );

        return $escrowOutKey;
    }

    /** The single SyCash debit row covering every passenger refund of a staff cancellation. */
    private function recordStaffCancellationEscrowRow(
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
    private function postStaffCancellationRefundLegs(
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

    /**
     * Lock SyCash, the passenger and the driver for a time-based cancellation, and check SyCash.
     *
     * @return array{0: Wallet, 1: Wallet, 2: Wallet}
     */
    private function lockTimeBasedCancellationWallets(Booking $booking, Ride $ride, $totalPaid): array
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
    private function guardTimeBasedCancellationEscrow(Booking $booking, int $seatsCancelled, $totalPaid): string
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

    /**
     * Move SyCash's money out to the passenger and the driver, returning the previous balances.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function applyTimeBasedCancellationBalances(
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
    private function recordTimeBasedCancellationEscrowRow(
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
    private function postTimeBasedCancellationLegs(
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
