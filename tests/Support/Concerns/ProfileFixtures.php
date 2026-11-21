<?php

namespace Tests\Support\Concerns;

use App\Models\User;
use App\Modules\Identity\Models\Profile;

/**
 * Stage 4 C2: profile fixtures without the retired kernel relation.
 *
 * `User::profile()` is gone (`Profile` is Identity's model now), so a test that needs a driver's or a passenger's
 * profile row writes it through the owner's model. The `user_id` key is filled from the user, which is the one thing
 * the relation used to imply; every other attribute is the caller's.
 */
trait ProfileFixtures
{
    /** @param  array<string, mixed>  $attributes */
    protected function createProfileFor(User $user, array $attributes = []): Profile
    {
        return Profile::create(['user_id' => $user->id] + $attributes);
    }
}
