<?php

namespace App\Modules\Identity\Contracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

interface PhotoRepositoryInterface
{
    /**
     * The document types that make a user a driver: a licence or a mechanic card.
     *
     * Named once, here, because the definition is read at both ends of this port - the subqueries
     * below and the callers that classify an already-fetched row.
     */
    public const DRIVER_DOCUMENT_TYPES = ['license', 'mechanic_card'];

    public function storeDocument($userId, $type, $path);

    public function deleteDocumentsByType($userId, $type);

    public function getUserDocumentsByType($userId, $types);

    /**
     * Find one photo by id, or null.
     *
     * Decision 1b: the staff document route needs a single photo by id. Reading `Identity\Models\Photo`
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

    /**
     * The ids of the users that own at least one driver document, as an EMBEDDABLE subquery.
     *
     * Stage 2 C2: Console's user queries asked this question with `whereHas('photos', ...)`, and a
     * `whereHas` cannot be replaced by a result set - the ids are not known until the query runs. The
     * owner therefore publishes the SUBQUERY and the caller embeds it with `whereIn('users.id', ...)`
     * (or `orWhereIn`, or the negated `whereNotIn`). A subquery is part of the caller's own statement,
     * so the endpoint's query count does not move - which is why this is not a Reader returning rows.
     *
     * Returned without `distinct()` on purpose: `whereIn` treats a repeated id the same as a single
     * one, so the deduplication would only add work. `photos.user_id` is NOT NULL (a cascade FK), so
     * the `whereNotIn` direction cannot be poisoned by a NULL in the set.
     */
    public function driverDocumentUserIdsSubquery(): Builder;

    /**
     * The same subquery restricted to documents CREATED inside the window.
     *
     * This is the "incoming verifications" half of the admin verification-efficiency widget, which
     * used `whereHas('photos', ... ->whereBetween('created_at', ...))`. The window belongs to the
     * caller; which document types count belongs here.
     */
    public function driverDocumentUserIdsCreatedBetweenSubquery(Carbon $start, Carbon $end): Builder;

    /**
     * How many document rows there are - the count `kyc:migrate-disk` reports before it moves anything.
     */
    public function countAllDocuments(): int;

    /**
     * Every document row, one at a time, in id order - `['id' => int, 'path' => string|null]`.
     *
     * Stage 2 C2: the `kyc:migrate-disk` entrypoint used to walk the model itself
     * (`Photo::query()->orderBy('id')->each(...)`). It is a KERNEL command, so once the model moves into
     * this module it may not name it - but it is the DISK migration, not a table read: the row set has to
     * be streamed in chunks (a document table is large) and the file copy, the size verification and the
     * source delete all stay in the command, because those are storage operations, not this module's
     * business. Only the two columns the move needs are selected.
     *
     * The chunk size mirrors what `Builder::each()` used to default to, so the statement count of a run
     * is unchanged.
     */
    public function eachDocument(callable $callback, int $chunkSize = 1000): void;
}
