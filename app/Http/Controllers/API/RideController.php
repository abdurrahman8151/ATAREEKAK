<?php

namespace App\Http\Controllers\API;

use App\DTOs\Ride\CreateRideDTO;
use App\DTOs\Ride\BookRideDTO;
use App\Enums\SyrianCity;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityTripsRequest;
use App\Http\Requests\CreateRideRequest;
use App\Http\Requests\BookRideRequest;
use App\Http\Resources\CityTripResource;
use App\Http\Resources\RideResource;
use App\Http\Resources\BookingResource;
use App\Models\Ride;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\Ride\CityTripService;
use App\Services\Ride\RideService;
use App\Services\Ride\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Score\ScoreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

/**
 * Ride Controller
 *
 * FIXED:
 *  1. create() — broken catch block referenced $ride outside its scope
 *                and returned 201 on exception. Rewritten cleanly.
 *  2. passengerConfirmCompletion() — catch (\Exception) misses ArgumentCountError
 *     and other Error subclasses; changed to catch (\Throwable).
 *  3. All other catch blocks also upgraded to \Throwable for the same reason.
 *
 * ── Caching summary ──────────────────────────────────────────────────────────
 *
 *  CACHED  getRouteOptions()  route.options.{lat1}.{lng1}.{lat2}.{lng2}  30 min
 *  CACHED  autocomplete()     geocode.autocomplete.{text}                 1 hour
 *
 *  Everything else is either a mutation or a per-user live read (seat counts,
 *  booking status, available seats) where staleness would directly harm UX.
 */
class RideController extends Controller
{
    public function __construct(
        private readonly RideService             $rideService,
        private readonly BookingService          $bookingService,
        private readonly GeocodingService        $geocodingService,
        private readonly RouteCalculationService $routeService,
        private readonly NotificationService     $notificationService,
        private readonly CityTripService         $cityTripService,
    ) {}

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * POST /rides
     *
     * FIXED: catch block previously referenced $ride which only existed inside
     * try scope, and returned 201 even on failure. Now uses a single clean
     * try/catch with \Throwable to also catch PHP Errors.
     */
    public function createRide(CreateRideRequest $request): JsonResponse
    {
        try {
            $dto   = CreateRideDTO::fromRequest($request->validated(), $request->user()->id);
            $ride  = $this->rideService->createRide($dto, $request->user());
            $score = app(ScoreService::class)->getScore($request->user());

            return response()->json([
                'success'      => true,
                'data'         => new RideResource($ride),
                'message'      => 'Ride created successfully',
                'driver_score' => ScoreController::formatScore($score),
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Ride creation failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // ROUTE OPTIONS
    // =========================================================================

    /**
     * GET /rides/route-options
     *
     * CACHED — 30 minutes per coordinate pair.
     */
    public function getRouteOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat'      => 'required|numeric|between:-90,90',
            'pickup_lng'      => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
        ]);

        try {
            $origin = [
                'lat' => (float) $validated['pickup_lat'],
                'lng' => (float) $validated['pickup_lng'],
            ];
            $destination = [
                'lat' => (float) $validated['destination_lat'],
                'lng' => (float) $validated['destination_lng'],
            ];

            $cacheKey = sprintf(
                'route.options.%s.%s.%s.%s',
                round($origin['lat'],      4),
                round($origin['lng'],      4),
                round($destination['lat'], 4),
                round($destination['lng'], 4)
            );

            $routes = Cache::remember($cacheKey, 1800, function () use ($origin, $destination) {
                return $this->routeService->getRouteAlternatives($origin, $destination, 3);
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'routes'      => $routes,
                    'pickup'      => $origin,
                    'destination' => $destination,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Route options failed', [
                'error'   => $e->getMessage(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get route options: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // BOOK RIDE
    // =========================================================================

    /**
     * POST /rides/{rideId}/book
     */
    public function bookRide(BookRideRequest $request, int $rideId): JsonResponse
    {
        try {
            $dto     = BookRideDTO::fromRequest($request->validated(), $request->user()->id, $rideId);
            $booking = $this->bookingService->bookRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'data'    => new BookingResource($booking),
                'message' => 'Ride booked successfully',
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * NOT cached — total_booked_seats changes with every booking.
     */
    // =========================================================================
// SHOW
// =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * NOT cached — seat counts and booking list change with every booking.
     * Returns the full ride data PLUS all bookings with passenger details.
     */
    // =========================================================================
// SHOW — PUBLIC (passenger browsing a ride)
// =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * Public endpoint. Returns ride details + seat counts ONLY.
     * NO passenger names, NO communication numbers, NO booking list.
     * Any authenticated user may call this.
     */
    public function show(int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);

            // Only aggregate seat totals — no booking rows, no passenger data
            $seatsByStatus = $ride->bookings()
                ->selectRaw('status, COALESCE(SUM(seats), 0) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $confirmedSeats = (int) ($seatsByStatus['confirmed'] ?? 0);
            $pendingSeats   = (int) ($seatsByStatus['pending']   ?? 0);

            return response()->json([
                'success'      => true,
                'data'         => new RideResource($ride),
                'seat_summary' => [
                    'total_capacity' => $ride->available_seats + $confirmedSeats + $pendingSeats,
                    'available'      => $ride->available_seats,
                    'confirmed'      => $confirmedSeats,
                    'pending'        => $pendingSeats,
                ],
                // !! NO 'bookings' key — passenger data never leaves this endpoint !!
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found',
            ], 404);
        }
    }

// =========================================================================
// DRIVER VIEW — PRIVATE (only the ride's own driver)
// =========================================================================

    /**
     * GET /rides/{rideId}/driver-view
     *
     * Returns the full booking list with passenger details and communication
     * numbers. Returns 403 for any user who is NOT the driver of this ride.
     */
    public function driverView(int $rideId, Request $request): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);

            // ── Hard authorization check ──────────────────────────────────────
            if ($request->user()->id !== (int) $ride->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only the driver of this ride can view passenger details.',
                ], 403);
            }

            // ── Eager-load bookings with passenger profile ────────────────────
            $ride->load([
                'bookings' => fn ($q) => $q
                    ->with([
                        'user:id,first_name,last_name',
                        'user.profile:user_id,profile_photo',
                    ])
                    ->orderBy('created_at'),
            ]);

            $ride->loadCount(['bookings as total_booked_seats' => fn ($q) =>
            $q->select(DB::raw('COALESCE(SUM(seats), 0)'))
            ]);

            // ── Seat summary (in-memory, bookings already loaded) ─────────────
            $seatsByStatus  = $ride->bookings
                ->groupBy('status')
                ->map(fn ($group) => (int) $group->sum('seats'));

            $confirmedSeats = $seatsByStatus['confirmed'] ?? 0;
            $pendingSeats   = $seatsByStatus['pending']   ?? 0;

            // ── Build passenger list ──────────────────────────────────────────
            $bookingList = $ride->bookings->map(function ($booking) {
                $avatar = $booking->user?->profile?->profile_photo
                    ? asset('storage/' . $booking->user->profile->profile_photo)
                    : null;

                return [
                    'id'                   => $booking->id,
                    'status'               => $booking->status,
                    'seats'                => $booking->seats,
                    'communication_number' => $booking->communication_number, // safe: driver only
                    'booked_at'            => $booking->created_at->toIso8601String(),
                    'passenger'            => $booking->user ? [
                        'id'     => $booking->user->id,
                        'name'   => trim("{$booking->user->first_name} {$booking->user->last_name}"),
                        'avatar' => $avatar,
                    ] : null,
                ];
            })->values();

            return response()->json([
                'success'  => true,
                'data'     => new RideResource($ride),
                'bookings' => [
                    'total_bookings' => $ride->bookings->count(),
                    'seat_summary'   => [
                        'total_capacity' => $ride->available_seats + $confirmedSeats + $pendingSeats,
                        'available'      => $ride->available_seats,
                        'confirmed'      => $confirmedSeats,
                        'pending'        => $pendingSeats,
                        'cancelled'      => $seatsByStatus['cancelled'] ?? 0,
                        'completed'      => $seatsByStatus['completed'] ?? 0,
                    ],
                    'list' => $bookingList,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found',
            ], 404);
        }
    }

    // =========================================================================
    // DRIVER RIDE LIST
    // =========================================================================

    public function getRides(Request $request): JsonResponse
    {
        try {
            // Same payload shape as always, except route_geometry is trimmed to
            // the path's start and end point — the full polyline is thousands of
            // coordinates per ride and this endpoint returns every ride a driver
            // has. GET /rides/{id} still returns the complete line for the map.
            $rides = $this->rideService->getDriverRides($request->user()->id)
                ->map(function (Ride $ride) {
                    return array_merge($ride->toArray(), [
                        'route_geometry' => $ride->routeEndpoints(),
                    ]);
                });

            return response()->json([
                'success' => true,
                'data'    => $rides,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to fetch driver rides', [
                'driver_id' => $request->user()->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rides: ' . $e->getMessage(),
            ], 500);
        }
    }


    // =========================================================================
    // SEARCH
    // =========================================================================

    public function searchRides(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_address'      => 'required_without_all:source_lat,source_lng|string|max:255',
            'source_lat'          => 'required_with:source_lng|numeric',
            'source_lng'          => 'required_with:source_lat|numeric',
            'destination_address' => 'required_without_all:dest_lat,dest_lng|string|max:255',
            'dest_lat'            => 'required_with:dest_lng|numeric',
            'dest_lng'            => 'required_with:dest_lat|numeric',
            'departure_date'      => 'required|date|after:yesterday',
            'seats_required'      => 'required|integer|min:1',
            'sort_by'             => 'sometimes|string|in:best,price_asc,price_desc,rating_desc,distance_asc,departure_time_asc',
        ]);

        try {
            $source = $request->filled('source_address')
                ? $this->geocodingService->geocodeAddress($validated['source_address'])
                : ['lat' => (float) $validated['source_lat'], 'lng' => (float) $validated['source_lng']];

            $destination = $request->filled('destination_address')
                ? $this->geocodingService->geocodeAddress($validated['destination_address'])
                : ['lat' => (float) $validated['dest_lat'], 'lng' => (float) $validated['dest_lng']];

            $rides = $this->rideService->searchRides([
                'departure_date' => $validated['departure_date'],
                'seats_required' => $validated['seats_required'],
                'source_lat'     => $source['lat'],
                'source_lng'     => $source['lng'],
                'dest_lat'       => $destination['lat'],
                'dest_lng'       => $destination['lng'],
                'sort_by'        => $validated['sort_by'] ?? 'best',
            ]);

            return response()->json([
                'success' => true,
                'data'    => RideResource::collection($rides),
            ]);

        } catch (\Throwable $e) {
            Log::error('Ride search failed', ['error' => $e->getMessage(), 'params' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // CITY TRIPS  (trips leaving from / arriving at the user's city)
    // =========================================================================

    /**
     * GET /rides/city-trips
     *
     * Every trip in the system that departs FROM or arrives AT the
     * authenticated user's city (users.address), paginated and filterable.
     *
     * NOT cached — available_seats changes on every booking, and a stale seat
     * count on a browse-and-book list directly causes failed bookings.
     *
     * The city is taken from the user's profile. A user who never set an
     * address gets 422 with a pointer to update their profile, unless they
     * pass an explicit ?city=.
     *
     * Query params — all optional. See CityTripsRequest for the exact rules.
     */
    public function cityTrips(CityTripsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user    = $request->user();

        // Explicit ?city= wins; otherwise fall back to the user's own city.
        $city = ! empty($filters['city'])
            ? SyrianCity::tryFromAddress($filters['city'])
            : SyrianCity::tryFromAddress($user->address);

        if ($city === null) {
            return response()->json([
                'success' => false,
                'message' => empty($user->address)
                    ? 'Your city is not set. Update your profile address, or pass ?city= explicitly.'
                    : "Unrecognised city: {$user->address}",
                'available_cities' => SyrianCity::values(),
            ], 422);
        }

        try {
            $paginator = $this->cityTripService->getCityTrips($city, $filters, $user->id);

            return response()->json([
                'success' => true,
                'data'    => CityTripResource::collection($paginator->getCollection()),
                'meta'    => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                    'has_more'     => $paginator->hasMorePages(),
                ],
                'city' => [
                    'value'     => $city->value,
                    'name_en'   => $city->englishName(),
                    'radius_km' => $city->radiusKm(),
                    'source'    => empty($filters['city']) ? 'user_profile' : 'query_param',
                ],
                // Opt-in: two extra COUNTs over the same spatial predicate.
                'counts' => filter_var($filters['with_counts'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? $this->cityTripService->getDirectionCounts(
                        $city,
                        $filters,
                        $user->id,
                        $filters['direction'] ?? 'both',
                        $paginator->total(),
                    )
                    : null,
                'applied_filters' => $filters + ['direction' => $filters['direction'] ?? 'both'],
            ]);

        } catch (\Throwable $e) {
            Log::error('City trips lookup failed', [
                'user_id' => $user->id,
                'city'    => $city->value,
                'filters' => $filters,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load trips for your city',
            ], 500);
        }
    }

    // =========================================================================
    // AUTOCOMPLETE
    // =========================================================================

    /**
     * GET /rides/autocomplete
     *
     * CACHED — 1 hour per search text.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|min:2|max:255',
        ]);

        try {
            $cacheKey = 'geocode.autocomplete.' . strtolower(trim($validated['text']));
            $results  = Cache::remember($cacheKey, 3600, function () use ($validated) {
                return $this->geocodingService->autocomplete($validated['text']);
            });

            return response()->json([
                'success' => true,
                'data'    => $results,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // MY RIDES / MY BOOKINGS
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $rides = $this->rideService->getUserRides($request->user()->id);
        $rides->load([
            'driver' => fn ($query) => $query->withAvg('receivedRatings as driver_rating', 'rating'),
            'driver.profile',
        ])
            ->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

        return response()->json([
            'success' => true,
            'data'    => RideResource::collection($rides),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'source_lat'     => 'required|numeric',
            'source_lng'     => 'required|numeric',
            'dest_lat'       => 'required|numeric',
            'dest_lng'       => 'required|numeric',
            'departure_date' => 'required|date',
            'seats_required' => 'required|integer|min:1',
        ]);

        try {
            $rides = $this->rideService->searchRides($request->all());
            $rides->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

            return response()->json([
                'success' => true,
                'data'    => RideResource::collection($rides),
                'count'   => $rides->count(),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $this->bookingService->getUserBookings($request->user()->id);
        $bookings->load(['user', 'user.profile', 'ride', 'ride.driver', 'ride.driver.profile']);

        return response()->json([
            'success' => true,
            'data'    => BookingResource::collection($bookings),
        ]);
    }

    // =========================================================================
    // CANCEL
    // =========================================================================

    public function cancel(int $rideId, Request $request): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'success' => true,
                'data'    => new RideResource($ride),
                'message' => 'Ride cancelled successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancelRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => 'Ride cancelled successfully.',
                'ride'    => $ride,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Ride cancellation failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->cancelBooking($bookingId, $request->user());

            try {
                $booking->loadMissing(['ride.driver']);
                if ($booking->ride?->driver) {
                    $this->notificationService->createNotification(
                        $booking->ride->driver,
                        'booking_cancelled_by_passenger',
                        'إلغاء حجز',
                        "ألغى أحد الركاب حجزه ({$booking->seats} مقعد). تم إعادة المقاعد لرحلتك.",
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'normal',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'success' => true,
                'data'    => new BookingResource($booking),
                'message' => 'Booking cancelled successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // ACCEPT / REJECT BOOKING
    // =========================================================================

    public function acceptBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->acceptBooking($bookingId, $request->user());

            try {
                $booking->loadMissing('user');
                if ($booking->user) {
                    $this->notificationService->createNotification(
                        $booking->user,
                        'booking_accepted',
                        'تم قبول حجزك',
                        'قبل السائق طلب حجزك. تحقق من تفاصيل رحلتك.',
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'high',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'success' => true,
                'data'    => new BookingResource($booking),
                'message' => 'Booking accepted successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function rejectBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->rejectBooking($bookingId, $request->user());

            try {
                $booking->loadMissing('user');
                if ($booking->user) {
                    $this->notificationService->createNotification(
                        $booking->user,
                        'booking_rejected',
                        'تم رفض طلب حجزك',
                        'اعتذر السائق عن قبول طلبك. يمكنك البحث عن رحلة أخرى.',
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'normal',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'success' => true,
                'data'    => new BookingResource($booking),
                'message' => 'Booking rejected successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // CREATE WITH ROUTE
    // =========================================================================

    public function createRideWithRoute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat'           => 'required|numeric|between:-90,90',
            'pickup_lng'           => 'required|numeric|between:-180,180',
            'destination_lat'      => 'required|numeric|between:-90,90',
            'destination_lng'      => 'required|numeric|between:-180,180',
            'pickup_address'       => 'nullable|string|max:500',
            'destination_address'  => 'nullable|string|max:500',
            'departure_time'       => 'required|date|after:' . now()->addMinutes(5)->toDateTimeString(),
            'available_seats'      => 'required|integer|min:1|max:8',
            'price_per_seat'       => 'required|numeric|min:0',
            'vehicle_type'         => 'required|string|max:100',
            'payment_method'       => 'required|in:cash,e-pay',
            'booking_type'         => 'required|in:direct,request',
            'communication_number' => 'required|string',
            'notes'                => 'nullable|string|max:1000',
            'route_geometry'       => 'nullable|array',
            'route_geometry.type'  => 'nullable|string|in:LineString',
            'route_geometry.coordinates' => 'nullable|array|min:2',
            'route_index'          => 'nullable|integer|min:0',
            'distance'             => 'nullable|numeric|min:0',
            'duration'             => 'nullable|numeric|min:0',
        ]);

        try {
            if (empty($validated['pickup_address'])) {
                $validated['pickup_address'] = $this->geocodingService->reverseGeocode(
                    $validated['pickup_lat'],
                    $validated['pickup_lng']
                );
            }
            if (empty($validated['destination_address'])) {
                $validated['destination_address'] = $this->geocodingService->reverseGeocode(
                    $validated['destination_lat'],
                    $validated['destination_lng']
                );
            }
            if (empty($validated['route_geometry']) || empty($validated['distance']) || empty($validated['duration'])) {
                $route = $this->routeService->getRouteDetails(
                    ['lat' => $validated['pickup_lat'],      'lng' => $validated['pickup_lng']],
                    ['lat' => $validated['destination_lat'], 'lng' => $validated['destination_lng']]
                );
                $validated['distance']       = $validated['distance']  ?? $route['distance'];
                $validated['duration']       = $validated['duration']  ?? $route['duration'];
                if (empty($validated['route_geometry'])) {
                    // Only persist a geometry the routing service actually returned.
                    // A LineString with fewer than two positions is not valid GeoJSON,
                    // and ST_GeomFromGeoJSON rejects it later during ride search —
                    // null is the same "no route" state the repository already stores.
                    $geometry = $route['geometry'] ?? null;
                    $validated['route_geometry'] = (is_array($geometry) && count($geometry) >= 2)
                        ? ['type' => 'LineString', 'coordinates' => $geometry]
                        : null;
                }
            }

            $dto  = CreateRideDTO::fromRequest($validated, $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => 'Ride created successfully',
                'ride'    => $ride,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Create ride with route failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // FINISH / CONFIRM
    // =========================================================================

    public function finish(int $rideId, Request $request): JsonResponse
    {
        return $this->finishRide($request, $rideId);
    }

    public function finishRide(Request $request, int $rideId): JsonResponse
    {
        return response()->json([
            'status'  => 'info',
            'message' => 'No driver action required. Once the departure time passes, '
                . 'passengers can confirm their completion. The ride finishes '
                . 'automatically when the last passenger confirms.',
            'data'    => [
                'ride_id'          => $rideId,
                'driver_confirmed' => false,          // nothing happened
                'ride_status'      => 'active',       // unchanged
            ],
        ]);
    }


    public function driverConfirmCompletion(Request $request, int $rideId): JsonResponse
    {
        return response()->json([
            'status'  => 'info',
            'message' => 'Driver confirmation is no longer required. '
                . 'Each passenger confirms individually. '
                . 'The ride completes automatically when all passengers have confirmed.',
        ]);
    }


    // =========================================================================
    // PASSENGER CONFIRM COMPLETION
    // =========================================================================

    /**
     * POST /bookings/{bookingId}/passenger-confirm
     *
     * FIXED: catch (\Exception) → catch (\Throwable)
     *
     * The original catch(\Exception) silently swallowed ArgumentCountError
     * thrown by PaymentStrategyFactory (an Error subclass, NOT Exception),
     * causing Laravel to return an HTML 500 page instead of JSON. The escrow
     * was already committed, leaving admin holding funds with no driver payout.
     *
     * Fix here + PaymentStrategyFactory fix together make this atomic.
     */
    public function passengerConfirmCompletion(Request $request, int $booking): JsonResponse
    {
        try {
            $result = $this->bookingService->passengerConfirmCompletion(
                $booking,
                $request->user()
            );

            // ── Notifications ──────────────────────────────────────────────────
            // Always notify the driver that this passenger confirmed (and was paid).
            // If the ride is now fully finished, send a separate "all done" notice.
            try {
                $b = \App\Models\Booking::with('ride.driver')->find($booking);

                if ($b?->ride?->driver) {
                    if ($result['ride_finished']) {
                        // All passengers confirmed — ride is closed
                        $this->notificationService->createNotification(
                            $b->ride->driver,
                            'ride_completed',
                            'اكتملت رحلتك',
                            'أكد جميع الركاب وصولهم. تم تحويل جميع المبالغ إلى محفظتك.',
                            ['ride_id' => $b->ride_id],
                            'high',
                            'ride'
                        );
                    } else {
                        // Partial — this passenger confirmed, others still pending
                        $passengerName = trim(
                            ($request->user()->first_name ?? '') . ' ' .
                            ($request->user()->last_name  ?? '')
                        );

                        $this->notificationService->createNotification(
                            $b->ride->driver,
                            'passenger_confirmed',
                            'تأكيد ركاب',
                            "أكد {$passengerName} وصوله. تم تحويل حصته إلى محفظتك.",
                            ['booking_id' => $booking, 'ride_id' => $b->ride_id],
                            'normal',
                            'ride'
                        );
                    }
                }
            } catch (\Throwable) {
                // Notification failure must never block the confirmation response
            }

            return response()->json([
                'status'        => 'success',
                'message'       => $result['message'],
                'ride_finished' => $result['ride_finished'],
            ]);

        } catch (\Throwable $e) {
            Log::error('Passenger confirmation failed', [
                'booking_id' => $booking,
                'user_id'    => $request->user()->id,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // PARTIAL SEAT CANCEL
    // =========================================================================

    public function cancelPartialSeats(Request $request, int $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'seats_to_cancel' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->bookingService->cancelPartialSeats(
                $bookingId,
                $validated['seats_to_cancel'],
                $request->user()
            );

            try {
                $booking = \App\Models\Booking::with('ride.driver')->find($bookingId);
                if ($booking?->ride?->driver) {
                    $this->notificationService->createNotification(
                        $booking->ride->driver,
                        'seats_partially_cancelled',
                        'إلغاء جزئي للمقاعد',
                        "ألغى أحد الركاب {$validated['seats_to_cancel']} مقعد من حجزه.",
                        ['booking_id' => $bookingId, 'ride_id' => $booking->ride_id],
                        'normal',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => $result['data'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Partial seat cancellation failed', [
                'booking_id' => $bookingId,
                'user_id'    => $request->user()->id,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // =========================================================================
    // NO SHOW REPORTS
    // =========================================================================

    public function reportPassengerNoShow(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingService->reportPassengerNoShow($bookingId, $request->user());

            try {
                $booking = \App\Models\Booking::with('user')->find($bookingId);
                if ($booking?->user) {
                    $this->notificationService->createNotification(
                        $booking->user,
                        'no_show_recorded',
                        'تسجيل غياب',
                        'أفاد السائق بغيابك في موعد انطلاق الرحلة. تم تسجيل ذلك في ملفك.',
                        ['booking_id' => $bookingId],
                        'high',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json(['status' => 'success', 'message' => $result['message']]);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function reportDriverNoShow(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->reportDriverNoShow($rideId, $request->user());

            try {
                $ride = $this->rideService->getRideById($rideId);
                if ($ride->driver) {
                    $this->notificationService->createNotification(
                        $ride->driver,
                        'driver_no_show_recorded',
                        'تقرير غياب',
                        'أفاد أحد الركاب بغيابك في موعد انطلاق الرحلة.',
                        ['ride_id' => $rideId],
                        'high',
                        'ride'
                    );
                }
            } catch (\Throwable) {}

            return response()->json(['status' => 'success', 'message' => $result['message']]);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
