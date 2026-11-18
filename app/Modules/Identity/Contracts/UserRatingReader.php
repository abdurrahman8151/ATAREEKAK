<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\DTOs\UserRatingStats;

/**
 * Stage 2 C1/C2: `user_ratings` is Identity's table; the admin surfaces ask here instead of querying it.
 *
 * Three Console services ran the same query - `COUNT(*) as total, ROUND(AVG(rating), 2) as average` for one
 * `rated_user_id` - and one of them typed its own return value as the model. The aggregate is a fact about a
 * user, not a row anyone outside Identity needs to hold.
 */
interface UserRatingReader
{
    /**
     * The rating count and average for one user, as the single aggregate row returns them.
     */
    public function statsFor(int $userId): UserRatingStats;

    /**
     * The platform-wide average across users who are verified drivers - one number for the dashboard card.
     *
     * The verified-driver filter lives in this module because it walks `user_ratings` to `users`, and both
     * tables are Identity's. Returning the raw `mixed` keeps the caller's existing rounding expression
     * (`$avg ? round((float) $avg, 2) : 0.0`) in charge of the shape it puts in the response.
     */
    public function averageAcrossVerifiedDrivers(): mixed;

    /**
     * Stage 2 C2: the batched average per user - ONE grouped query for a whole page.
     *
     * This is the replacement for the kernel's `User::receivedRatings()` relation and the `withAvg(...)`
     * calls that leaned on it: the ride and booking resources render a rating per row, and the admin driver
     * list renders one per driver, so the read has to stay one query per page, never one per row.
     *
     * @param  int[]  $userIds
     * @return array<int, float> keyed by user id; users with no ratings are ABSENT (callers default to 0)
     */
    public function averageForUsers(array $userIds): array;

    /**
     * Stage 2 C2: the rating ROWS for a page, keyed by rated user id - ONE query.
     *
     * This exists for the endpoints that serialize RAW models: their payload nests `received_ratings`, and the
     * kernel relation that used to supply it is gone (owner ruling 2026-11-17). Returning the rows through the
     * port keeps that public key byte-identical without letting another module name the model, and keeps the
     * read at one query per page - the same query the eager load cost.
     *
     * @param  int[]  $userIds
     * @return array<int, array<int, array<string, mixed>>> rows as the model serializes them
     */
    public function rowsForUsers(array $userIds): array;
}
