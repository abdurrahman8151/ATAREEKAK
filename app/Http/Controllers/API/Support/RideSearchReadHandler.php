<?php

namespace App\Http\Controllers\API\Support;

use App\Http\Resources\RideResource;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\Ride\RideService;
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
            $source = $request->filled('source_address')
                ? $this->geocodingService->geocodeAddress($validated['source_address'])
                : ['lat' => (float) $validated['source_lat'], 'lng' => (float) $validated['source_lng']];
            // RV-50: an unresolved address must not search from (0,0). Refusal stays inside the
            // catch below, so the public response is unchanged.
            if ($source === []) {
                throw new \InvalidArgumentException("Could not find a location for the source address: {$validated['source_address']}");
            }

            $destination = $request->filled('destination_address')
                ? $this->geocodingService->geocodeAddress($validated['destination_address'])
                : ['lat' => (float) $validated['dest_lat'], 'lng' => (float) $validated['dest_lng']];
            if ($destination === []) {
                throw new \InvalidArgumentException("Could not find a location for the destination address: {$validated['destination_address']}");
            }

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
            // RV-50 / owner ruling: an unresolved address is a curated refusal the user can correct,
            // not a fault. Only the RV-50 "Could not find a location" refusal is curated here; every
            // other exception still reaches the generic 500 below (RV-13).
            if (str_starts_with($e->getMessage(), 'Could not find a location for the')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            Log::error('Ride search failed', ['error' => $e->getMessage(), 'params' => $request->all()]);

            return response()->json(['success' => false, 'message' => 'Search failed.'], 500);
        } catch (\Throwable $e) {
            Log::error('Ride search failed', ['error' => $e->getMessage(), 'params' => $request->all()]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed.',
            ], 500);
        }
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
                'error' => $e->getMessage(),
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
            // RV-38: getRideById does not eager-load the driver, but RideResource reads it
            // (and driver.profile) — load them so rendering costs no lazy query.
            $ride->load(['driver', 'driver.profile']);

            // Only aggregate seat totals — no booking rows, no passenger data
            $seatsByStatus = $ride->bookings()
                ->selectRaw('status, COALESCE(SUM(seats), 0) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $confirmedSeats = (int) ($seatsByStatus['confirmed'] ?? 0);
            $pendingSeats = (int) ($seatsByStatus['pending'] ?? 0);

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
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
            if ($request->user()->id !== (int) $ride->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only the driver of this ride can view passenger details.',
                ], 403);
            }

            // ── Eager-load bookings with passenger profile ────────────────────
            $ride->load([
                'driver',           // RV-38: RideResource reads driver + driver.profile
                'driver.profile',
                'bookings' => fn ($q) => $q
                    ->with([
                        'user:id,first_name,last_name',
                        'user.profile:user_id,profile_photo',
                    ])
                    ->orderBy('created_at'),
            ]);

            $ride->loadCount(['bookings as total_booked_seats' => fn ($q) => $q->select(DB::raw('COALESCE(SUM(seats), 0)')),
            ]);

            // ── Seat summary (in-memory, bookings already loaded) ─────────────
            $seatsByStatus = $ride->bookings
                ->groupBy('status')
                ->map(fn ($group) => (int) $group->sum('seats'));

            $confirmedSeats = $seatsByStatus['confirmed'] ?? 0;
            $pendingSeats = $seatsByStatus['pending'] ?? 0;

            // ── Build passenger list ──────────────────────────────────────────
            $bookingList = $ride->bookings->map(function ($booking) {
                $avatar = $booking->user?->profile?->profile_photo
                    ? asset('storage/'.$booking->user->profile->profile_photo)
                    : null;

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
            })->values();

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
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
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get route options.',
            ], 500);
        }
    }
}
