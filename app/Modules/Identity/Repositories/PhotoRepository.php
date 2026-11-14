<?php

namespace App\Modules\Identity\Repositories;

use App\Models\Photo;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PhotoRepository implements PhotoRepositoryInterface
{
    public function storeDocument($userId, $type, $path)
    {
        return Photo::create([
            'user_id' => $userId,
            'type' => $type,
            'path' => $path,
        ]);
    }

    public function deleteDocumentsByType($userId, $type)
    {
        return Photo::where('user_id', $userId)
            ->where('type', $type)
            ->delete();
    }

    public function getUserDocumentsByType($userId, $types)
    {
        return Photo::where('user_id', $userId)
            ->whereIn('type', $types)
            ->get();
    }

    /**
     * Decision 1b: single-document lookup for the staff streaming route. Kept here (not in the
     * controller) so the HTTP layer never imports App\Models\Photo - see the interface note.
     */
    public function findById(int $photoId)
    {
        return Photo::find($photoId);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, array{id: int, type: string|null, path: string|null}>>
     */
    public function documentsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Photo::whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'type', 'path'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn ($photo) => [
                'id' => $photo->id,
                'type' => $photo->type,
                'path' => $photo->path,
            ])->values()->all())
            ->all();
    }

    /**
     * Stage 2 C2: the existence predicate Console used to express with `whereHas('photos', ...)`,
     * published as a subquery it can embed. See the interface note for why it is not a result set.
     */
    public function driverDocumentUserIdsSubquery(): Builder
    {
        return Photo::query()
            ->select('user_id')
            ->whereIn('type', self::DRIVER_DOCUMENT_TYPES);
    }

    /** The same subquery for documents created inside the window (the "incoming" count). */
    public function driverDocumentUserIdsCreatedBetweenSubquery(Carbon $start, Carbon $end): Builder
    {
        return $this->driverDocumentUserIdsSubquery()
            ->whereBetween('created_at', [$start, $end]);
    }

    /** Stage 2 C2: the count the `kyc:migrate-disk` entrypoint reports before it moves anything. */
    public function countAllDocuments(): int
    {
        return Photo::query()->count();
    }

    /**
     * Stage 2 C2: the streamed row set for the disk migration. Chunked by id, so a large document
     * table is never held in memory - and the chunk size matches the `each()` default this replaces.
     */
    public function eachDocument(callable $callback, int $chunkSize = 1000): void
    {
        Photo::query()
            ->orderBy('id')
            ->select('id', 'path')
            ->chunkById($chunkSize, function ($rows) use ($callback): void {
                foreach ($rows as $photo) {
                    $callback(['id' => $photo->id, 'path' => $photo->path]);
                }
            });
    }
}
