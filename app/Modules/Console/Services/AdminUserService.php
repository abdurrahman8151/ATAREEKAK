<?php

namespace App\Modules\Console\Services;

use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserDirectory;

/**
 * AdminUserService
 *
 * Single endpoint returns everything the Users dashboard needs:
 *   - admin_photo
 *   - stats cards
 *   - paginated user table  (all filters applied together)
 *
 * ── Filter defaults (when nothing is passed) ───────────────────────────────
 *   type      = all
 *   status    = all
 *   date      = all
 *
 * ── Type definitions ───────────────────────────────────────────────────────
 *   driver    = is_verified_driver = 1
 *              OR (verification_status = 'pending' AND has license/mechanic_card)
 *   passenger = everyone else
 *
 * ── Status definitions ─────────────────────────────────────────────────────
 *   verified  = is_verified_driver = 1  OR  is_verified_passenger = 1
 *   pending   = verification_status = 'pending'
 *   suspended = banned: status = -1 and the ban is not expired
 *                (RV-42a - it was `status = 0`, which is LOGGED_OUT, so every
 *                 signed-out and every unbanned account was counted as suspended)
 *
 * Stage 2 C3: every `users` read on this screen moved behind `UserDirectory` - the five card COUNTS, the
 * filtered/paginated list (predicates included) and the per-row fields. This service no longer names the
 * `User` model; it renders rows from the directory's summaries and keeps the presentation: the type/status
 * word, the storage URL, the row keys.
 */
final class AdminUserService
{
    public function __construct(
        private readonly PhotoRepositoryInterface $photos,
        private readonly ProfileDirectory $profiles,
        private readonly UserDirectory $users,
    ) {}

    // =========================================================================
    // MAIN — single merged response
    // =========================================================================

    /**
     * Returns admin photo, stats, and the filtered paginated table
     * in one response.
     *
     * @param  string  $typeFilter  all | driver | passenger
     * @param  string  $statusFilter  all | verified | pending | suspended
     * @param  string  $dateFilter  all | last_30_days | last_3_months | last_6_months | last_12_months
     */
    public function getPageData(
        ?int $adminUserId = null,
        string $typeFilter = 'all',
        string $statusFilter = 'all',
        string $dateFilter = 'all',
        int $perPage = 10,
        int $page = 1,
        ?string $search = null
    ): array {
        $paginator = $this->users->adminUserPage(
            $typeFilter,
            $statusFilter,
            $dateFilter,
            $search,
            $perPage,
            $page,
        );

        // Stage 2 C2: `photos` is Identity's table. The page's document rows arrive here in ONE
        // batched read, replacing the `photos:id,user_id,type` eager load `buildQuery` used to
        // carry - same one query, and the query still runs after the page is fetched.
        $documents = $this->photos->documentsFor(
            $paginator->getCollection()->pluck('id')->all()
        );

        // Stage 2 C2: `profiles` is Identity's too. The page's photo column arrives in ONE batched read,
        // replacing the `profile:user_id,profile_photo` eager load `buildQuery` used to carry - same one
        // query, still after the page is fetched.
        $photoPaths = $this->profiles->photoPathsForUsers(
            $paginator->getCollection()->pluck('id')->all()
        );

        $rows = $paginator->getCollection()
            ->map(fn ($user) => $this->formatUser(
                $user,
                $documents[$user->id] ?? [],
                $photoPaths[$user->id] ?? null
            ))
            ->values();

        return [
            'admin_photo' => $this->getAdminPhoto($adminUserId),
            'stats' => $this->getStats(),
            'users' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filters' => [
                    'type' => $typeFilter,
                    'status' => $statusFilter,
                    'date' => $dateFilter,
                ],
            ],
        ];
    }

    // =========================================================================
    // STATS CARDS
    // =========================================================================

    public function getStats(): array
    {
        // Stage 2 C3: every counter is a `users` COUNT, so Identity runs the statements.
        return [
            'total_registered' => $this->users->countAll(),
            'active_drivers' => $this->users->countVerifiedDrivers(),
            'pending_drivers' => $this->users->countPendingDrivers(),
            'passengers' => $this->users->countVerifiedPassengers(),
            'suspended_users' => $this->users->countSuspended(),
        ];
    }

    // =========================================================================
    // ADMIN PHOTO
    // =========================================================================

    public function getAdminPhoto(?int $adminUserId): ?string
    {
        if (! $adminUserId) {
            return null;
        }

        // Stage 2 C2: `profiles` is Identity's table. ONE statement either way, and the URL format is
        // still this screen's - the port returns the raw stored path.
        $photo = $this->profiles->photoPathForUser($adminUserId);

        return $photo ? asset('storage/'.$photo) : null;
    }

    // =========================================================================
    // PRIVATE — FORMAT
    // =========================================================================

    /**
     * `$user` is Identity's `UserSummary`, read by property - this module may not name the DTO class.
     */
    private function formatUser($user, array $documents, ?string $storedPhoto = null): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->fullName(),
            'email' => $user->email,
            'profile_photo' => $storedPhoto
                ? asset('storage/'.$storedPhoto)
                : null,
            'type' => $this->resolveUserType($user, $documents),
            'status' => $this->resolveUserStatus($user),
            'joined_at' => $user->createdAt->toIso8601String(),
            'joined_label' => $user->createdAt->format('d M Y'),
        ];
    }

    /**
     * @param  array<int, array{id: int, type: string|null, path: string|null}>  $documents  this user's rows
     */
    private function resolveUserType($user, array $documents): string
    {
        if ($user->isVerifiedDriver) {
            return 'driver';
        }

        // Stage 2 C2: the type list is Identity's definition, read from the port's constant so the
        // query side and this side cannot drift apart.
        $hasDriverDocs = collect($documents)
            ->whereIn('type', PhotoRepositoryInterface::DRIVER_DOCUMENT_TYPES)
            ->isNotEmpty();

        return $hasDriverDocs ? 'driver' : 'passenger';
    }

    private function resolveUserStatus($user): string
    {
        // RV-42a: "suspended" means BANNED (status -1), the same thing
        // `LoginController:83` refuses login for. It was `status == 0`, which is
        // LOGGED_OUT - so every signed-out and every unbanned account read as suspended.
        // Stage 2 C3: the ban RULE stays with Identity; the summary carries the answer.
        if ($user->isBannedNow) {
            return 'suspended';
        }
        if ($user->isVerifiedDriver) {
            return 'verified';
        }
        if ($user->isVerifiedPassenger) {
            return 'verified';
        }
        if ($user->verificationStatus === 'pending') {
            return 'pending';
        }
        if ($user->verificationStatus === 'rejected') {
            return 'rejected';
        }

        return 'unverified';
    }
}
