<?php

namespace App\Services\Payment;

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

        // Normalise to 2dp before summing. Comparing floats that have not been rounded is how a
        // transfer of 10.005 + (-10.005) gets rejected for "not balancing" when it does.
        $sum = 0.0;
        foreach ($legs as $leg) {
            $sum += round((float) $leg['amount'], 2);
        }
        $sum = round($sum, 2);

        if ($sum !== 0.0) {
            throw new RuntimeException(
                'Refusing to post an unbalanced ledger transfer: legs sum to '.$sum
                .' (expected 0.00). Money is only conserved if every credit has a matching debit; '
                .'an unbalanced entry is either a bug or a half-written movement, and recording it '
                .'would make the ledger lie.'
            );
        }

        $written = [];

        DB::transaction(function () use ($legs, $transaction, &$written) {
            foreach ($legs as $leg) {
                $entry = LedgerEntry::create([
                    'wallet_id' => $leg['wallet_id'],
                    'wallet_transaction_id' => $transaction?->id,
                    'amount' => round((float) $leg['amount'], 2),
                    'description' => $leg['description'] ?? null,
                ]);

                $written[] = $entry;
            }
        });

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
        $amount = round($amount, 2);

        return $this->postTransfer([
            [
                'wallet_id' => $from->id,
                'amount' => -$amount,
                'description' => $description ? "debit: {$description}" : null,
            ],
            [
                'wallet_id' => $to->id,
                'amount' => $amount,
                'description' => $description ? "credit: {$description}" : null,
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

        return round((float) $sum, 2);
    }
}
