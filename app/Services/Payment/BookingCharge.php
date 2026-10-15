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
class BookingCharge
{

    public function __construct(
        private readonly WalletAccess $access,
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

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
        $escrowInKey = $this->access->postingKey('booking:'.$booking->id, 'escrow-in');
        $this->access->assertPostingKeyUnused($escrowInKey);

        // RV-09: acquire the GLOBAL serialization lock (SyCash) FIRST, then the
        // passenger's own wallet. Every other money path (release/refund/no-show) already
        // locks SyCash before any user wallet; charge was the lone exception, locking
        // passenger → SyCash. A user who is simultaneously a booking passenger and a
        // paid driver could therefore deadlock (MySQL 1213): this txn holding the
        // passenger wallet waiting for SyCash, a settlement holding SyCash waiting for
        // the same wallet. With SyCash first everywhere, all money ops serialize on it
        // and the inverted-order deadlock class cannot occur. Balance logic is unchanged.
        $syCashWallet = $this->access->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->access->lockWalletByUserId($passenger->id);

        $this->access->assertSufficientBalance(
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
}
