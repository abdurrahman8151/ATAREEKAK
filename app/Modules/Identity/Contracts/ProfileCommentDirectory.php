<?php

namespace App\Modules\Identity\Contracts;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Stage 2 C2: the staff review-moderation screen's read and delete of `profile_comments`, as data.
 *
 * `profile_comments` is Identity's table. Console's moderation screen used to query
 * `App\Models\ProfileComment` directly and hand the model back through `format()`, which is the coupling
 * this port removes. The page keeps the SAME eager loads and the SAME filters, so the query count and the
 * response shape do not move; only the type crossing the boundary changes, from a model to arrays.
 */
interface ProfileCommentDirectory
{
    /**
     * The filtered, paginated comment page. Items carry only what the response prints.
     *
     * @param  int|null  $userId  filter by commenter OR recipient user ID
     * @param  string|null  $search  full-text search inside the comment body
     * @param  string|null  $date  'last_7_days' | 'last_30_days'
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     comment: string|null,
     *     created_at: Carbon,
     *     commenter: array{id: int|null, first_name: string|null, last_name: string|null},
     *     recipient: array{id: int|null, first_name: string|null, last_name: string|null}
     * }>
     */
    public function page(
        ?int $userId = null,
        ?string $search = null,
        ?string $date = null,
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator;

    /**
     * Delete one comment (policy violation).
     *
     * @throws ModelNotFoundException when the id does not exist - the endpoint's 404.
     */
    public function deleteOrFail(int $commentId): void;
}
