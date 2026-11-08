<?php

namespace App\Modules\Identity\DTOs;

/**
 * Stage 2 C1/C2: one user's rating aggregate, as another module may see it.
 *
 * `total` and `average` are `mixed` on purpose, for the same reason `WalletSummary::balance` is: the query
 * returns them as raw driver values - a decimal string for `ROUND(AVG(rating), 2)`, null when nobody has
 * rated - and callers put them straight into responses (`'average' => $stats->average`). Casting here would
 * turn `"4.50"` into `4.5` and change a response for nobody's benefit.
 *
 * The property names are the ones the existing call sites already read, so replacing
 * `UserRating::...->selectRaw(...)->first()` with this DTO needs no change at the read site.
 */
final class UserRatingStats
{
    public function __construct(
        public readonly mixed $total,
        public readonly mixed $average,
    ) {}

    /**
     * The shape a caller gets when there is no row at all. The aggregate query always returns one row, so
     * this exists for readers that short-circuit an empty id.
     */
    public static function empty(): self
    {
        return new self(total: 0, average: null);
    }
}
