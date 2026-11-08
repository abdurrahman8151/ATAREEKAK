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
}
