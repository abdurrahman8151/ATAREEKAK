<?php

namespace App\Repositories;

use App\Interfaces\PhotoRepositoryInterface;
use App\Models\Photo;

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
}
