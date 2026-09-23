<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminUserController
 *
 * ── Routes ──────────────────────────────────────────────────────────────────
 *
 *  GET  /api/admin/users   → admin photo + stats + filtered paginated table
 *
 * ── Query params (all optional, all default to "all") ───────────────────────
 *
 *  type      = all | driver | passenger
 *  status    = all | verified | pending | suspended | banned
 *  date      = all | last_30_days | last_3_months | last_6_months | last_12_months
 *  per_page  = 1–50   (default 10)
 *  page      = int    (default 1)
 *  search    = string (matches first name, last name, or email)
 */
final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
    ) {}

    // =========================================================================
    // SINGLE MERGED ENDPOINT
    // =========================================================================

    /**
     * GET /api/admin/users
     *
     * Returns everything the Users/Passengers dashboard page needs:
     *   - admin_photo
     *   - stats  (total_registered, active_drivers, pending_drivers,
     *             passengers, suspended_users)
     *   - users  (paginated rows, all filters applied)
     *   - meta   (pagination + active filters)
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type'     => 'sometimes|in:all,driver,passenger',
            'status'   => 'sometimes|in:all,verified,pending,suspended,banned',
            'date'     => 'sometimes|in:all,last_30_days,last_3_months,last_6_months,last_12_months',
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

        try {
            $data = $this->userService->getPageData(
                adminUserId:  $request->attributes->get('staffEmployee')?->id,
                typeFilter:   $request->get('type',     'all'),
                statusFilter: $request->get('status',   'all'),
                dateFilter:   $request->get('date',     'all'),
                perPage:      (int) $request->get('per_page', 10),
                page:         (int) $request->get('page',     1),
                search:       $request->get('search'),
            );

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin user index failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load users',
            ], 500);
        }
    }
}
