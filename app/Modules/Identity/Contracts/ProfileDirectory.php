<?php

namespace App\Modules\Identity\Contracts;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Stage 2 C2: the profile reads other modules are allowed to make, as data rather than as a model.
 *
 * `profiles` is Identity's table, and the Console reporting screens used to answer these questions with
 * `App\Modules\Identity\Models\Profile` themselves (four files on the Console allowlist). Each method here replaces one of
 * those reads 1:1 - the same statement, the same rows, the raw stored values - so the caller keeps its
 * own URL formatting, its own response shape and its own query budget. Callers get scalars or arrays,
 * never the model: a foreign model handed across a boundary is exactly what this phase removes, so the
 * port grows by METHOD as each caller is converted rather than publishing the whole row.
 *
 * The one exception is `User`: it is the shared principal, importable by every context, so a method may
 * carry the profile's own `User` when the caller needs the authenticated model (the staff profile builds
 * its score from it).
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

    /**
     * The STORED `profile_photo` path for each of the given users, keyed by user id - ONE query for a page.
     *
     * Added for the callers that used to eager-load `User::profile` only to read this one column: the swap
     * is one query in, one query out, so their budgets do not move. A user with no profile row is absent;
     * a user whose column is null is present with `null`.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, string|null>
     */
    public function photoPathsForUsers(array $userIds): array;

    /**
     * The vehicle-card columns of a user's profile, keyed by user id - ONE query.
     *
     * Added for the admin driver screens, which eager-loaded `User::profile` for exactly these five columns.
     * A user with no profile row is absent; a present row carries every key (values may be null), so callers
     * can read `['type_of_car'] ?? null` without an isset dance.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{profile_photo: string|null, type_of_car: string|null, color_of_car: string|null, number_of_seats: int|null, car_pic: string|null}>
     */
    public function vehicleProfilesForUsers(array $userIds): array;

    /**
     * The WHOLE profile row for each of the given users, keyed by user id - ONE query.
     *
     * Stage 4 C2: the replacement for every `*.profile` eager load that fed a RAW-MODEL response. The rows are
     * the model's own `toArray()`, which is exactly what the eager-loaded relation serialized (casts applied,
     * timestamps as ISO strings), so a caller can inject the value back under the `profile` key and keep its
     * response byte-identical. A user with no profile row is ABSENT - the caller must distinguish that from a row
     * with null columns, because the two serialize differently (`null` versus an object).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function profilesForUsers(array $userIds): array;

    /**
     * The recent-activity feed's "vehicle / profile update" rows, newest first.
     *
     * The 1:1 replacement for
     * `Profile::with('user:id,first_name,last_name')->whereHas('user', ...)->whereNotNull('type_of_car')`.
     * It stays TWO statements (the profiles page plus the users eager load), exactly as the caller ran
     * them, because the admin dashboard's query budget is frozen.
     *
     * @return array<int, array{user_id: int|null, first_name: string|null, last_name: string|null, updated_at: Carbon}>
     */
    public function recentVehicleUpdates(int $limit): array;

    /**
     * The profile row behind the staff profile screen, with the comments received and their commenters.
     *
     * The 1:1 replacement for `Profile::with(['user', 'comments.commenter:id,first_name,last_name'])
     * ->where('user_id', $userId)->firstOrFail()`. Comments are returned in the relation's own order and
     * carry the raw comment text and the commenter's name parts, so the caller can keep reshaping them.
     *
     * @return array{
     *     user: User,
     *     profile_photo: string|null,
     *     description: string|null,
     *     comments: array<int, array{
     *         id: int,
     *         comment: string|null,
     *         commenter_id: int|null,
     *         commenter_first_name: string|null,
     *         commenter_last_name: string|null,
     *         created_at: Carbon
     *     }>
     * }
     *
     * @throws ModelNotFoundException when the user has no profile row (the screen's 404).
     */
    public function staffProfile(int $userId): array;
}
