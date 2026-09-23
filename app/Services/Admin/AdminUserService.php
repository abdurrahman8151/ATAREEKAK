<?php

namespace App\Services\Admin;

use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
 *   suspended = status IN (-1, 0)   — banned or logged-out
 *   banned    = status = -1         — the ban-only subset of `suspended`
 */
final class AdminUserService
{
    // =========================================================================
    // MAIN — single merged response
    // =========================================================================

    /**
     * Returns admin photo, stats, and the filtered paginated table
     * in one response.
     *
     * @param int|null $adminUserId
     * @param string   $typeFilter    all | driver | passenger
     * @param string   $statusFilter  all | verified | pending | suspended | banned
     * @param string   $dateFilter    all | last_30_days | last_3_months | last_6_months | last_12_months
     * @param int      $perPage
     * @param int      $page
     * @param string|null $search
     */
    public function getPageData(
        ?int    $adminUserId  = null,
        string  $typeFilter   = 'all',
        string  $statusFilter = 'all',
        string  $dateFilter   = 'all',
        int     $perPage      = 10,
        int     $page         = 1,
        ?string $search       = null
    ): array {
        $paginator = $this->buildQuery(
            $typeFilter,
            $statusFilter,
            $dateFilter,
            $search
        )->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginator->getCollection()
            ->map(fn(User $user) => $this->formatUser($user))
            ->values();

        return [
            'admin_photo' => $this->getAdminPhoto($adminUserId),
            'stats'       => $this->getStats(),
            'users'       => $rows,
            'counts'      => $this->getStatusCounts($typeFilter, $dateFilter, $search),
            'meta'        => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'filters'      => [
                    'type'   => $typeFilter,
                    'status' => $statusFilter,
                    'date'   => $dateFilter,
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
            'active_drivers'   => User::where('is_verified_driver', true)->count(),
            'pending_drivers'  => User::where('verification_status', 'pending')
                ->whereHas('photos', fn($p) => $p->whereIn('type', ['license', 'mechanic_card']))
                ->count(),
            'passengers'       => User::where('is_verified_passenger', true)
                ->where('is_verified_driver', false)
                ->count(),
            'suspended_users'  => User::whereIn('status', [-1, 0])->count(),
        ];
    }

    // =========================================================================
    // ADMIN PHOTO
    // =========================================================================

    public function getAdminPhoto(?int $adminUserId): ?string
    {
        if (!$adminUserId) return null;

        $profile = Profile::where('user_id', $adminUserId)->first();

        return ($profile?->profile_photo)
            ? asset('storage/' . $profile->profile_photo)
            : null;
    }

    // =========================================================================
    // PRIVATE — QUERY BUILDER
    // =========================================================================

    private function buildQuery(
        string  $typeFilter,
        string  $statusFilter,
        string  $dateFilter,
        ?string $search
    ) {
        $query = User::with([
            'profile:user_id,profile_photo',
            'photos:id,user_id,type',
        ]);

        $this->applyTypeDateSearch($query, $typeFilter, $dateFilter, $search);
        $this->applyStatusFilter($query, $statusFilter);

        return $query->orderByDesc('created_at');
    }

    /**
     * Applies the type/date/search filters only — the shared base that both
     * the paginated table and the per-status counts below are built on top of.
     */
    private function applyTypeDateSearch(
        $query,
        string  $typeFilter,
        string  $dateFilter,
        ?string $search
    ): void {
        // ── Type filter ───────────────────────────────────────────────────
        match ($typeFilter) {

            'driver' => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->where('verification_status', 'pending')
                            ->whereHas('photos', fn($p) => $p->whereIn('type', ['license', 'mechanic_card']));
                    });
            }),

            'passenger' => $query
                ->where('is_verified_driver', false)
                ->whereDoesntHave('photos', fn($p) => $p->whereIn('type', ['license', 'mechanic_card'])),

            default => null,   // 'all' — no constraint
        };

        // ── Date / join filter ────────────────────────────────────────────
        $cutoff = match ($dateFilter) {
            'last_30_days'   => Carbon::now()->subDays(30),
            'last_3_months'  => Carbon::now()->subMonths(3),
            'last_6_months'  => Carbon::now()->subMonths(6),
            'last_12_months' => Carbon::now()->subMonths(12),
            default          => null,   // 'all' — no constraint
        };

        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }

        // ── Search ────────────────────────────────────────────────────────
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name',  'like', "%{$search}%")
                    ->orWhere('email',      'like', "%{$search}%");
            });
        }
    }

    private function applyStatusFilter($query, string $statusFilter): void
    {
        match ($statusFilter) {

            'verified'  => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere('is_verified_passenger', true);
            }),

            'pending'   => $query->where('verification_status', 'pending'),

            // 'suspended' covers both banned (-1) and logged-out (0) accounts.
            'suspended' => $query->whereIn('status', [-1, 0]),

            // 'banned' is the narrower cut: only accounts an admin actually
            // banned, excluding the logged-out (0) rows `suspended` sweeps in.
            'banned'    => $query->where('status', -1),

            default     => null,   // 'all' — no constraint
        };
    }

    /**
     * Per-status counts for the filter-tab badges, respecting the *other*
     * active filters (type/date/search) — unlike getStats(), which is always
     * platform-wide. Separate counted queries over the same filtered base
     * rather than one grouped query, since 'verified' and 'suspended' are
     * OR/whereIn conditions that don't reduce to a single GROUP BY (and
     * 'banned' overlaps 'suspended', so the buckets aren't disjoint anyway).
     */
    public function getStatusCounts(
        string  $typeFilter,
        string  $dateFilter,
        ?string $search
    ): array {
        $base = fn () => tap(User::query(), fn ($q) =>
            $this->applyTypeDateSearch($q, $typeFilter, $dateFilter, $search)
        );

        $counts = ['all' => $base()->count()];

        foreach (['verified', 'pending', 'suspended', 'banned'] as $status) {
            $query = $base();
            $this->applyStatusFilter($query, $status);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    // =========================================================================
    // PRIVATE — FORMAT
    // =========================================================================

    private function formatUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'full_name'     => trim("{$user->first_name} {$user->last_name}"),
            'email'         => $user->email,
            'profile_photo' => $user->profile?->profile_photo
                ? asset('storage/' . $user->profile->profile_photo)
                : null,
            'type'          => $this->resolveUserType($user),
            'status'        => $this->resolveUserStatus($user),
            'is_banned'     => $user->status == -1,
            'joined_at'     => $user->created_at->toIso8601String(),
            'joined_label'  => $user->created_at->format('d M Y'),
        ];
    }

    private function resolveUserType(User $user): string
    {
        if ($user->is_verified_driver) {
            return 'driver';
        }

        $hasDriverDocs = $user->photos
            ->whereIn('type', ['license', 'mechanic_card'])
            ->isNotEmpty();

        return $hasDriverDocs ? 'driver' : 'passenger';
    }

    private function resolveUserStatus(User $user): string
    {
        if ($user->status == -1)                       return 'banned';
        if ($user->status == 0)                        return 'logged_out';
        if ($user->is_verified_driver)                 return 'verified';
        if ($user->is_verified_passenger)              return 'verified';
        if ($user->verification_status === 'pending')  return 'pending';
        if ($user->verification_status === 'rejected') return 'rejected';

        return 'unverified';
    }
}
