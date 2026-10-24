<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\User;
use App\Services\Admin\AdminTripService;
use App\Services\Admin\AdminUserService;
use App\Services\Staff\StaffBookingQueryService;
use App\Services\Staff\StaffCancellationService;
use App\Services\Staff\StaffUserProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * StaffOperationsController
 *
 * UC-ADM-05: Support agents browse operational data in read-only mode.
 *
 * All routes protected by `staff` middleware (any role).
 *
 * Routes:
 *   GET /api/staff/users                    → users()
 *   GET /api/staff/users/{userId}           → userProfile()
 *   GET /api/staff/trips                    → trips()
 *   GET /api/staff/bookings                 → bookings()
 */
final class StaffOperationsController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
        private readonly AdminTripService $tripService,
        private readonly StaffBookingQueryService $bookingQueries,
        private readonly StaffUserProfileService $userProfiles,
        private readonly StaffCancellationService $cancellations,
    ) {}

    // =========================================================================
    // USERS LIST
    // =========================================================================

    /**
     * GET /api/staff/users
     *
     * Query params:
     *   type      = all | driver | passenger
     *   status    = all | verified | pending | suspended
     *   date      = all | last_30_days | last_3_months | last_6_months | last_12_months
     *   per_page  = 1–50  (default 10)
     *   page      = int
     *   search    = string
     */
    public function users(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|in:all,driver,passenger',
            'status' => 'sometimes|in:all,verified,pending,suspended',
            'date' => 'sometimes|in:all,last_30_days,last_3_months,last_6_months,last_12_months',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->userService->getPageData(
                adminUserId: null,           // staff has no admin photo
                typeFilter: $request->get('type', 'all'),
                statusFilter: $request->get('status', 'all'),
                dateFilter: $request->get('date', 'all'),
                perPage: (int) $request->get('per_page', 10),
                page: (int) $request->get('page', 1),
                search: $request->get('search'),
            );

            // Staff doesn't need the admin_photo block
            unset($data['admin_photo']);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return $this->serverError();
        }
    }

    // =========================================================================
    // USER PROFILE DETAIL
    // =========================================================================

    /**
     * GET /api/staff/users/{userId}
     *
     * Returns the public profile of any user: personal info, score,
     * ride history counts, comments received, and rating stats.
     * Support agents use this when investigating complaints.
     */
    public function userProfile(int $userId): JsonResponse
    {
        try {
            $data = Cache::remember("staff.user-profile.{$userId}", now()->addMinutes(2), function () use ($userId) {
                return $this->userProfiles->build($userId);
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        } catch (\Exception $e) {
            return $this->serverError();
        }
    }

    // =========================================================================
    // TRIPS LIST
    // =========================================================================

    /**
     * GET /api/staff/trips
     *
     * Query params:
     *   filter   = all | active | scheduled | completed | cancelled | awaiting
     *   per_page = 1-50  (default 15)
     *   page     = int
     */
    public function trips(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter' => 'sometimes|in:all,active,scheduled,completed,cancelled,awaiting',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $filter = $request->get('filter', 'all');
            $perPage = (int) $request->get('per_page', 15);
            $page = (int) $request->get('page', 1);

            $paginator = $this->tripService->getFilteredTrips($filter, $perPage, $page);

            $data = $paginator->getCollection()
                ->map(fn ($ride) => $this->tripService->formatTrip($ride))
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'filter' => $filter,
                ],
                'counts' => $this->tripService->getStatusCounts(),
            ]);
        } catch (\Exception $e) {
            return $this->serverError();
        }
    }

    // =========================================================================
    // BOOKINGS LIST
    // =========================================================================

    /**
     * GET /api/staff/bookings
     *
     * Query params:
     *   status   = all | pending | confirmed | cancelled | completed | no_show
     *   user_id  = int  (filter by passenger)
     *   ride_id  = int  (filter by ride)
     *   per_page = 1-50  (default 15)
     *   page     = int
     */
    public function bookings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:all,pending,confirmed,cancelled,completed,no_show',
            'user_id' => 'sometimes|integer|exists:users,id',
            'ride_id' => 'sometimes|integer|exists:rides,id',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Only pass the filters that were actually supplied, as the original query did (filled()).
            $filters = [
                'status' => $request->get('status', 'all'),
                'per_page' => (int) $request->get('per_page', 15),
                'page' => (int) $request->get('page', 1),
            ];
            if ($request->filled('user_id')) {
                $filters['user_id'] = $request->integer('user_id');
            }
            if ($request->filled('ride_id')) {
                $filters['ride_id'] = $request->integer('ride_id');
            }

            $paginator = $this->bookingQueries->paginate($filters);

            $data = $paginator->getCollection()
                ->map(fn ($booking) => $this->bookingQueries->toRow($booking))
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'filter' => $filters['status'],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError();
        }
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }

    public function cancelTrip(int $rideId, Request $request): JsonResponse
    {
        return $this->cancellations->cancelTrip($rideId, $request->all(), $request->attributes->get('staffEmployee'));
    }

    // POST /api/staff/bookings/{bookingId}/cancel
    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        return $this->cancellations->cancelBooking($bookingId, $request->all(), $request->attributes->get('staffEmployee'));
    }
}
