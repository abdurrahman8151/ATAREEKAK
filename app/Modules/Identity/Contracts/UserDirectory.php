<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\DTOs\UserSummary;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage 2 C3: the read surface for user data, owned by IDENTITY.
 *
 * Every other module that needs a name, an email or a verification flag for a user id calls this
 * instead of querying `users`. The batched form is the one to prefer: a list screen that calls
 * `summaryFor()` in a loop is an N+1 that the query budgets in the snapshot suite will catch, and the
 * reason `summariesFor()` exists is so the correct call is also the convenient one.
 *
 * Stage 2 C3: the verification queues were the first screens whose whole LIST lives on `users` (filtered by
 * `verification_status`), so the query itself moved here rather than staying in Console.
 */
interface UserDirectory
{
    public function summaryFor(int $userId): ?UserSummary;

    /**
     * @param  int[]  $userIds
     * @return array<int, UserSummary> keyed by user id; ids that do not exist are absent
     */
    public function summariesFor(array $userIds): array;

    /**
     * Every user whose `verification_status` is `pending`, as summaries.
     *
     * `$newestFirst` reproduces the staff queue's `orderByDesc('updated_at')`; the admin queue wants the
     * engine's own order, which is what the unfiltered form gives - so both screens keep their current row
     * order (and their snapshots) exactly.
     *
     * @return array<int, UserSummary> a list, not keyed
     */
    public function pendingVerificationUsers(bool $newestFirst = false): array;

    /**
     * Stage 2 C3: the admin report's three `users` counters. They are aggregates over a table Identity owns,
     * so the statements live here even though the numbers are the report's.
     */
    public function countAll(): int;

    public function countPendingVerifications(): int;

    public function countCreatedBetween(Carbon $start, Carbon $end): int;

    /**
     * The city distribution the admin dashboard draws: raw `address` rows with their counts, most common
     * first, capped at `$limit`. The Arabic-to-English display names and the percentages stay in Console -
     * they are presentation, not data.
     *
     * @return array<int, array{address: string, count: int}>
     */
    public function addressDistribution(int $limit): array;

    /**
     * Stage 2 C3: the users dashboard's four cards. Each is one COUNT over `users`; `countAll()` above is the
     * fifth. The driver-document predicate is Identity's own `photos` table, so it is evaluated here.
     */
    public function countVerifiedDrivers(): int;

    public function countPendingDrivers(): int;

    public function countVerifiedPassengers(): int;

    public function countSuspended(): int;

    /**
     * Stage 2 C3: the admin dashboard's driver cards and recent-activity feed. Same rule as above - the
     * statements are over `users` (plus Identity's own `photos` for the "submitted documents" predicate), so
     * they run here even though the words are the dashboard's.
     */
    public function countDrivers(): int;

    /** @return array<int, UserSummary> newest verification updates first, capped at $limit */
    public function recentVerificationUsers(int $limit): array;

    public function countUsersWithDriverDocumentsCreatedBetween(Carbon $start, Carbon $end): int;

    public function countProcessedVerifications(Carbon $start, Carbon $end): int;

    /**
     * Stage 2 C3: the users dashboard's filtered, paginated list, as summaries.
     *
     * The filters are the screen's own - type (driver/passenger, including the driver-document predicate),
     * status (verified/pending/suspended), date window and free-text search - and they are applied here
     * because every one of them is a predicate on `users`. The caller renders rows from the summaries and
     * never queries the table.
     *
     * @return LengthAwarePaginator<int, UserSummary>
     */
    /**
     * Stage 4 A3: the admin driver list, filtered, searched and paginated - the same statement Console used to
     * build, moved to the module that owns `users`.
     *
     * The rating average and the driver's latest communication number ride along in the same DTO, so the screen
     * needs no second read for either. The communication number is a subquery on `rides`: Ride owns that table,
     * so its expression is built by Ride (`RideQueryExpressions`) and passed IN as a fragment - Identity embeds
     * it in its own statement and names no Ride class, so this adds no port reference to the graph. The fragment
     * is a subquery, never a result set, so it costs no query either. The driver predicate (a verified driver OR a pending/rejected
     * verification WITH submitted documents) and the ban filter are the owner's own rules.
     *
     * @return LengthAwarePaginator<int, UserSummary>
     */
    public function driverListPage(
        string $filter,
        int $perPage,
        int $page,
        ?string $search,
        ?Builder $latestCommunicationNumber = null,
    ): LengthAwarePaginator;

    public function adminUserPage(
        string $type,
        string $status,
        string $date,
        ?string $search,
        int $perPage,
        int $page,
    ): LengthAwarePaginator;
}
