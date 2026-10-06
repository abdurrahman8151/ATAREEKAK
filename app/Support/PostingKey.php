<?php

namespace App\Support;

use App\Models\WalletTransaction;
use RuntimeException;

/**
 * RV-02 L2 (owner decision D1 = A) — deterministic idempotency keys for money movements.
 *
 * THE DEFECT THIS EXISTS TO CLOSE
 *
 * `wallet_transactions.transaction_id` looks like an idempotency key and is not one. It is minted
 * from `time()` plus randomness — `'RB_'.time().'_'.Str::random(8)` — so re-running the same
 * logical movement produced a completely different value, nothing enforced its uniqueness, and it
 * could not be recomputed from the facts of the movement. A replayed settlement therefore wrote a
 * new, entirely ordinary-looking transaction every time and paid again. Nothing in the schema
 * recorded that the movement had already happened.
 *
 * `posting_key` (migration `2026_10_08_000001`) is that record. It is derived ONLY from what the
 * movement IS — the booking or ride, and the action — never from when it ran. That determinism is
 * the whole mechanism: a later run of the same movement recomputes the same key, the unique index
 * refuses the second INSERT, and the surrounding transaction unwinds with no balance written.
 *
 * WHY A SHARED HELPER RATHER THAN A PRIVATE METHOD PER SERVICE
 *
 * Both money services need the identical guarantee, and a guard that exists twice is a guard that
 * will drift. The check lives here once; each service still decides WHICH movements are once-only
 * (see the note on `autoClearDebt` in `CashRideFeeService`, which is deliberately NOT keyed).
 *
 * FAIL LOUD. `assertUnused` throws before the caller writes a single balance, so a replay aborts
 * having moved nothing rather than writing money and unwinding afterwards. The unique index
 * remains the backstop for the concurrent case, where two transactions both pass the read.
 */
final class PostingKey
{
    /**
     * Join segments into a key.
     *
     * Exposed so a caller cannot quietly introduce a time- or random-derived segment: every key
     * in the system is built here, and {@see self::assertUnused} only means something if the key
     * is a function of the movement's identity.
     */
    public static function build(string ...$segments): string
    {
        return implode(':', $segments);
    }

    /**
     * Build a key for one movement that settles a SET of bookings as a single ride-level
     * operation.
     *
     * The booking ids are sorted, so the key does not depend on the order the collection happened
     * to be built in — two runs over the same set must produce the same key. The set itself is
     * part of the key, which is what makes cancelling booking #5 alone and then booking #7 a
     * different movement rather than a collision.
     *
     * @param  iterable<int, mixed>  $bookings  Anything with an `id` (Booking, or an id itself).
     */
    public static function buildForSet(int|string $rideId, string $action, iterable $bookings): string
    {
        $ids = [];

        foreach ($bookings as $booking) {
            $ids[] = (int) (is_object($booking) ? $booking->id : $booking);
        }

        sort($ids);
        $joined = $ids === [] ? 'none' : implode('-', $ids);

        return self::build('ride:'.$rideId, 'escrow-out', $action, $joined);
    }

    /**
     * Refuse a movement whose posting key has already been recorded.
     *
     * @throws RuntimeException when the movement has already been applied.
     */
    public static function assertUnused(string $postingKey): void
    {
        if (WalletTransaction::where('posting_key', $postingKey)->exists()) {
            throw new RuntimeException(
                "RV-02 L2: money movement already posted (posting_key={$postingKey}). "
                .'This movement has already been applied and cannot be applied again.'
            );
        }
    }
}
