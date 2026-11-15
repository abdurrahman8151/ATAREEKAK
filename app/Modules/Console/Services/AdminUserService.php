<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileDirectory;
use Carbon\Carbon;

/**
 * AdminUserService
 *
 * Single endpoint returns everything the Users dashboard needs:
 *   - admin_photo
 *   - stats cards
 *   - paginated user table  (all filters applied together)
 *
 * ── Filter defaults (when nothing is passed) ────────────────────────────────
 *   type      = all
 *   status    = all
 *   date      = all
 *
 * ── Type definitions ────────────────────────────────────────────────────────
 *   driver    = is_verified_driver = 1
 *              OR (verification_status = 'pending' AND has license/mechanic_card)
 *   passenger = everyone else
 *
 * ── Status definitions ──────────────────────────────────────────────────────
 *   verified  = is_verified_driver = 1  OR  is_verified_passenger = 1
 *   pending   = verification_status = 'pending'
 *   suspended = banned: status = -1 and the ban is not expired
 *                (RV-42a - it was `status = 0`, which is LOGGED_OUT, so every
 *                 signed-out and every unbanned account was counted as suspended)
 */
final class AdminUserService
{
    public function __construct(
        private readonly PhotoRepositoryInterface $photos,
        private readonly ProfileDirectory $profiles,
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
        $paginator = $this->buildQuery(
            $typeFilter,
            $statusFilter,
            $dateFilter,
            $search
        )->paginate($perPage, ['*'], 'page', $page);

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
            ->map(fn (User $user) => $this->formatUser(
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
        return [
            'total_registered' => User::count(),
            'active_drivers' => User::where('is_verified_driver', true)->count(),
            'pending_drivers' => User::where('verification_status', 'pending')
                ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery())
                ->count(),
            'passengers' => User::where('is_verified_passenger', true)
                ->where('is_verified_driver', false)
                ->count(),
            'suspended_users' => User::bannedNow()->count(),
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
    // PRIVATE — QUERY BUILDER
    // =========================================================================

    private function buildQuery(
        string $typeFilter,
        string $statusFilter,
        string $dateFilter,
        ?string $search
    ) {
        $query = User::query();

        $this->applyTypeFilter($query, $typeFilter);
        $this->applyStatusFilter($query, $statusFilter);
        $this->applyDateFilter($query, $dateFilter);
        $this->applySearchFilter($query, $search);

        return $query->orderByDesc('created_at');
    }

    /**
     * Type filter: driver | passenger (anything else adds no constraint).
     */
    private function applyTypeFilter($query, string $typeFilter): void
    {
        // ── Type filter ───────────────────────────────────────────────────
        match ($typeFilter) {

            'driver' => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->where('verification_status', 'pending')
                            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery());
                    });
            }),

            'passenger' => $query
                ->where('is_verified_driver', false)
                ->whereNotIn('users.id', $this->photos->driverDocumentUserIdsSubquery()),

            default => null,   // 'all' — no constraint
        };

    }

    /**
     * Status filter: verified | pending | suspended (anything else adds no constraint).
     */
    private function applyStatusFilter($query, string $statusFilter): void
    {
        // ── Status filter ─────────────────────────────────────────────────
        match ($statusFilter) {

            'verified' => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere('is_verified_passenger', true);
            }),

            'pending' => $query->where('verification_status', 'pending'),

            'suspended' => $query->bannedNow(),

            default => null,   // 'all' — no constraint
        };
    }

    /**
     * Date filter. Only the four named windows constrain the query.
     */
    private function applyDateFilter($query, string $dateFilter): void
    {
        // ── Date / join filter ────────────────────────────────────────────
        $cutoff = match ($dateFilter) {
            'last_30_days' => Carbon::now()->subDays(30),
            'last_3_months' => Carbon::now()->subMonths(3),
            'last_6_months' => Carbon::now()->subMonths(6),
            'last_12_months' => Carbon::now()->subMonths(12),
            default => null,   // 'all' — no constraint
        };

        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }
    }

    /**
     * Free-text search across first name, last name and email.
     */
    private function applySearchFilter($query, ?string $search): void
    {
        // ── Search ────────────────────────────────────────────────────────
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
    }

    // =========================================================================
    // PRIVATE — FORMAT
    // =========================================================================

    private function formatUser(User $user, array $documents, ?string $storedPhoto = null): array
    {
        return [
            'id' => $user->id,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'profile_photo' => $storedPhoto
                ? asset('storage/'.$storedPhoto)
                : null,
            'type' => $this->resolveUserType($user, $documents),
            'status' => $this->resolveUserStatus($user),
            'joined_at' => $user->created_at->toIso8601String(),
            'joined_label' => $user->created_at->format('d M Y'),
        ];
    }

    /**
     * @param  array<int, array{id: int, type: string|null, path: string|null}>  $documents  this user's rows
     */
    private function resolveUserType(User $user, array $documents): string
    {
        if ($user->is_verified_driver) {
            return 'driver';
        }

        // Stage 2 C2: the type list is Identity's definition, read from the port's constant so the
        // query side and this side cannot drift apart.
        $hasDriverDocs = collect($documents)
            ->whereIn('type', PhotoRepositoryInterface::DRIVER_DOCUMENT_TYPES)
            ->isNotEmpty();

        return $hasDriverDocs ? 'driver' : 'passenger';
    }

    private function resolveUserStatus(User $user): string
    {
        // RV-42a: "suspended" means BANNED (status -1), the same thing
        // `LoginController:83` refuses login for. It was `status == 0`, which is
        // LOGGED_OUT - so every signed-out and every unbanned account read as suspended.
        if ($user->isBannedNow()) {
            return 'suspended';
        }
        if ($user->is_verified_driver) {
            return 'verified';
        }
        if ($user->is_verified_passenger) {
            return 'verified';
        }
        if ($user->verification_status === 'pending') {
            return 'pending';
        }
        if ($user->verification_status === 'rejected') {
            return 'rejected';
        }

        return 'unverified';
    }
}
