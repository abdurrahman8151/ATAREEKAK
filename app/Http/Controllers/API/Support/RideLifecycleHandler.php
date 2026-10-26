<?php

namespace App\Http\Controllers\API\Support;

use App\DTOs\Ride\CreateRideDTO;
use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\API\ScoreController;
use App\Http\Requests\CreateRideRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\RideResource;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideService;
use App\Services\Score\ScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ride-lifecycle read and create actions for RideController (create, create-with-route, the
 * driver's rides, the passenger's bookings, and getRides), moved out verbatim.
 *
 * RideController keeps the public method names and signatures, so every route binding is
 * unchanged. The bodies are the original ones; response envelopes, status codes and log messages
 * are kept exactly.
 */
class RideLifecycleHandler
{
    public function __construct(
        private readonly RideService $rideService,
        private readonly BookingService $bookingService,
        private readonly GeocodingService $geocodingService,
        private readonly RouteCalculationService $routeService,
        private readonly ScoreService $scoreService,
    ) {}

    public function create(CreateRideRequest $request): JsonResponse
    {
        try {
            $dto = CreateRideDTO::fromRequest($request->validated(), $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());
            // RV-38: RideResource reads $this->driver (and ->driver->profile). Eager-load them
            // here — a freshly created ride carries no relation, so rendering it lazy-loaded the
            // driver (one extra query per response, and a lazy-load violation under strictness).
            $ride->load(['driver', 'driver.profile']);
            $score = $this->scoreService->getScore($request->user());

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
                'message' => 'Ride created successfully',
                'driver_score' => ScoreController::formatScore($score),
            ], 201);

        } catch (DomainException|\InvalidArgumentException $e) {
            // RV-45 (owner ruling): a refused ride creation is a curated domain refusal, not a
            // fault. Return the curated sentence with 422, matching the branch in
            // this controller. RV-13 holds: anything else stays generic below.
            Log::error('Ride creation refused', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Ride creation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The request could not be completed. Please try again.',
            ], 500);
        }
    }

    public function createRideWithRoute(CreateRideRequest $request): JsonResponse
    {
        // RV-14: this endpoint used to carry its OWN inline rule set, and the two sets had DRIFTED
        // apart in ways that mattered:
        //   price_per_seat        CreateRideRequest min:100|max:100000   vs  here min:0  (NO bound)
        //   communication_number  regex:/^09\d{8}$/                     vs  here 'string' (no check)
        //   notes                 max:500                               vs  here max:1000
        // So the weaker endpoint was the LIVE one: a ride could be created for a price of 0, or
        // with "banana" as the contact number. That is RV-14's "lying endpoints" exactly - two
        // routes for one action answering to different rules.
        //
        // The price bound landed first (the column is decimal(15,2)), which is what this item was
        // waiting on. Both endpoints now validate identically, so a rule can only be changed once.
        $validated = $request->validated();

        try {
            $validated = $this->fillMissingAddresses($validated);
            $validated = $this->fillMissingRouteSummary($validated);

            $dto = CreateRideDTO::fromRequest($validated, $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Ride created successfully',
                'ride' => $ride,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e);
        } catch (\Throwable $e) {
            return $this->unexpectedCreateFailure($e, $request->user()->id);
        }
    }

    /**
     * Reverse-geocode any address the client left out, keeping the coordinates it sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fillMissingAddresses(array $validated): array
    {
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

        return $validated;
    }

    /**
     * Fill distance, duration and geometry from the routing service when the guard below flags
     * any of them as empty.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fillMissingRouteSummary(array $validated): array
    {
        if (empty($validated['route_geometry']) || empty($validated['distance']) || empty($validated['duration'])) {
            $route = $this->routeService->getRouteDetails(
                ['lat' => $validated['pickup_lat'],      'lng' => $validated['pickup_lng']],
                ['lat' => $validated['destination_lat'], 'lng' => $validated['destination_lng']]
            );
            // The guard above tests empty(), so the fill MUST test empty() too. `??` only
            // replaces null/absent, so a client sending distance=0 or duration=0 (both legal:
            // `numeric|min:0`) or route_geometry=[] (legal: `array`) tripped the guard and
            // then kept its own degenerate value while the server filled the OTHER fields -
            // storing a real server geometry beside a zero distance. Server-derived metres
            // are authoritative here (D6 = B), so fill exactly what the guard flagged.
            $validated['distance'] = empty($validated['distance']) ? $route['distance'] : $validated['distance'];
            $validated['duration'] = empty($validated['duration']) ? $route['duration'] : $validated['duration'];
            $validated['route_geometry'] = empty($validated['route_geometry'])
                ? ['type' => 'LineString', 'coordinates' => $route['geometry']]
                : $validated['route_geometry'];
        }

        return $validated;
    }

    private function invalidArgumentResponse(\InvalidArgumentException $e): JsonResponse
    {
        $message = $e->getMessage();

        return response()->json(['status' => 'error', 'message' => $message], 422);
    }

    private function unexpectedCreateFailure(\Throwable $e, int $userId): JsonResponse
    {
        Log::error('Create ride with route failed', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ]);

        return response()->json(['status' => 'error', 'message' => 'The request could not be completed. Please try again.'], 500);
    }

    public function index(Request $request): JsonResponse
    {
        $rides = $this->rideService->getUserRides($request->user()->id);
        $rides->load(['driver', 'driver.profile'])
            ->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

        return response()->json([
            'success' => true,
            'data' => RideResource::collection($rides),
        ]);
    }

    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $this->bookingService->getUserBookings($request->user()->id);
        $bookings->load(['user', 'user.profile', 'ride', 'ride.driver', 'ride.driver.profile']);

        return response()->json([
            'success' => true,
            'data' => BookingResource::collection($bookings),
        ]);
    }

    public function getRides(Request $request): JsonResponse
    {
        try {
            $rides = $this->rideService->getDriverRides($request->user()->id);

            return response()->json([
                'success' => true,
                'data' => $rides,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to fetch driver rides', [
                'driver_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rides.',
            ], 500);
        }
    }
}
