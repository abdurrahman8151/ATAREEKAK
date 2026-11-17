<?php

namespace App\Modules\Ride\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * The three ride expressions the admin driver screens need, published as expressions rather than rows.
 *
 * WHY EXPRESSIONS AND NOT DTOs (Stage 2 C2). Two of these are EMBEDDED in Console's own `users` query - a
 * correlated `count(*)` and a correlated "newest non-null communication number" - because Console's driver
 * table is paginated and the aggregates must cost ZERO extra statements. `admin/drivers` is budgeted at 9
 * queries and a budget may only shrink, so a reader returning values (one query for the page) is not an
 * option there: it would make the endpoint 10. `withAvg`/`withCount` are what generated these expressions
 * before, from the `Ride` model; once the model belongs to this module, Console cannot name it, so the
 * owner publishes the expression itself and the caller embeds it in the same statement.
 *
 * The third is a plain scalar and is deliberately in the same contract: all three are one screen's reads,
 * and splitting them into "readers" and "expressions" would be two contracts for one caller.
 *
 * The contract names no model in its signatures - only a column name, a string and an int - so nothing
 * outside this module ever has to reference the Ride class.
 */
interface RideQueryExpressions
{
    /**
     * How many of a driver's rides are `finished`. ONE query; the caller gets a number.
     */
    public function completedRidesForDriver(int $driverId): int;

    /**
     * A correlated `count(*)` of the owner's ride rows, ready to embed:
     *
     *     ->addSelect(['rides_count' => $expressions->rideCountSubquery('users.id')])
     *
     * @param  string  $ownerKeyColumn  the outer column the rows are correlated to, e.g. `users.id`
     */
    public function rideCountSubquery(string $ownerKeyColumn): Builder;

    /**
     * The newest non-null `communication_number` among the owner's rides, ready to embed the same way.
     *
     * Ordering is `created_at DESC`, exactly as the previous inline expression had it, so ties resolve as
     * they did. Null when the owner has no ride with a number.
     *
     * @param  string  $ownerKeyColumn  the outer column the rows are correlated to, e.g. `users.id`
     */
    public function latestCommunicationNumberSubquery(string $ownerKeyColumn): Builder;
}
