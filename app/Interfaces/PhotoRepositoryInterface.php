<?php

namespace App\Interfaces;

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
}
