<?php

namespace App\Services\Payment;

use App\Domain\ValueObjects\Money;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Decision un3 (owner, 2026-10-02): double-entry.
 *
 * THE INVARIANT, stated once: **every transfer conserves money, so its signed legs must sum to
 * zero.** That single rule is what makes a ledger trustworthy - it is checkable, and it fails loudly
 * the moment half a movement is written. The single-sided `wallet_transactions` table cannot express
 * it, which is why it could never catch a half-written transfer.
 *
 * DELIBERATELY ADDITIVE. `postTransfer()` does NOT move balances. The existing money paths already
 * do that, inside their own transactions and with their own locking. This service records WHAT
 * MOVED; it does not decide who moves it. Conflating the two would mean rewriting the locking and
 * the idempotency of 31 call sites at once - the thing this design exists to avoid.
 *
 * So a converted path looks like: do exactly what it did before (adjust balances, write the
 * single-sided row), then call `postTransfer()` to record the legs. If the two disagree, the test
 * fails. The ledger is a witness, not a new mover of money.
 */
class LedgerService
{
    /**
     * Record a transfer as balanced ledger legs.
     *
     * @param  array<int, array{wallet_id: int, amount: float, description?: string|null}>  $legs
     *                                                                                             Signed amounts. A debit is negative; a credit is positive.
     * @param  WalletTransaction|null  $transaction  the single-sided row, when one exists
     * @return LedgerEntry[] the written legs
     *
     * @throws RuntimeException if the legs do not sum to zero, or fewer than two are supplied
     */
    public function postTransfer(array $legs, ?WalletTransaction $transaction = null): array
    {
        if (count($legs) < 2) {
            throw new RuntimeException(
                'A double-entry transfer needs at least two legs (someone gives, someone receives); '
                .count($legs).' given. A one-legged entry is a single-sided record, which is exactly '
                .'what the ledger replaces.'
            );
        }

        // `R2 sec 117`: the balance check is the whole point of this class, and it used to be decided
        // in FLOAT - each leg `round()`ed to 2dp and the running total compared to `0.0` with `!==`.
        // That is a float sum being trusted to answer "is this exactly zero?". Summing in integer minor
        // units via `Money` asks the question in the representation money actually has, so the check is
        // exact rather than approximately exact. Rounding is no longer decided per call site either: it
        // happens once, inside `Money::from()`.
        $sum = Money::zero();
        foreach ($legs as $leg) {
            $sum = $sum->add(Money::from((float) $leg['amount']));
        }

        if (! $sum->isZero()) {
            throw new RuntimeException(
                'Refusing to post an unbalanced ledger transfer: legs sum to '.$sum->formatted()
                .' (expected 0.00 SYP). Money is only conserved if every credit has a matching debit; '
                .'an unbalanced entry is either a bug or a half-written movement, and recording it '
                .'would make the ledger lie.'
            );
        }

        $written = [];

        // RV-09(a): this transaction retries on a concurrency error (see the attempts argument
        // below). Laravel rolls the attempt back before retrying, but `$written` lives in the
        // ENCLOSING scope - so a retry would append this attempt's legs to the previous
        // attempt's and the caller would receive duplicates of rows that no longer exist.
        // Resetting it inside the closure is what makes the retry safe to re-run.
        DB::transaction(function () use ($legs, $transaction, &$written) {
            $written = [];

            foreach ($legs as $leg) {
                $entry = LedgerEntry::create([
                    'wallet_id' => $leg['wallet_id'],
                    'wallet_transaction_id' => $transaction?->id,
                    // Same normalisation as the balance check above, and for the same reason: what is
                    // stored is what was checked. Rounding to 2dp happens once, in `Money::from()`.
                    'amount' => Money::from((float) $leg['amount'])->amount(),
                    'description' => $leg['description'] ?? null,
                ]);

                $written[] = $entry;
            }
        }, attempts: 3);

        return $written;
    }

    /**
     * Convenience for the common two-party case: money leaves one wallet and enters another.
     *
     * The amount is stated ONCE and the signs are derived here, because the most common way to write
     * an unbalanced pair by hand is to get one sign wrong.
     *
     * @return LedgerEntry[] the debit leg and the credit leg, in that order
     */
    public function postTwoPartyTransfer(
        Wallet $from,
        Wallet $to,
        float $amount,
        ?WalletTransaction $transaction = null,
        ?string $description = null,
    ): array {
        // `R2 sec 117`: the amount is stated once and the two signs are DERIVED from it, in integer
        // minor units. `negated()` exists for exactly this - the raw unary minus this used is the
        // reason a signed money type was needed at all.
        $amount = Money::from($amount);

        return $this->postTransfer([
            [
                'wallet_id' => $from->id,
                'amount' => $amount->negated()->amount(),
                'description' => $description ? "debit: {$description}" : null,
            ],
            [
                'wallet_id' => $to->id,
                'amount' => $amount->amount(),
                'description' => $description ? "credit: {$description}" : null,
            ],
        ], $transaction);
    }

    /**
     * Convenience for the EXTERNAL flows (owner choice (a), 2026-10-03): money entering or leaving
     * the platform from outside it.
     *
     * An injection (an admin wallet credit funded by a real deposit) DEBITS the external account and
     * credits the user wallet; a withdrawal is the mirror image. That is what makes the ledger CLOSED
     * rather than mostly-right - every movement now has both halves, so "the ledger explains the
     * money" is a statement that can be checked instead of one with a permanent hole in it.
     *
     * The external account is NOT the platform's earnings wallet. Conflating them would make the
     * revenue figure wrong: money the platform EARNED and money that ARRIVED are different things.
     */
    public function postExternalTransfer(
        Wallet $external,
        Wallet $target,
        float $amount,
        bool $inbound,
        ?WalletTransaction $transaction = null,
        ?string $description = null,
    ): array {
        // `R2 sec 117`: signed `Money` replaces the raw unary minus, so the two legs cannot disagree
        // about a sign - the second is the negation OF the first, not a second independent expression.
        $amount = Money::from($amount);

        // $inbound: money ARRIVES at $target, so it leaves the external account.
        // !$inbound: a withdrawal leaves $target for the outside world.
        $externalLeg = $inbound ? $amount->negated() : $amount;
        $targetLeg = $externalLeg->negated();

        $direction = $inbound ? 'inbound' : 'outbound';

        return $this->postTransfer([
            [
                'wallet_id' => $external->id,
                'amount' => $externalLeg->amount(),
                'description' => $description ? "{$direction} external: {$description}" : "{$direction} external",
            ],
            [
                'wallet_id' => $target->id,
                'amount' => $targetLeg->amount(),
                'description' => $description ? "{$direction} wallet: {$description}" : "{$direction} wallet",
            ],
        ], $transaction);
    }

    /**
     * Does the ledger agree with itself for one transfer? Used by tests and by the reporting path.
     *
     * Returns the signed sum in 2dp; 0.00 means balanced.
     */
    public function imbalanceFor(WalletTransaction $transaction): float
    {
        $sum = LedgerEntry::where('wallet_transaction_id', $transaction->id)
            ->sum('amount');

        // `R2 sec 117`: exact in minor units, same as the check that produced it.
        return Money::from((float) $sum)->amount();
    }
}
