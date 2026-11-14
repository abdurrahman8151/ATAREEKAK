<?php

namespace App\Modules\Identity\Contracts;

/**
 * Stage 2 C2: the profile reads other modules are allowed to make, as data rather than as a model.
 *
 * `profiles` is Identity's table, and the Console reporting screens used to answer these questions with
 * `App\Models\Profile` themselves (four files on the Console allowlist). Each method here replaces one of
 * those reads 1:1 - the same single statement, the same row, the raw stored value - so the caller keeps its
 * own URL formatting, its own response shape and its own query budget. Callers get scalars, never the model:
 * a foreign model handed across a boundary is exactly what this phase removes, so the port grows by METHOD
 * as each caller is converted rather than publishing the whole row.
 */
interface ProfileDirectory
{
    /**
     * The STORED `profile_photo` path for a user, or null when the user has no profile row.
     *
     * Raw on purpose: the two admin screens that display it build their own `asset('storage/...')` URL, and
     * that formatting is their public response, not this module's.
     */
    public function photoPathForUser(int $userId): ?string;
}
