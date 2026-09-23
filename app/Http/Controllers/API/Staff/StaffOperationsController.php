<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Profile;
use App\Models\ProfileComment;
use App\Models\UserRating;
use App\Services\Admin\AdminTripService;
use App\Services\Admin\AdminUserService;
use App\Services\Score\ScoreService;
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
        private readonly ScoreService     $scoreService,
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
            'type'     => 'sometimes|in:all,driver,passenger',
            'status'   => 'sometimes|in:all,verified,pending,suspended',
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
                adminUserId:  null,           // staff has no admin photo
                typeFilter:   $request->get('type',     'all'),
                statusFilter: $request->get('status',   'all'),
                dateFilter:   $request->get('date',     'all'),
                perPage:      (int) $request->get('per_page', 10),
                page:         (int) $request->get('page',     1),
                search:       $request->get('search'),
            );

            // Staff doesn't need the admin_photo block
            unset($data['admin_photo']);

            return response()->json([
                'status' => 'success',
                'data'   => $data,
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
                $profile = Profile::with([
                    'user',
                    'comments.commenter:id,first_name,last_name',
                ])->where('user_id', $userId)->firstOrFail();

                $user = $profile->user;

                // ── Score ──────────────────────────────────────────────────────────
                $userScore = $this->scoreService->getScore($user);

                // ── Rating stats ───────────────────────────────────────────────────
                $ratingStats = UserRating::where('rated_user_id', $userId)
                    ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
                    ->first();

                // ── Ride history: as driver — 1 query instead of 3 ────────────────
                $driverStats = \App\Models\Ride::where('driver_id', $userId)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                $asDriver = [
                    'total'     => $driverStats->sum(),
                    'completed' => $driverStats->get('finished', 0),
                    'cancelled' => $driverStats->get('cancelled', 0),
                ];

                // ── Ride history: as passenger — 1 query instead of 3 ─────────────
                $passengerStats = Booking::where('user_id', $userId)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                $asPassenger = [
                    'total'     => $passengerStats->sum(),
                    'completed' => $passengerStats->get('completed', 0),
                    'cancelled' => $passengerStats->get('cancelled', 0),
                ];

                // ── Comments received ──────────────────────────────────────────────
                $comments = $profile->comments->map(fn ($c) => [
                    'id'         => $c->id,
                    'comment'    => $c->comment,
                    'commenter'  => [
                        'id'   => $c->commenter?->id,
                        'name' => trim(($c->commenter?->first_name ?? '') . ' ' . ($c->commenter?->last_name ?? '')),
                    ],
                    'created_at' => $c->created_at->toIso8601String(),
                ])->values()->all();

                return [
                    'id'                    => $user->id,
                    'full_name'             => trim("{$user->first_name} {$user->last_name}"),
                    'email'                 => $user->email,
                    'gender'                => $user->gender,
                    'address'               => $user->address,
                    'verification_status'   => $user->verification_status,
                    'is_verified_driver'    => (bool) $user->is_verified_driver,
                    'is_verified_passenger' => (bool) $user->is_verified_passenger,
                    'account_status'        => $user->status == 1 ? 'active' : 'suspended',
                    'joined_at'             => $user->created_at->toIso8601String(),
                    'profile_photo'         => $profile->profile_photo
                        ? asset("storage/{$profile->profile_photo}")
                        : null,
                    'description'           => $profile->description,
                    'score'                 => [
                        'score'               => $userScore->score,
                        'tier'                => $userScore->tier,
                        'cancel_rate'         => round($userScore->cancel_rate, 2),
                        'total_rides'         => $userScore->total_rides,
                        'total_cancellations' => $userScore->total_cancellations,
                    ],
                    'rating' => [
                        'average'       => $ratingStats->average ?? 0,
                        'total_ratings' => (int) ($ratingStats->total ?? 0),
                    ],
                    'ride_history' => [
                        'as_driver'    => $asDriver,
                        'as_passenger' => $asPassenger,
                    ],
                    'comments_received' => $comments,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
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
            'filter'   => 'sometimes|in:all,active,scheduled,completed,cancelled,awaiting',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $filter  = $request->get('filter', 'all');
            $perPage = (int) $request->get('per_page', 15);
            $page    = (int) $request->get('page', 1);

            $paginator = $this->tripService->getFilteredTrips($filter, $perPage, $page);

            $data = $paginator->getCollection()
                ->map(fn ($ride) => $this->tripService->formatTrip($ride))
                ->values();

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
            'status'   => 'sometimes|in:all,pending,confirmed,cancelled,completed,no_show',
            'user_id'  => 'sometimes|integer|exists:users,id',
            'ride_id'  => 'sometimes|integer|exists:rides,id',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $query = Booking::with([
                'user:id,first_name,last_name,email',
                'ride:id,pickup_address,destination_address,departure_time,price_per_seat,status,driver_id',
                'ride.driver:id,first_name,last_name',
            ]);

            $status = $request->get('status', 'all');
            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->integer('user_id'));
            }

            if ($request->filled('ride_id')) {
                $query->where('ride_id', $request->integer('ride_id'));
            }

            // Counts respect the same user_id/ride_id scoping as the list, but
            // never the status filter — that's the whole point of the badges.
            $countsQuery = Booking::query();
            if ($request->filled('user_id')) {
                $countsQuery->where('user_id', $request->integer('user_id'));
            }
            if ($request->filled('ride_id')) {
                $countsQuery->where('ride_id', $request->integer('ride_id'));
            }
            $countsByStatus = (clone $countsQuery)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $counts = collect(['pending', 'confirmed', 'cancelled', 'completed', 'no_show'])
                ->mapWithKeys(fn (string $s) => [$s => $countsByStatus[$s] ?? 0])
                ->put('all', $countsQuery->count());

            $paginator = $query
                ->orderByDesc('created_at')
                ->paginate(
                    (int) $request->get('per_page', 15),
                    ['*'],
                    'page',
                    (int) $request->get('page', 1)
                );

            $data = $paginator->getCollection()->map(fn ($booking) => [
                'id'                   => $booking->id,
                'status'               => $booking->status,
                'seats'                => $booking->seats,
                'communication_number' => $booking->communication_number,
                'passenger' => [
                    'id'    => $booking->user?->id,
                    'name'  => trim(($booking->user?->first_name ?? '') . ' ' . ($booking->user?->last_name ?? '')),
                    'email' => $booking->user?->email,
                ],
                'ride' => [
                    'id'                  => $booking->ride?->id,
                    'pickup_address'      => $booking->ride?->pickup_address,
                    'destination_address' => $booking->ride?->destination_address,
                    'departure_time'      => $booking->ride?->departure_time?->toIso8601String(),
                    'price_per_seat'      => $booking->ride?->price_per_seat,
                    'ride_status'         => $booking->ride?->status,
                    'driver' => [
                        'id'   => $booking->ride?->driver?->id,
                        'name' => trim(
                            ($booking->ride?->driver?->first_name ?? '') . ' ' .
                            ($booking->ride?->driver?->last_name ?? '')
                        ),
                    ],
                ],
                'total_price' => $booking->seats * ($booking->ride?->price_per_seat ?? 0),
                'booked_at'   => $booking->created_at->toIso8601String(),
                'completed_at'=> $booking->completed_at?->toIso8601String(),
            ])->values();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'meta'   => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'filter'       => $status,
                ],
                'counts' => $counts,
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
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }

    public function cancelTrip(int $rideId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ], [
            'reason.required' => 'A cancellation reason is required.',
            'reason.min'      => 'Reason must be at least 10 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $agent = $request->attributes->get('staffEmployee');
            $ride  = \App\Models\Ride::findOrFail($rideId);

            // Only cancel active/full rides
            if (!in_array($ride->status, ['active', 'full', 'awaiting_confirmation'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cannot cancel a ride with status: {$ride->status}.",
                ], 422);
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($ride, $request, $agent) {

                // Refund all confirmed bookings (e-pay only)
                $confirmedBookings = $ride->bookings()
                    ->whereIn('status', ['confirmed', 'pending'])
                    ->get();

                foreach ($confirmedBookings as $booking) {
                    $booking->update(['status' => 'cancelled']);
                }

                // Cancel the ride
                $ride->update(['status' => 'cancelled']);

                // Notify affected passengers
                $notificationService = app(\App\Services\NotificationService::class);
                foreach ($confirmedBookings as $booking) {
                    $notificationService->createNotification(
                        \App\Models\User::find($booking->user_id),
                        'ride_cancelled',
                        'رحلتك تم إلغاؤها',
                        "تم إلغاء الرحلة من قِبل فريق الدعم. السبب: {$request->input('reason')}",
                        ['ride_id' => $ride->id],
                        'high',
                        'ride'
                    );
                }

                \Illuminate\Support\Facades\Log::info('Staff cancelled trip', [
                    'ride_id'      => $ride->id,
                    'agent_id'     => $agent->id,
                    'agent_name'   => $agent->fullName(),
                    'reason'       => $request->input('reason'),
                    'bookings_affected' => $confirmedBookings->count(),
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Trip cancelled successfully. All affected passengers have been notified.',
                'data'    => [
                    'ride_id'            => $ride->id,
                    'new_status'         => 'cancelled',
                    'bookings_cancelled' => $ride->bookings()->where('status', 'cancelled')->count(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ride not found.',
            ], 404);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Staff trip cancellation failed', [
                'ride_id' => $rideId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

// POST /api/staff/bookings/{bookingId}/cancel
    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ], [
            'reason.required' => 'A cancellation reason is required.',
            'reason.min'      => 'Reason must be at least 10 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $agent   = $request->attributes->get('staffEmployee');
            $booking = \App\Models\Booking::with(['ride', 'user'])->findOrFail($bookingId);

            // Only cancel active bookings
            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cannot cancel a booking with status: {$booking->status}.",
                ], 422);
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $request, $agent) {

                $seatsToRestore = $booking->seats;
                $ride           = $booking->ride;

                // Cancel booking
                $booking->update(['status' => 'cancelled']);

                // Restore seats on the ride if it's still active
                if ($ride && in_array($ride->status, ['active', 'full', 'awaiting_confirmation'])) {
                    $ride->increment('available_seats', $seatsToRestore);

                    // If ride was full, set back to active
                    if ($ride->status === 'full') {
                        $ride->update(['status' => 'active']);
                    }
                }

                // Notify the passenger
                if ($booking->user) {
                    app(\App\Services\NotificationService::class)->createNotification(
                        $booking->user,
                        'booking_cancelled',
                        'تم إلغاء حجزك',
                        "تم إلغاء حجزك من قِبل فريق الدعم. السبب: {$request->input('reason')}",
                        ['booking_id' => $booking->id, 'ride_id' => $ride?->id],
                        'high',
                        'ride'
                    );
                }

                \Illuminate\Support\Facades\Log::info('Staff cancelled booking', [
                    'booking_id'  => $booking->id,
                    'ride_id'     => $booking->ride_id,
                    'agent_id'    => $agent->id,
                    'agent_name'  => $agent->fullName(),
                    'reason'      => $request->input('reason'),
                    'seats'       => $seatsToRestore,
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Booking cancelled successfully. The passenger has been notified.',
                'data'    => [
                    'booking_id' => $booking->id,
                    'new_status' => 'cancelled',
                    'seats_restored_to_ride' => $booking->seats,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Booking not found.',
            ], 404);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Staff booking cancellation failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }
}
