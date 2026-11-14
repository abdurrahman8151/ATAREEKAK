<?php

namespace App\Modules\Identity\Services;

use App\Models\Profile;
use App\Modules\Identity\Contracts\ProfileDirectory;

/**
 * Stage 2 C2: Identity's profile reads for other modules. One method, one statement, no model across the
 * boundary - see the interface for why it publishes scalars.
 */
final class ProfileDirectoryReader implements ProfileDirectory
{
    /**
     * `value('profile_photo')` is the same single SELECT ... LIMIT 1 the callers ran themselves
     * (`Profile::where(...)->first()` then `?->profile_photo`), with one column instead of the whole row,
     * and it goes through the model so any cast on the attribute still applies.
     */
    public function photoPathForUser(int $userId): ?string
    {
        return Profile::query()
            ->where('user_id', $userId)
            ->value('profile_photo');
    }
}
