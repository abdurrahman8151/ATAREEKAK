<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\DTOs\UserSummary;

/**
 * Stage 2 C3: the read surface for user data, owned by IDENTITY.
 *
 * Every other module that needs a name, an email or a verification flag for a user id calls this
 * instead of querying `users`. The interface is deliberately two methods wide, and the batched form
 * is the one to prefer: a list screen that calls `summaryFor()` in a loop is an N+1 that the query
 * budgets in the snapshot suite will catch, and the reason `summariesFor()` exists is so the correct
 * call is also the convenient one.
 */
interface UserDirectory
{
    public function summaryFor(int $userId): ?UserSummary;

    /**
     * @param  int[]  $userIds
     * @return array<int, UserSummary> keyed by user id; ids that do not exist are absent
     */
    public function summariesFor(array $userIds): array;
}
