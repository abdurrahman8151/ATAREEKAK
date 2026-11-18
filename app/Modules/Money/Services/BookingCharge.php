<?php

namespace App\Modules\Money\Services;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Modules\Money\Contracts\BookingChargeInterface;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Money path split out of WalletTransactionService (behaviour unchanged). */
class BookingCharge implements BookingChargeInterface
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
    public function chargePassengerForBooking($booking, $ride, User $passenger): void
    {
        $amount = $booking->seats * $ride->price_per_seat;

        $escrowInKey = $this->guardAgainstDoubleCharge($booking);

        [$syCashWallet, $passengerWallet] = $this->lockChargeWallets($passenger, $amount);

        [$passengerPrev, $syCashPrev] = $this->movePassengerFundsIntoEscrow($passengerWallet, $syCashWallet, $amount);

        $this->snapshotBookingCharge($booking, $ride, $amount);

        $txId = 'RB_'.time().'_'.Str::random(8);

        // Stage 2 B2: the rows are returned keyed by wallet id so the legs can name their own side.
        $chargeTransactions = $this->recordChargeTransactions(
            $booking, $ride, $passenger, $passengerWallet, $syCashWallet,
            $amount, $passengerPrev, $syCashPrev, $txId, $escrowInKey
        );

        $this->postChargeLegs($booking, $passengerWallet, $syCashWallet, $amount, $chargeTransactions);

        Log::info('Passenger charged — escrow held in SyCash', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'amount' => $amount,
        ]);
    }

    /**
     * The replay guard, FIRST — before any wallet is locked or any balance is written.
     */
    private function guardAgainstDoubleCharge($booking): string
    {
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

        return $escrowInKey;
    }

    /**
     * Lock the global escrow wallet before the passenger's own wallet, then check the balance.
     *
     * @return array{0: Wallet, 1: Wallet}
     */
    private function lockChargeWallets(User $passenger, $amount): array
    {
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

        return [$syCashWallet, $passengerWallet];
    }

    /**
     * Move the amount off the passenger and into SyCash, returning both previous balances.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function movePassengerFundsIntoEscrow(Wallet $passengerWallet, Wallet $syCashWallet, $amount): array
    {
        $passengerPrev = $passengerWallet->balance;
        $syCashPrev = $syCashWallet->balance;

        $passengerWallet->balance -= $amount;
        $syCashWallet->balance += $amount;

        $passengerWallet->save();
        $syCashWallet->save();

        return [$passengerPrev, $syCashPrev];
    }

    /** Persist what this booking paid as an immutable snapshot on the booking itself. */
    private function snapshotBookingCharge($booking, $ride, $amount): void
    {
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
    }

    /**
     * Write the single-sided wallet_transactions rows for this charge.
     *
     * Stage 2 B2: returns them keyed by wallet id, so the double-entry legs can name the row that
     * recorded THEIR side's movement.
     *
     * @return array<int, WalletTransaction>
     */
    private function recordChargeTransactions(
        $booking,
        $ride,
        User $passenger,
        Wallet $passengerWallet,
        Wallet $syCashWallet,
        $amount,
        $passengerPrev,
        $syCashPrev,
        string $txId,
        string $escrowInKey
    ): array {
        $passengerTransaction = WalletTransaction::create([
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

        $syCashTransaction = WalletTransaction::create([
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

        return [
            $passengerWallet->id => $passengerTransaction,
            $syCashWallet->id => $syCashTransaction,
        ];
    }

    /**
     * Post the double-entry legs that witness the passenger → SyCash movement.
     *
     * @param  array<int, WalletTransaction>  $transactions  keyed by wallet id (stage 2 B2)
     */
    private function postChargeLegs($booking, Wallet $passengerWallet, Wallet $syCashWallet, $amount, array $transactions = []): void
    {
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
                'wallet_transaction_id' => $transactions[$passengerWallet->id]->id ?? null,
            ],
            [
                'wallet_id' => $syCashWallet->id,
                'amount' => $amount,
                'description' => "booking #{$booking->id}: escrow credit",
                'wallet_transaction_id' => $transactions[$syCashWallet->id]->id ?? null,
            ],
        ]);
    }
}
