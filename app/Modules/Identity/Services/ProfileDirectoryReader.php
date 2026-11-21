<?php

namespace App\Modules\Identity\Services;

use App\Modules\Communication\Contracts\ProfilePhotoDirectory;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Models\Profile;

/**
 * Stage 2 C2: Identity's profile reads for other modules. One method, one statement (or the same small
 * fixed set of statements the caller ran), no model across the boundary - see the interface for why it
 * publishes scalars and arrays.
 *
 * It also implements Communication's `ProfilePhotoDirectory`: that port is declared by the CONSUMER so the
 * chat path never has to name Identity (the phase-1.5 cut of `Communication -> Identity`), and the owner of
 * `profiles` supplies the one query it asks for.
 */
final class ProfileDirectoryReader implements ProfileDirectory, ProfilePhotoDirectory
{
    public function __construct(
        private readonly PhotoRepositoryInterface $photos,
    ) {}

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

    /**
     * Communication's batched read: ONE `whereIn` for the whole page of senders, keyed by user id. The
     * values are the raw stored paths (null when the column is null), which is what its caller needs to
     * keep its own `exists()` check and URL formatting.
     */
    public function photoPathsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Profile::query()
            ->whereIn('user_id', $userIds)
            ->pluck('profile_photo', 'user_id')
            ->all();
    }

    /**
     * The same read, published under Identity's own `ProfileDirectory` for Console and Money. It delegates so
     * there is exactly one implementation of the query, and so both ports keep their own spelling.
     */
    public function photoPathsForUsers(array $userIds): array
    {
        return $this->photoPathsFor($userIds);
    }

    /**
     * Stage 4 C2: the whole profile row per user, as the model serializes it - ONE query for a page.
     *
     * `toArray()` (not `getAttributes()`) on purpose: the eager load this replaces serialized the model, so
     * casts and ISO timestamps must match, or a raw-model response would drift.
     */
    public function profilesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Profile::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id')
            ->map(fn (Profile $profile) => $profile->toArray())
            ->all();
    }

    /** Stage 4 C2: Communication's consumer-declared form of `profilesForUsers()` - same rows, same query. */
    public function profileRowsFor(array $userIds): array
    {
        return $this->profilesForUsers($userIds);
    }

    /**
     * The five vehicle-card columns for a page of users, in ONE query. Same table, same module, same
     * "scalars only" rule as the photo read.
     */
    public function vehicleProfilesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Profile::query()
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'profile_photo', 'type_of_car', 'color_of_car', 'number_of_seats', 'car_pic'])
            ->keyBy('user_id')
            ->map(fn (Profile $profile) => [
                'profile_photo' => $profile->profile_photo,
                'type_of_car' => $profile->type_of_car,
                'color_of_car' => $profile->color_of_car,
                'number_of_seats' => $profile->number_of_seats,
                'car_pic' => $profile->car_pic,
            ])
            ->all();
    }

    /**
     * The same two statements the Console feed ran: the `profiles` page (with the driver predicate and
     * the ordering/limit in ONE statement) and the `users` eager load for the names it prints.
     *
     * `user_id` is read from the RELATED user, not from the profile column, so a profile whose user row
     * is gone still maps to null and the caller keeps its `if (! $profile->user) continue;` skip.
     */
    public function recentVehicleUpdates(int $limit): array
    {
        $documentUserIds = $this->photos->driverDocumentUserIdsSubquery();

        return Profile::with('user:id,first_name,last_name')
            ->whereHas('user', fn ($q) => $q->where('is_verified_driver', true)
                ->orWhereIn('users.id', $documentUserIds)
            )
            ->whereNotNull('type_of_car')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Profile $profile) => [
                'user_id' => $profile->user?->id,
                'first_name' => $profile->user?->first_name,
                'last_name' => $profile->user?->last_name,
                'updated_at' => $profile->updated_at,
            ])
            ->all();
    }

    /**
     * The staff profile's four statements (profile, user, comments, commenters), unchanged, with the
     * comments flattened to the columns the response prints. `firstOrFail` is kept FOR ITS 404 - the
     * staff screen answers a user without a profile with ModelNotFoundException, and that must not
     * become a 200 with empty fields.
     */
    public function staffProfile(int $userId): array
    {
        $profile = Profile::with([
            'user',
            'comments.commenter:id,first_name,last_name',
        ])->where('user_id', $userId)->firstOrFail();

        return [
            'user' => $profile->user,
            'profile_photo' => $profile->profile_photo,
            'description' => $profile->description,
            'comments' => $profile->comments->map(fn ($c) => [
                'id' => $c->id,
                'comment' => $c->comment,
                'commenter_id' => $c->commenter?->id,
                'commenter_first_name' => $c->commenter?->first_name,
                'commenter_last_name' => $c->commenter?->last_name,
                'created_at' => $c->created_at,
            ])->values()->all(),
        ];
    }
}
