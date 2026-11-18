<?php

namespace Tests\Support\Concerns;

use App\Modules\Money\Models\LedgerEntry;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletTransaction;
use App\Modules\Ride\Models\Booking;
use RuntimeException;

/**
 * The four properties every money movement must satisfy, in ONE place.
 *
 * Both the fixed-amount property test and the randomized one use these, so "what must always hold"
 * cannot drift between them: the fixed cases are the readable anchors, the randomized ones are the
 * search. Each assertion takes a `$context` string - the randomized test passes its seed and case
 * number - so a red line is reproducible without re-reading the failure.
 */
trait AssertsLedgerInvariants
{
    /** Every wallet balance in the database, to the fils. */
    protected function moneyInWallets(): float
    {
        return round((float) Wallet::query()->sum('balance'), 2);
    }

    /**
     * 1 + 2 + 3: the signed legs cancel, the total money is unchanged, and escrow is a
     * non-negative snapshot.
     */
    protected function assertInvariantsHold(float $moneyBefore, string $context): void
    {
        $ledgerSum = (float) LedgerEntry::query()->sum('amount');

        $this->assertSame(
            0.0,
            round($ledgerSum, 2),
            "{$context}: the signed ledger legs must sum to zero (debits equal credits), got {$ledgerSum}."
        );

        $moneyAfter = $this->moneyInWallets();

        $this->assertSame(
            round($moneyBefore, 2),
            $moneyAfter,
            "{$context}: money must be conserved - the wallet total moved from {$moneyBefore} to {$moneyAfter}."
        );

        $negativeEscrow = Booking::query()->where('escrow_held', '<', 0)->count();

        $this->assertSame(0, $negativeEscrow, "{$context}: escrow_held must never be negative, {$negativeEscrow} booking(s) are.");
    }

    /** Escrow is released in full, not merely non-negative. */
    protected function assertEscrowIsEmpty(Booking $booking, string $context): void
    {
        $this->assertSame(
            0.0,
            round((float) $booking->fresh()->escrow_held, 2),
            "{$context}: the movement must empty the escrow snapshot, it holds ".$booking->fresh()->escrow_held
        );
    }

    /**
     * 4: replay the same movement and prove it raised BEFORE touching anything.
     */
    protected function assertReplayChangesNothing(callable $replay, string $context): void
    {
        $ledgerRows = LedgerEntry::query()->count();
        $transactions = WalletTransaction::query()->count();
        $balances = Wallet::query()->orderBy('id')->pluck('balance', 'id')->toArray();
        $escrow = Booking::query()->orderBy('id')->pluck('escrow_held', 'id')->toArray();

        try {
            $replay();
            $this->fail("{$context}: replaying the same movement must be refused by its posting key.");
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/already posted|already been applied/i',
                $e->getMessage(),
                "{$context}: the refusal must say the movement was already posted, got: ".$e->getMessage()
            );
        }

        $this->assertSame($ledgerRows, LedgerEntry::query()->count(), "{$context}: the replay must not write a ledger leg.");
        $this->assertSame($transactions, WalletTransaction::query()->count(), "{$context}: the replay must not write a transaction row.");
        $this->assertSame($balances, Wallet::query()->orderBy('id')->pluck('balance', 'id')->toArray(), "{$context}: the replay must not move a balance.");
        $this->assertSame($escrow, Booking::query()->orderBy('id')->pluck('escrow_held', 'id')->toArray(), "{$context}: the replay must not move escrow.");
    }
}
