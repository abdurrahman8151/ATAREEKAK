<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;      // ← added
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminDriverController
 *
 * All endpoints are protected by the  auth.admin  middleware.
 * Primary-only routes are further protected by  auth.admin:primary.
 *
 * ── Routes ──────────────────────────────────────────────────────────────────
 *
 *  GET  /api/admin/drivers/dashboard          → full BFF payload
 *
 *  GET  /api/admin/drivers/stats              → stats cards only
 *  GET  /api/admin/drivers                    → driver table (paginated + filtered)
 *  GET  /api/admin/drivers/activity           → recent activity feed
 *
 *  GET  /api/admin/drivers/{driverId}/profile → single driver detail
 *
 * ── Caching summary ─────────────────────────────────────────────────────────
 *
 *  CACHED     dashboard              admin.drivers.dashboard                5 min
 *  CACHED     stats                  admin.drivers.stats                    5 min
 *  NOT CACHED index                  (filter+page+search = unbounded keys)
 *  CACHED     activity               admin.drivers.activity.{limit}         1 min
 *  CACHED     driverProfile          admin.driver.profile.{id}             10 min
 *  CACHED     driverDashboard        admin.driver.dashboard.{id}            5 min
 *  CACHED     verificationEfficiency admin.drivers.verification.efficiency.{period} 5 min
 */
final class AdminDriverController extends Controller
{
    public function __construct(
        private readonly AdminDriverService $driverService,
    ) {}

    // =========================================================================
    // BFF  –  full dashboard
    // =========================================================================

    /**
     * GET /api/admin/drivers/dashboard
     *
     * CACHED — 5 minutes. One shared Redis key for all 3 cluster nodes.
     *
     * Returns every widget the driver management page needs in one call:
     *   - admin_photo
     *   - stats  (total, active, pending, suspended, avg_rating)
     *   - recent_activity
     *
     * The driver table is intentionally excluded from the BFF because it is
     * paginated; the frontend fetches it separately via GET /api/admin/drivers.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $data = Cache::remember('admin.drivers.dashboard', 300, function () use ($request) {
                return $this->driverService->getDashboardData(
                    $request->attributes->get('staffEmployee')?->id
                );
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Driver dashboard failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver dashboard',
            ], 500);
        }
    }

    // =========================================================================
    // STATS CARDS
    // =========================================================================

    /**
     * GET /api/admin/drivers/stats
     *
     * CACHED — 5 minutes.
     * Returns the four stat cards independently so the frontend can refresh
     * just the numbers without reloading the whole page.
     */
    public function stats(): JsonResponse
    {
        try {
            $data = Cache::remember('admin.drivers.stats', 300, function () {
                return $this->driverService->getStats();
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Driver stats failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver stats',
            ], 500);
        }
    }

    // =========================================================================
    // DRIVER TABLE — NOT cached
    // =========================================================================

    /**
     * GET /api/admin/drivers
     *
     * NOT cached.
     * Reason: the combination of filter + page + per_page + search creates
     * effectively unbounded cache keys. Free-text search alone generates a
     * unique key per query string. Caching paginated search results fills Redis
     * with one-time entries and gives near-zero hit rate in practice.
     *
     * Query params:
     *   filter   = all | verified | pending | suspended   (default: all)
     *   per_page = 1-50                                   (default: 10)
     *   page     = int                                    (default: 1)
     *   search   = string  (optional – matches name or email)
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter'   => 'sometimes|in:all,verified,pending,suspended',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
            'search'   => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filter  = $request->get('filter', 'all');
        $perPage = (int) $request->get('per_page', 10);
        $page    = (int) $request->get('page', 1);
        $search  = $request->get('search');

        try {
            $paginator = $this->driverService->getDrivers($filter, $perPage, $page, $search);

            $data = $paginator->getCollection()
                ->map(fn($driver) => $this->driverService->formatDriver($driver))
                ->values();

            $stats = $this->driverService->getStats();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'meta'   => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'filter'       => $filter,
                ],
                'counts' => [
                    'all'       => $stats['total_drivers'],
                    'verified'  => $stats['active_drivers'],
                    'pending'   => $stats['pending_verifications'],
                    'suspended' => $stats['suspended_drivers'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Driver index failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load drivers',
            ], 500);
        }
    }

    // =========================================================================
    // RECENT ACTIVITY
    // =========================================================================

    /**
     * GET /api/admin/drivers/activity
     *
     * CACHED — 1 minute per $limit value.
     * "Recent" implies freshness. 60 s is the shortest cache window worth
     * having — it still removes the per-request DB hit under concurrent load
     * while keeping the feed nearly live.
     *
     * Query params:
     *   limit = 1-50   (default: 10)
     */
    public function activity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $limit = (int) $request->get('limit', 10);

            $data = Cache::remember("admin.drivers.activity.{$limit}", 60, function () use ($limit) {
                return $this->driverService->getRecentActivity($limit);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Driver activity feed failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load activity feed',
            ], 500);
        }
    }

    // =========================================================================
    // DRIVER PROFILE  (detail / modal view)
    // =========================================================================

    /**
     * GET /api/admin/drivers/{driverId}/profile
     *
     * CACHED — 10 minutes per driver.
     * Profile data (name, vehicle, documents, licence scan, rating) changes
     * rarely. Per-driver key means a profile update only busts one entry.
     *
     * Returns the full profile of a single driver including:
     *   - personal info, vehicle details, documents, rating, recent rides
     */
    public function driverProfile(int $driverId): JsonResponse
    {
        try {
            $profile = Cache::remember("admin.driver.profile.{$driverId}", 600, function () use ($driverId) {
                return $this->driverService->getDriverProfile($driverId);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $profile,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Driver not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Driver profile failed', [
                'driver_id' => $driverId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver profile',
            ], 500);
        }
    }

    /**
     * CACHED — 5 minutes per driver.
     * Per-driver key so a status change only busts that specific entry.
     */
    public function driverDashboard(int $driverId): JsonResponse
    {
        try {
            $data = Cache::remember("admin.driver.dashboard.{$driverId}", 300, function () use ($driverId) {
                return $this->driverService->getDriverDashboard($driverId);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Driver not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Driver dashboard failed', [
                'driver_id' => $driverId,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver dashboard',
            ], 500);
        }
    }

    /**
     * CACHED — 5 minutes per period.
     * Three possible Redis keys: efficiency.day / efficiency.week / efficiency.month
     * All three are busted by AdminDashboardController::bustVerificationCaches()
     * when a verification is approved or rejected.
     */
    public function verificationEfficiency(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'sometimes|in:day,week,month',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $period = $request->get('period', 'week');

            $data = Cache::remember(
                "admin.drivers.verification.efficiency.{$period}",
                300,
                function () use ($period) {
                    return $this->driverService->getVerificationEfficiency($period);
                }
            );

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Verification efficiency failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load verification efficiency',
            ], 500);
        }
    }

    // =========================================================================
    // Private helpers — not endpoints, no caching
    // =========================================================================

    private function resolvePeriodBounds(string $period): array
    {
        $now = \Carbon\Carbon::now();

        switch ($period) {
            case 'day':
                $currentStart  = $now->copy()->startOfDay();
                $currentEnd    = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subDay()->startOfDay();
                $previousEnd   = $now->copy()->subDay()->endOfDay();
                $label         = 'day';
                $prevLabel     = 'day';
                break;

            case 'month':
                $currentStart  = $now->copy()->startOfMonth();
                $currentEnd    = $now->copy()->endOfMonth();
                $previousStart = $now->copy()->subMonth()->startOfMonth();
                $previousEnd   = $now->copy()->subMonth()->endOfMonth();
                $label         = 'month';
                $prevLabel     = 'month';
                break;

            case 'week':
            default:
                $currentStart  = $now->copy()->startOfWeek();
                $currentEnd    = $now->copy()->endOfWeek();
                $previousStart = $now->copy()->subWeek()->startOfWeek();
                $previousEnd   = $now->copy()->subWeek()->endOfWeek();
                $label         = 'week';
                $prevLabel     = 'week';
                break;
        }

        return [$currentStart, $currentEnd, $previousStart, $previousEnd, $label, $prevLabel];
    }

    private function countProcessedVerifications(
        \Carbon\Carbon $start,
        \Carbon\Carbon $end
    ): int {
        return \App\Models\User::whereIn('verification_status', ['approved', 'rejected'])
            ->whereHas('photos', fn($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->whereBetween('updated_at', [$start, $end])
            ->count();
    }

    private function countIncomingVerifications(
        \Carbon\Carbon $start,
        \Carbon\Carbon $end
    ): int {
        return \App\Models\User::whereHas('photos', function ($q) use ($start, $end) {
            $q->whereIn('type', ['license', 'mechanic_card'])
                ->whereBetween('created_at', [$start, $end]);
        })
            ->count();
    }
}
