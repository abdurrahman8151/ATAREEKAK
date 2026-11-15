<?php

namespace App\Modules\Console\Contracts;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: the passenger-profile screens' complaint reads, as data.
 *
 * `complaints` is Console's table, and Identity's passenger panels used to query the `Complaint` model
 * themselves. This port replaces those queries 1:1 - same eager loads, same filters, same ordering, same
 * limits - and returns scalars, so the model does not cross the boundary. The caller keeps its own
 * presentation: the Arabic type labels, the `created_at` date format and the response keys stay in
 * Identity's panel builder.
 */
interface ComplaintDirectory
{
    /**
     * One paginated page of a user's complaints, newest first.
     *
     * @param  string  $status  'all' or a `ComplaintStatus` value
     * @param  string  $type  'all' or a `ComplaintType` value
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     type: string,
     *     status: string,
     *     created_at: Carbon,
     *     assigned_to: string|null
     * }>
     */
    public function pageForUser(
        int $userId,
        string $status,
        string $type,
        int $perPage,
        int $page,
    ): LengthAwarePaginator;

    /**
     * The same rows without pagination, capped at `$limit` - the full-profile panel's shape.
     *
     * @return array<int, array{id: int, type: string, status: string, created_at: Carbon, assigned_to: string|null}>
     */
    public function recentForUser(int $userId, string $status, string $type, int $limit): array;

    /**
     * Raw `status => COUNT(*)` rows for one user. The tab labels and the `all` total are the caller's.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $userId): array;
}
