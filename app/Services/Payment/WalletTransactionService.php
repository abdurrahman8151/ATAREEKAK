<?php

namespace App\Services\Payment;

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

/**
 * WalletTransactionService
 *
 * Single source of truth for ALL money movements.
 *
 * ═══════════════════════════════════════════════════════
 *  MONEY FLOW
 * ═══════════════════════════════════════════════════════
 *
 *  BOOKING (e-pay):
 *    Passenger ──(100% of seats × price)──► SyCash (escrow)
 *
 *  RIDE COMPLETION:
 *    SyCash ──(95%)──► Driver
 *    SyCash ──( 5%)──► Primary Admin
 *
 *  DRIVER CANCELS RIDE:
 *    SyCash ──(100% per booking)──► each Passenger (full refund)
 *
 *  PASSENGER CANCELS (time-based, e-pay confirmed booking):
 *    SyCash ──(refund %)──► Passenger
 *    SyCash ──(rest   %)──► Driver
 *    Tiers (elapsed %):
 *      0–30%   → 100% passenger / 0%  driver
 *      30–50%  →  70% passenger / 30% driver
 *      50–70%  →  50% passenger / 50% driver
 *      70–100% →   0% passenger / 100% driver
 *
 *  PASSENGER NO-SHOW (e-pay):
 *    SyCash ──(95%)──► Driver
 *    SyCash ──( 5%)──► Primary Admin
 *
 *  DRIVER NO-SHOW (e-pay):
 *    SyCash ──(100%)──► Passenger
 *
 *  CASH rides: no wallet movements at all (payment offline).
 *  PRIMARY ADMIN: only ever receives money at completion or no-show.
 *  NO creation fee is charged to the driver.
 * ═══════════════════════════════════════════════════════
 */
class WalletTransactionService
{
    /**
     * Decision un3: the double-entry ledger is injected rather than resolved from the container
     * inside each method, so a test can substitute it and so the dependency is visible here instead
     * of being a hidden `app()` call on the money path.
     */
    public function __construct(
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

    // =========================================================================
    // BOOKING PAYMENT  (Passenger → SyCash)
    // =========================================================================

    /**
     * Charge passenger for an e-pay booking.
     * Full amount goes to SyCash (escrow) — driver receives nothing yet.
     *
     * Called when:
     *   - DIRECT booking confirmed
     *   - REQUEST booking accepted by driver
     */
    public function chargePassengerForBooking(Booking $booking, Ride $ride, User $passenger): void
    {
        $amount = $booking->seats * $ride->price_per_seat;

        // ── RV-02 L2 (D1): the replay guard, FIRST — before any wallet is locked or
        // any balance is written.
        //
        // Every caller wraps this in DB::transaction, so a guard placed after the balance
        // updates would still roll back. Placing it here means the method aborts having moved
        // NOTHING on its own account: the guarantee belongs to the money service, not to every
        // call site remembering to open a transaction. It is also the cheapest possible ordering
        // — a duplicate is rejected by one indexed read instead of after two wallet writes.
        //
        // Deterministic (`booking:{id}:escrow-in`), so a re-charge of the same booking collides
        // here rather than putting the passenger's money into SyCash twice.
        $escrowInKey = $this->postingKey('booking:'.$booking->id, 'escrow-in');
        $this->assertPostingKeyUnused($escrowInKey);

        // RV-09: acquire the GLOBAL serialization lock (SyCash) FIRST, then the
        // passenger's own wallet. Every other money path (release/refund/no-show) already
        // locks SyCash before any user wallet; charge was the lone exception, locking
        // passenger → SyCash. A user who is simultaneously a booking passenger and a
        // paid driver could therefore deadlock (MySQL 1213): this txn holding the
        // passenger wallet waiting for SyCash, a settlement holding SyCash waiting for
        // the same wallet. With SyCash first everywhere, all money ops serialize on it
        // and the inverted-order deadlock class cannot occur. Balance logic is unchanged.
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->lockWalletByUserId($passenger->id);

        $this->assertSufficientBalance(
            $passengerWallet,
            $amount,
            'Insufficient balance. Required: '.number_format($amount, 0).' SYP. '.
            'Current: '.number_format($passengerWallet->balance, 0).' SYP.'
        );

        $passengerPrev = $passengerWallet->balance;
        $syCashPrev = $syCashWallet->balance;

        $passengerWallet->balance -= $amount;
        $syCashWallet->balance += $amount;

        $passengerWallet->save();
        $syCashWallet->save();

        // RV-40: persist the money as an immutable snapshot on the booking, the moment it
        // is actually charged. Before this, nothing recorded what a booking paid; every
        // later refund/settlement re-derived `seats * ride.price_per_seat`, so a mutable
        // price or a partial-seat cancel silently changed what a paid booking "owed" and
        // the booking could disagree with its own ledger with no source of truth.
        //   unit_price     the per-seat price captured now
        //   amount_paid    the total actually moved off the passenger now
        //   payment_method snapshot so flipping the ride's method cannot change refunds
        // These three never change after the charge, so they are always truthful here.
        //   escrow_held    RV-02 L2: what this booking now has IN SyCash. Written here and
        //                  cleared by the guarded decrement on every settlement, so the column
        //                  answers "what does this booking still have sitting in escrow" — the
        //                  fact the settlement guards are built on. It is the whole amount
        //                  charged: this method runs only for e-pay (BookingService and
        //                  EPayPaymentStrategy both gate on it), so a cash booking never reaches
        //                  here and never needs a 0 written for it.
        $booking->forceFill([
            'unit_price' => $ride->price_per_seat,
            'amount_paid' => $amount,
            'payment_method' => $ride->payment_method,
            'escrow_held' => $amount,
        ])->save();

        $txId = 'RB_'.time().'_'.Str::random(8);

        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $passenger->id,
            'type' => 'ride_booking_payment',
            'amount' => -$amount,
            'previous_balance' => $passengerPrev,
            'new_balance' => $passengerWallet->balance,
            'description' => "Payment for {$booking->seats} seat(s): {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => $txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        WalletTransaction::create([
            'wallet_id' => $syCashWallet->id,
            'user_id' => null,
            'type' => 'escrow_received',
            'amount' => $amount,
            'previous_balance' => $syCashPrev,
            'new_balance' => $syCashWallet->balance,
            'description' => "Escrow received — {$passenger->first_name} {$passenger->last_name}, {$booking->seats} seat(s)",
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
            'posting_key' => $escrowInKey,
        ]);

        // ── Decision un3: double-entry legs ────────────────────────────────────
        // The two `wallet_transactions` rows above are the SINGLE-SIDED record: one row per wallet
        // that moved. These legs state that they are two halves of ONE movement - the passenger's
        // money out, SyCash's money in - summing to zero by construction.
        //
        // Nothing about the balances or the existing rows changes. The ledger is a WITNESS to this
        // transfer, not a second mover of money, and `LedgerService::postTransfer` refuses to write
        // legs that do not balance, so a half-written transfer cannot be recorded as complete.
        //
        // This is the reference implementation of the pattern; the remaining money paths convert the
        // same way, one at a time, each verified to leave balances unchanged.
        $this->ledger->postTransfer([
            [
                'wallet_id' => $passengerWallet->id,
                'amount' => -$amount,
                'description' => "booking #{$booking->id}: passenger debit",
            ],
            [
                'wallet_id' => $syCashWallet->id,
                'amount' => $amount,
                'description' => "booking #{$booking->id}: escrow credit",
            ],
        ]);

        Log::info('Passenger charged — escrow held in SyCash', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'amount' => $amount,
        ]);
    }

    // =========================================================================
    // RIDE COMPLETION  (SyCash → Driver 95% + Primary 5%)
    // =========================================================================

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

        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance(
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
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrowForSet(
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

        // Driver receives 95%
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

        // Primary receives 5%
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

    // =========================================================================
    // DRIVER CANCELS RIDE  (SyCash → each Passenger, 100%)
    // =========================================================================

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
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));

        $this->assertSufficientBalance(
            $syCashWallet,
            $totalRefund,
            "Insufficient SyCash balance for passenger refunds. Required: {$totalRefund}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // 100% of each booking's escrow goes back, so every booking's row is cleared to zero by
        // this call. The refund TIER is untouched — this only decides whether it may happen once.
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'driver-cancel', $bookings);
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrowForSet(
            $bookings,
            fn (Booking $b) => (float) $b->seats * (float) $ride->price_per_seat
        );

        $txId = 'DRIVER_CANCEL_'.time().'_'.Str::random(6);

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

        foreach ($bookings as $booking) {
            $refundAmount = $booking->seats * $ride->price_per_seat;
            $passengerWallet = $this->lockWalletByUserId($booking->user_id);
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
                    'wallet_id' => $this->lockWalletByUserId($booking->user_id)->id,
                    'amount' => (float) $booking->seats * (float) $ride->price_per_seat,
                    'description' => "ride #{$ride->id}: refund to booking #{$booking->id}",
                ];
            }

            $this->ledger->postTransfer($legs);
        }
    }

    // =========================================================================
    // STAFF-INITIATED CANCELLATION  (SyCash → Passenger, full refund)
    // =========================================================================

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

        if ($refundable->isEmpty()) {
            return [
                'refunded' => 0.0,
                'bookings' => 0,
                'needs_review' => $needsReview,
                'amount_source' => 'none',
            ];
        }

        $totalRefund = (float) $refundable->sum(fn (Booking $b) => (float) $b->amount_paid);
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));

        $this->assertSufficientBalance(
            $syCashWallet,
            $totalRefund,
            "Insufficient SyCash balance for staff-cancellation refunds. Required: {$totalRefund}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // Debited against the SAME `amount_paid` snapshot this method already refunds from, so
        // the escrow row can never disagree with the money about how much this booking held.
        // Only `$refundable` is touched: the needs-review rows were never charged, so they hold
        // no escrow, and this is deliberately not a second place to guess an amount at them.
        $escrowOutKey = PostingKey::buildForSet($ride->id, 'staff-cancel', $refundable);
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrowForSet(
            $refundable,
            fn (Booking $b) => (float) $b->amount_paid
        );

        $txId = 'STAFF_CANCEL_'.time().'_'.Str::random(6);

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

        foreach ($refundable as $booking) {
            $refundAmount = (float) $booking->amount_paid;
            $passengerWallet = $this->lockWalletByUserId($booking->user_id);
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
                    'wallet_id' => $this->lockWalletByUserId($booking->user_id)->id,
                    'amount' => (float) $booking->amount_paid,
                    'description' => "ride #{$ride->id}: refund to booking #{$booking->id}",
                ];
            }

            $this->ledger->postTransfer($legs);
        }

        return [
            'refunded' => $totalRefund,
            'bookings' => $refundable->count(),
            'needs_review' => $needsReview,
            'amount_source' => 'amount_paid',
        ];
    }

    // =========================================================================
    // TIME-BASED PASSENGER CANCELLATION  (SyCash → Passenger + Driver)
    // =========================================================================

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

        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->lockWalletByUserId($booking->user_id);
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance(
            $syCashWallet,
            $totalPaid,
            "Insufficient SyCash balance for cancellation refund. Required: {$totalPaid}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        // The refund TIER is untouched — this guards whether the movement may happen, not what
        // it pays. `$totalPaid` is debited, not the full booking: the seats that stay booked keep
        // their escrow, and `BookingService` reduces `$booking->seats` immediately afterwards, so
        // a later completion of the same booking releases exactly what is left here.
        //
        // THE KEY MUST DISTINGUISH A PARTIAL CANCEL FROM THE FULL SETTLEMENT — and successive
        // partial cancels from each other, which is why it names the REMAINING seat count rather
        // than the cancelled one or the amount. Cancelling one of two seats then the other is two
        // movements (`:cancel:1`, then `:cancel:0`); a single full cancel of two seats is the same
        // end state and correctly shares the final key. The caller still holds the PRE-cancel
        // `$booking->seats` at this point, so the arithmetic is exact and deterministic.
        $seatsRemaining = max(0, (int) $booking->seats - $seatsCancelled);
        $escrowOutKey = $this->postingKey('booking:'.$booking->id, 'escrow-out', 'cancel:'.$seatsRemaining);
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrow($booking, (float) $totalPaid);

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

        $txId = 'TIME_CANCEL_'.time().'_'.Str::random(6);

        // SyCash debit
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

        // Passenger refund
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

        // Driver compensation
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

        Log::info('Time-based cancellation processed', [
            'booking_id' => $booking->id,
            'seats_cancelled' => $seatsCancelled,
            'total_paid' => $totalPaid,
            'refund_amount' => $refundAmount,
            'driver_amount' => $driverAmount,
            'policy_tier' => $refundPolicy['policy_tier'],
        ]);
    }

    // =========================================================================
    // PASSENGER NO-SHOW  (SyCash → Driver 95% + Primary 5%)
    // =========================================================================

    /**
     * Passenger didn't show — E-PAY ride.
     * SyCash → Driver (95%) + SyCash → Primary (5%).
     */
    public function processPassengerNoShow(Booking $booking, Ride $ride, User $passenger): void
    {
        $total = $booking->seats * $ride->price_per_seat;
        // RV-19: one definition of the 95/5 split. This used to round each share independently,
        // which produced legs summing to one cent MORE than the escrow debit for any total ending
        // in an odd tenth (1000.50 -> 950.48 + 50.03 = 1000.51), so postTransfer() refused the
        // transfer and the ride could never complete. See App\Support\FeeSplit.
        ['driver' => $driverShare, 'platform' => $primaryShare] = FeeSplit::driverAndPlatform($total);

        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));

        $this->assertSufficientBalance(
            $syCashWallet,
            $total,
            "Insufficient SyCash balance for no-show settlement. Required: {$total}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        $escrowOutKey = $this->postingKey('booking:'.$booking->id, 'escrow-out', 'passenger-no-show');
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrow($booking, (float) $total);

        $syCashPrev = $syCashWallet->balance;
        $driverPrev = $driverWallet->balance;
        $primaryPrev = $primaryWallet->balance;

        $syCashWallet->balance -= $total;
        $driverWallet->balance += $driverShare;
        $primaryWallet->balance += $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        $txId = 'PASS_NOSHOW_'.time().'_'.Str::random(6);

        WalletTransaction::create([
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

        WalletTransaction::create([
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

        WalletTransaction::create([
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

        // ── Decision un3: double-entry legs (same 95/5 shape as ride completion) ─
        $this->ledger->postTransfer([
            ['wallet_id' => $syCashWallet->id, 'amount' => -$total, 'description' => "booking #{$booking->id}: no-show escrow released"],
            ['wallet_id' => $driverWallet->id, 'amount' => $driverShare, 'description' => "booking #{$booking->id}: driver share 95%"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $primaryShare, 'description' => "booking #{$booking->id}: platform fee 5%"],
        ]);

        Log::info('Passenger no-show settled', [
            'booking_id' => $booking->id,
            'driver_share' => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }

    // =========================================================================
    // DRIVER NO-SHOW  (SyCash → Passenger 100%)
    // =========================================================================

    /**
     * Driver didn't show — E-PAY ride.
     * SyCash → Passenger (100% refund).
     */
    public function processDriverNoShowRefund(Ride $ride, Booking $booking, User $passenger): void
    {
        $refundAmount = $booking->seats * $ride->price_per_seat;
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->lockWalletByUserId($passenger->id);

        $this->assertSufficientBalance(
            $syCashWallet,
            $refundAmount,
            "Insufficient SyCash balance for driver no-show refund. Required: {$refundAmount}"
        );

        // ── RV-02 L2 (D1): both escrow guards, before any balance moves ────────
        $escrowOutKey = $this->postingKey('booking:'.$booking->id, 'escrow-out', 'driver-no-show');
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrow($booking, (float) $refundAmount);

        $syCashPrev = $syCashWallet->balance;
        $passengerPrev = $passengerWallet->balance;

        $syCashWallet->balance -= $refundAmount;
        $passengerWallet->balance += $refundAmount;

        $syCashWallet->save();
        $passengerWallet->save();

        $txId = 'DRIVER_NOSHOW_'.time().'_'.Str::random(6);

        WalletTransaction::create([
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

        WalletTransaction::create([
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

        // ── Decision un3: double-entry legs (SyCash → passenger, 100%) ───────────
        $this->ledger->postTransfer([
            ['wallet_id' => $syCashWallet->id, 'amount' => -$refundAmount, 'description' => "booking #{$booking->id}: driver no-show refund out"],
            ['wallet_id' => $passengerWallet->id, 'amount' => $refundAmount, 'description' => "booking #{$booking->id}: refund to passenger"],
        ]);

        Log::info('Driver no-show refund processed', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'refund_amount' => $refundAmount,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * RV-02 L2 (owner decision D1 = A) — escrow guards
     * ══════════════════════════════════════════════════════════════════════════
     *
     * TWO GUARDS, AND WHY BOTH.
     *
     * `wallet_transactions.transaction_id` was never an idempotency key: it is minted from
     * `time()` plus randomness, so a replay produced a brand-new, ordinary-looking row and
     * nothing in the schema could tell it from the original. Every escrow movement therefore ran
     * again on a replay and paid again. Two independent, deterministic guards close that:
     *
     *   1. POSTING KEY — is this exact movement already recorded? The key is derived from the
     *      booking/ride and the action, never from a clock, so the same logical movement
     *      recomputes the same key. The unique index added by
     *      `2026_10_08_000001_add_posting_key_to_wallet_transactions` is the database backstop;
     *      `assertPostingKeyUnused` is the explicit early check that fails BEFORE any balance is
     *      touched, so a replay never gets as far as writing money and then unwinding.
     *
     *   2. ESCROW HELD — is the booking still holding enough? `escrow_held` is what the booking
     *      has actually put into SyCash and not yet taken out. The decrement is CONDITIONAL
     *      (`WHERE escrow_held >= :amount`) and the caller aborts unless exactly one row changed,
     *      so a booking that has already given up its escrow cannot give it up again.
     *
     * Either guard alone would be sufficient against a literal replay, but they fail differently:
     * the posting key catches "the same movement ran twice", while the escrow guard catches "this
     * booking's escrow is already spent" even when the movements differ (a full completion after a
     * partial cancel, say). Together a settlement can neither repeat nor overdraw.
     *
     * FAIL LOUD, DO NOT SILENTLY SKIP. Every one of these throws \RuntimeException, the type this
     * service already uses for `assertSufficientBalance`, so the surrounding transaction unwinds
     * with nothing half-written and no HTTP status changes. A settlement that cannot be proven
     * safe must not quietly succeed.
     *
     * WHAT THIS DOES NOT CHANGE. Not one amount. The 95/5 split, all six refund tiers, and every
     * `seats * ride.price_per_seat` derivation stay exactly as they were; this is bookkeeping
     * (the owner's word) over money that already moved the same way.
     */

    /**
     * Format an amount for safe interpolation into raw SQL.
     *
     * Two decimals, no thousands separator, no locale, no exponent: `escrow_held` is
     * decimal(15,2), so anything else could round differently from the stored value or arrive as
     * `1.0E+3` — which is valid PHP string output and invalid arithmetic here.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Build the deterministic posting key for one movement.
     *
     * A thin delegate to {@see PostingKey} rather than a second implementation: a guard that
     * exists twice is a guard that drifts, and this one is the difference between paying once and
     * paying twice.
     */
    private function postingKey(string ...$segments): string
    {
        return PostingKey::build(...$segments);
    }

    /**
     * Guard 1 — refuse a movement whose posting key is already recorded.
     *
     * Runs before any balance is written, so a replay aborts having moved nothing.
     */
    private function assertPostingKeyUnused(string $postingKey): void
    {
        PostingKey::assertUnused($postingKey);
    }

    /**
     * Guard 2 — take `amount` out of a booking's escrow, conditionally.
     *
     * The guard is in the WHERE clause, not a read-then-write: `escrow_held >= :amount` is
     * evaluated by the database as part of the UPDATE, so two concurrent settlements cannot both
     * see the pre-decrement value and both proceed. Exactly one row changed, or the caller aborts.
     *
     * @throws \RuntimeException when the booking does not hold that much (already settled, or
     *                           never charged at all — both are reasons not to pay).
     */
    private function debitEscrow(Booking $booking, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $affected = DB::table('bookings')
            ->where('id', $booking->id)
            ->where('escrow_held', '>=', $this->money($amount))
            ->update([
                'escrow_held' => DB::raw('escrow_held - '.$this->money($amount)),
            ]);

        if ($affected !== 1) {
            $held = (float) (DB::table('bookings')->where('id', $booking->id)->value('escrow_held') ?? 0);

            throw new \RuntimeException(
                "RV-02 L2: booking #{$booking->id} does not hold {$this->money($amount)} in escrow "
                ."(holds {$this->money($held)}). Refusing to settle: this booking's escrow has "
                .'already been spent, or it was never charged.'
            );
        }
    }

    /**
     * Guard 2 over a set of bookings, for a ride-level settlement.
     *
     * Each booking is decremented on its OWN row: one ride-level wallet movement can still cover
     * several bookings, and the escrow is per booking. Any booking short of its share aborts the
     * whole operation — a partial settlement is exactly the half-applied state this task removes.
     */
    private function debitEscrowForSet(Collection $bookings, callable $amountFor): void
    {
        foreach ($bookings as $booking) {
            $this->debitEscrow($booking, (float) $amountFor($booking));
        }
    }

    private function lockWalletByUserId(int $userId): Wallet
    {
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

        if (! $wallet) {
            throw new \RuntimeException("Wallet not found for user ID: {$userId}");
        }

        return $wallet;
    }

    private function lockWalletByPhone(string $phone): Wallet
    {
        // RV-21: resolve the SYSTEM wallet by phone, and refuse any non-system row.
        // This lookup takes the "escrow sink" role in every money path, so it MUST
        // only ever return a platform-owned wallet (user_id IS NULL). It previously
        // matched on phone alone, so a user who registered the SyCash/Primary phone
        // (config defaults 0987654321 / 0912345678 are in the repo) BEFORE the
        // seeder ran would own the wallet that receives all escrow, refunds and
        // platform fees — i.e. money routed to a hijacker. Filtering to
        // user_id NULL and failing closed (rather than silently using a user-owned
        // row) makes that impossible, and cannot affect a correctly-seeded system
        // wallet. The seeder's own firstOrCreate(['phone_number'=>…]) is the second
        // half of this (it should not adopt a claimed phone); that belongs with the
        // wallets.kind refactor in §26.4, not this boundary fix.
        //
        // Decision un3: `kind = system` is now the PRIMARY condition and `user_id IS NULL` is kept
        // as a second, independent one. Keeping both means this defence no longer rests on a single
        // column: a `kind = system` row that somehow acquired a user_id would still be refused here
        // rather than becoming the escrow sink.
        $wallet = Wallet::where('phone_number', $phone)
            ->where('kind', WalletKind::SYSTEM->value)
            ->whereNull('user_id')
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            throw new \RuntimeException(
                "System wallet not found for phone: {$phone}. Either it is not seeded ".
                '(run: php artisan db:seed --class=SystemWalletSeeder) or a user-owned '.
                'wallet has claimed that phone number, which is rejected here so escrow '.
                'is never routed to a normal account.'
            );
        }

        return $wallet;
    }

    private function assertSufficientBalance(Wallet $wallet, float $required, string $message): void
    {
        if ($wallet->balance < $required) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * PASTE THIS METHOD INTO:
     *   app/Services/Payment/WalletTransactionService.php
     *
     * PLACE IT after chargePassengerForBooking() — they are paired operations.
     *
     * IMPORTS TO ADD at the top of WalletTransactionService if not already there:
     *   use App\Enums\WalletKind;
use App\Models\Booking;
     *   use App\Models\Ride;
     *   use App\Models\User;
     *   use App\Models\Wallet;
     *   use App\Models\WalletTransaction;
     *   use App\Models\Employee;
     *   use Illuminate\Support\Facades\DB;
     *   use Illuminate\Support\Facades\Log;
     * ══════════════════════════════════════════════════════════════════════════
     */

    /**
     * Release a single passenger's escrowed funds to the driver.
     *
     * Called once per passenger when they confirm trip completion.
     * Money flow:
     *   admin escrow wallet  →  driver wallet (95%)
     *                        →  SyCash wallet (5%)
     *
     * Must be called inside a DB::transaction() — the caller (BookingService)
     * wraps the whole confirmation in one.
     *
     * @throws \RuntimeException if the escrow or driver wallet cannot be found,
     *                           or if the escrow has insufficient balance.
     */

    /**
     * ══════════════════════════════════════════════════════════════════════════════
     * FIND and REPLACE the entire releaseEscrowToDriver() method in:
     *   app/Services/Payment/WalletTransactionService.php
     *
     * The old version deducted from system_admin's wallet, which is WRONG.
     * The escrow is held in the SyCash wallet (chargePassengerForBooking puts
     * money there), so the release must come FROM SyCash too.
     *
     * This version mirrors releaseEarningsToDriver() but for ONE booking,
     * not the whole ride — so it can be called per-passenger.
     * ══════════════════════════════════════════════════════════════════════════════
     *
     * MONEY FLOW (matches chargePassengerForBooking exactly):
     *   SyCash (escrow)  →  Driver wallet   (95%)
     *   SyCash (escrow)  →  Primary Admin   (5%)
     */

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
        $total = round((float) ($booking->seats * $ride->price_per_seat), 2);
        // RV-19: routed through the same helper as the other two sites. This one already
        // subtracted, which is why it was the only path that worked for the affected totals.
        ['driver' => $driverShare, 'platform' => $primaryShare] = FeeSplit::driverAndPlatform($total);

        // ── Lock all three wallets (same order as everywhere else to prevent deadlock) ──
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet = $this->lockWalletByUserId($driver->id);

        // ── Guard: SyCash must have enough to release ─────────────────────────────
        $this->assertSufficientBalance(
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
        $escrowOutKey = $this->postingKey('booking:'.$booking->id, 'escrow-out', 'release');
        $this->assertPostingKeyUnused($escrowOutKey);
        $this->debitEscrow($booking, $total);

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
