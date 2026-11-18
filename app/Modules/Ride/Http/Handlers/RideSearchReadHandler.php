<?php

namespace App\Modules\Ride\Http\Handlers;

use App\Http\Resources\RideResource;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Ride\Services\GeocodingService;
use App\Modules\Ride\Services\RideService;
use App\Modules\Ride\Services\RouteCalculationService;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ride search and read actions for RideController (search, autocomplete, the public ride view,
 * the driver's passenger view, and route options), moved out verbatim.
 *
 * RideController keeps the public method names and signatures, so every route binding is
 * unchanged. The bodies are the original ones; response envelopes, status codes and log messages
 * are kept exactly.
 */
class RideSearchReadHandler
{
    public function __construct(
        private readonly RideService $rideService,
        private readonly GeocodingService $geocodingService,
        private readonly RouteCalculationService $routeService,
        private readonly ProfileDirectory $profiles,
        private readonly UserRatingReader $ratingReader,
    ) {}

    public function searchRides(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_address' => 'required_without_all:source_lat,source_lng|string|max:255',
            'source_lat' => 'required_with:source_lng|numeric',
            'source_lng' => 'required_with:source_lat|numeric',
            'destination_address' => 'required_without_all:dest_lat,dest_lng|string|max:255',
            'dest_lat' => 'required_with:dest_lng|numeric',
            'dest_lng' => 'required_with:dest_lat|numeric',
            'departure_date' => 'required|date|after:yesterday',
            'seats_required' => 'required|integer|min:1',
        ]);

        try {
            $source = $this->resolveSearchPoint($request, $validated, 'source_address', 'source_lat', 'source_lng', 'source');
            $destination = $this->resolveSearchPoint($request, $validated, 'destination_address', 'dest_lat', 'dest_lng', 'destination');

            $rides = $this->rideService->searchRides([
                'departure_date' => $validated['departure_date'],
                'seats_required' => $validated['seats_required'],
                'source_lat' => $source['lat'],
                'source_lng' => $source['lng'],
                'dest_lat' => $destination['lat'],
                'dest_lng' => $destination['lng'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $rides,
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->respondSearchRefusalOrFail($e, $request);
        } catch (\Throwable $e) {
            Log::error('Ride search failed', ['error' => SafeExceptionLog::message($e), 'params' => $request->all()]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed.',
            ], 500);
        }
    }

    /**
     * Resolve one search endpoint: an address through the geocoder, or raw coordinates.
     */
    private function resolveSearchPoint(Request $request, array $validated, string $addressKey, string $latKey, string $lngKey, string $label): array
    {
        $point = $request->filled($addressKey)
            ? $this->geocodingService->geocodeAddress($validated[$addressKey])
            : ['lat' => (float) $validated[$latKey], 'lng' => (float) $validated[$lngKey]];

        // RV-50: an unresolved address must not search from (0,0). Refusal stays inside the
        // catch below, so the public response is unchanged.
        if ($point === []) {
            throw new \InvalidArgumentException("Could not find a location for the {$label} address: {$validated[$addressKey]}");
        }

        return $point;
    }

    /**
     * Turn an InvalidArgumentException into the curated 422 or the generic 500.
     */
    private function respondSearchRefusalOrFail(\InvalidArgumentException $e, Request $request): JsonResponse
    {
        // RV-50 / owner ruling: an unresolved address is a curated refusal the user can correct,
        // not a fault. Only the RV-50 "Could not find a location" refusal is curated here; every
        // other exception still reaches the generic 500 below (RV-13).
        if (str_starts_with($e->getMessage(), 'Could not find a location for the')) {
            $message = $e->getMessage();

            return response()->json(['success' => false, 'message' => $message], 422);
        }

        Log::error('Ride search failed', ['error' => SafeExceptionLog::message($e), 'params' => $request->all()]);

        return response()->json(['success' => false, 'message' => 'Search failed.'], 500);
    }

    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|min:2|max:255',
        ]);

        try {
            $cacheKey = 'geocode.autocomplete.'.strtolower(trim($validated['text']));
            $results = Cache::remember($cacheKey, 3600, function () use ($validated) {
                return $this->geocodingService->autocomplete($validated['text']);
            });

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);

        } catch (\Throwable $e) {
            // RV-13: the exception text goes to the LOG, not to the client - a
            // QueryException carries the SQL and the table names.
            Log::error('RideController: request failed', [
                'error' => SafeExceptionLog::message($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The request could not be completed. Please try again.',
            ], 500);
        }
    }

    public function show(int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);
            // RV-38: getRideById does not eager-load the driver, but RideResource reads it.
            // Stage 2 C2: `driver.profile` is Identity's and arrives from its port in ONE query - the same
            // single query the retired eager load cost.
            $ride->load(['driver']);
            $photoPaths = $this->profiles->photoPathsForUsers([$ride->driver_id]);
            // Stage 2 C2: ONE batched rating read for the driver this response renders.
            $rideRatings = $this->ratingReader->averageForUsers([$ride->driver_id]);

            // Only aggregate seat totals — no booking rows, no passenger data
            $seatsByStatus = $ride->bookings()
                ->selectRaw('status, COALESCE(SUM(seats), 0) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $confirmedSeats = (int) ($seatsByStatus['confirmed'] ?? 0);
            $pendingSeats = (int) ($seatsByStatus['pending'] ?? 0);

            return response()->json([
                'success' => true,
                'data' => (new RideResource($ride))->withProfilePhotos($photoPaths)->withRatings($rideRatings),
                'seat_summary' => [
                    'total_capacity' => $ride->available_seats + $confirmedSeats + $pendingSeats,
                    'available' => $ride->available_seats,
                    'confirmed' => $confirmedSeats,
                    'pending' => $pendingSeats,
                ],
                // !! NO 'bookings' key — passenger data never leaves this endpoint !!
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found',
            ], 404);
        }
    }

    public function driverView(int $rideId, Request $request): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);

            // ── Hard authorization check ──────────────────────────────────────
            if (! $this->driverOwnsRide($ride, $request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only the driver of this ride can view passenger details.',
                ], 403);
            }

            $this->loadDriverViewRelations($ride);

            // Stage 2 C2: ONE batched Identity read covers the driver AND every passenger on the page -
            // the query the two retired `*.profile` eager loads cost, paid once.
            $photoPaths = $this->profiles->photoPathsForUsers(
                collect([$ride->driver_id])
                    ->merge($ride->bookings->pluck('user_id'))
                    ->unique()
                    ->filter()
                    ->values()
                    ->all()
            );

            // ── Seat summary (in-memory, bookings already loaded) ─────────────
            [$confirmedSeats, $pendingSeats, $seatsByStatus] = $this->driverViewSeatSummary($ride);

            // ── Build passenger list ──────────────────────────────────────────
            $bookingList = $ride->bookings->map(fn ($booking) => $this->driverViewBooking($booking, $photoPaths))->values();

            return response()->json([
                'success' => true,
                'data' => (new RideResource($ride))->withProfilePhotos($photoPaths)->withRatings($this->ratingReader->averageForUsers([$ride->driver_id])),
                'bookings' => [
                    'total_bookings' => $ride->bookings->count(),
                    'seat_summary' => [
                        'total_capacity' => $ride->available_seats + $confirmedSeats + $pendingSeats,
                        'available' => $ride->available_seats,
                        'confirmed' => $confirmedSeats,
                        'pending' => $pendingSeats,
                        'cancelled' => $seatsByStatus['cancelled'] ?? 0,
                        'completed' => $seatsByStatus['completed'] ?? 0,
                    ],
                    'list' => $bookingList,
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found',
            ], 404);
        }
    }

    /**
     * True when the authenticated user is the ride's driver.
     */
    private function driverOwnsRide($ride, Request $request): bool
    {
        return $request->user()->id === (int) $ride->driver_id;
    }

    /**
     * Eager-load everything the driver view renders.
     */
    private function loadDriverViewRelations($ride): void
    {
        // ── Eager-load bookings with passenger profile ────────────────────
        $ride->load([
            'driver',           // RV-38: RideResource reads driver
            // Stage 2 C2: `driver.profile` and `user.profile` are GONE - the caller resolves the same
            // columns from Identity's port in ONE batched read after this load.
            'bookings' => fn ($q) => $q
                ->with([
                    'user:id,first_name,last_name',
                ])
                ->orderBy('created_at'),
        ]);

        $ride->loadCount(['bookings as total_booked_seats' => fn ($q) => $q->select(DB::raw('COALESCE(SUM(seats), 0)')),
        ]);
    }

    /**
     * Aggregate booked seats per booking status from the loaded collection.
     */
    private function driverViewSeatSummary($ride): array
    {
        $seatsByStatus = $ride->bookings
            ->groupBy('status')
            ->map(fn ($group) => (int) $group->sum('seats'));

        $confirmedSeats = $seatsByStatus['confirmed'] ?? 0;
        $pendingSeats = $seatsByStatus['pending'] ?? 0;

        return [$confirmedSeats, $pendingSeats, $seatsByStatus];
    }

    /**
     * Shape one booking row for the driver passenger list.
     *
     * @param  array<int, string|null>  $photoPaths  stored profile paths keyed by user id
     */
    private function driverViewBooking($booking, array $photoPaths): array
    {
        $storedPhoto = $booking->user ? ($photoPaths[$booking->user->id] ?? null) : null;
        $avatar = $storedPhoto ? asset('storage/'.$storedPhoto) : null;

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'seats' => $booking->seats,
            'communication_number' => $booking->communication_number, // safe: driver only
            'booked_at' => $booking->created_at->toIso8601String(),
            'passenger' => $booking->user ? [
                'id' => $booking->user->id,
                'name' => trim("{$booking->user->first_name} {$booking->user->last_name}"),
                'avatar' => $avatar,
            ] : null,
        ];
    }

    public function getRouteOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat' => 'required|numeric|between:-90,90',
            'pickup_lng' => 'required|numeric|between:-180,180',
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
                round($origin['lat'], 4),
                round($origin['lng'], 4),
                round($destination['lat'], 4),
                round($destination['lng'], 4)
            );

            $routes = Cache::remember($cacheKey, 1800, function () use ($origin, $destination) {
                return $this->routeService->getRouteAlternatives($origin, $destination, 3);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'routes' => $routes,
                    'pickup' => $origin,
                    'destination' => $destination,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Route options failed', [
                'error' => SafeExceptionLog::message($e),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get route options.',
            ], 500);
        }
    }
}
