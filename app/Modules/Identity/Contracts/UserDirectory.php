<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\DTOs\UserSummary;
use Carbon\Carbon;

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
}
