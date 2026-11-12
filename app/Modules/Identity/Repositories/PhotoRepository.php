<?php

namespace App\Modules\Identity\Repositories;

use App\Models\Photo;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;

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
}
