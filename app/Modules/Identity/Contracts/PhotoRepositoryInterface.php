<?php

namespace App\Modules\Identity\Contracts;

interface PhotoRepositoryInterface
{
    public function storeDocument($userId, $type, $path);

    public function deleteDocumentsByType($userId, $type);

    public function getUserDocumentsByType($userId, $types);

    /**
     * Find one photo by id, or null.
     *
     * Decision 1b: the staff document route needs a single photo by id. Reading `App\Models\Photo`
     * from the controller would add a controllers->models boundary edge (R6) - and that baseline is
     * explicitly never to be raised - so the lookup goes through the repository and the HTTP layer
     * stays off the model.
     */
    public function findById(int $photoId);

    /**
     * The document rows for a set of users, keyed by user id - ONE query for a whole page.
     *
     * Stage 2 C2: the two verification queues used to eager-load `User::photos()` and then pluck the type and
     * build a URL per row. `photos` is Identity's table and the relation belongs to `User`, so the read is
     * published here instead: the caller passes the ids it already has and gets the rows back as plain arrays.
     *
     * Each row carries `id`, `type` and `path` because the two callers need different ones - the admin queue
     * builds a public `asset()` URL from the path, the staff queue builds a staff-authenticated streaming route
     * from the id. A user with no documents is absent from the result rather than present with an empty array,
     * so `$documents[$id] ?? []` is the caller's idiom.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, array{id: int, type: string|null, path: string|null}>>
     */
    public function documentsFor(array $userIds): array;
}
