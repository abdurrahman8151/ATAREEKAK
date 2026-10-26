<?php

namespace App\Repositories;

use App\Interfaces\Geocoding\GeocodingServiceInterface;
use App\Interfaces\Geocoding\RouteCalculationServiceInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Support\GeoPoint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ride Repository (UPDATED)
 *
 * ✅ NOW USES: New split geocoding services
 * ❌ WAS USING: Old OpenRouteService god class
 *
 * Changes:
 * - Constructor: Uses GeocodingService + RouteCalculationService
 * - All geocoding calls → GeocodingService
 * - All routing calls → RouteCalculationService
 */
class RideRepository implements RideRepositoryInterface
{
    public function __construct(
        // ✅ NEW: Split services
        private GeocodingServiceInterface $geocodingService,
        private RouteCalculationServiceInterface $routeService
    ) {}

    /**
     * Create a new ride with geocoding and routing details.
     */
    public function createRide(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            $points = $this->resolveRidePoints($data);

            // ────────────────────────────────────────────────────────────
            // 3) Route summary: distance, duration, geometry (LineString)
            // ✅ NEW: Use RouteCalculationService
            // ────────────────────────────────────────────────────────────
            $route = $this->routeService->getRouteDetails($points['pickup'], $points['destination']);

            $ride = $this->buildRide($data, $points, $route);

            // ────────────────────────────────────────────────────────────
            // 7) Save and return
            // ────────────────────────────────────────────────────────────
            $ride->save();

            return $ride->fresh();
        });
    }

    /**
     * Resolve the pickup and destination endpoints of a ride about to be created.
     *
     * @return array{pickup: array{lat: float, lng: float}, destination: array{lat: float, lng: float}, pickup_label: string, destination_label: string}
     */
    private function resolveRidePoints(array $data): array
    {
        // ────────────────────────────────────────────────────────────
        // 1) Pickup: use 'pickup_location' array if provided, otherwise geocode 'pickup_address'
        // ────────────────────────────────────────────────────────────
        $pickupPoint = $this->resolveEndpoint($data, 'pickup');
        $pickup = ['lat' => $pickupPoint['lat'], 'lng' => $pickupPoint['lng']];

        // ────────────────────────────────────────────────────────────
        // 2) Destination: same pattern as pickup
        // ────────────────────────────────────────────────────────────
        $destinationPoint = $this->resolveEndpoint($data, 'destination');
        $destination = ['lat' => $destinationPoint['lat'], 'lng' => $destinationPoint['lng']];

        return [
            'pickup' => $pickup,
            'destination' => $destination,
            'pickup_label' => $pickupPoint['label'],
            'destination_label' => $destinationPoint['label'],
        ];
    }

    /**
     * Build the unsaved Ride for the resolved points and route.
     *
     * @param  array<string, mixed>  $data
     * @param  array{pickup: array{lat: float, lng: float}, destination: array{lat: float, lng: float}, pickup_label: string, destination_label: string}  $points
     * @param  array<string, mixed>  $route
     */
    private function buildRide(array $data, array $points, array $route): Ride
    {
        // ────────────────────────────────────────────────────────────
        // 4) Create the Ride model and fill non-spatial fields
        // ────────────────────────────────────────────────────────────
        $ride = new Ride;
        $ride->driver_id = $data['driver_id'];
        $ride->pickup_address = $points['pickup_label'];
        $ride->destination_address = $points['destination_label'];
        $ride->distance = $route['distance'];
        $ride->duration = $route['duration'];
        $ride->departure_time = Carbon::parse($data['departure_time'])->toDateTimeString();
        $ride->available_seats = $data['available_seats'];
        $ride->payment_method = $data['payment_method'];
        $ride->price_per_seat = $data['price_per_seat'];
        $ride->vehicle_type = $data['vehicle_type'];
        $ride->notes = $data['notes'] ?? null;
        $ride->booking_type = $data['booking_type'] ?? 'direct';

        // ────────────────────────────────────────────────────────────
        // 5) Assign spatial columns via Eloquent mutators
        // ────────────────────────────────────────────────────────────
        $ride->pickup_location = [
            'lat' => $points['pickup']['lat'],
            'lng' => $points['pickup']['lng'],
        ];
        $ride->destination_location = [
            'lat' => $points['destination']['lat'],
            'lng' => $points['destination']['lng'],
        ];

        // 6) Assign route_geometry as GeoJSON - WITH VALIDATION
        $ride->route_geometry = $this->validRouteGeometry($route);

        return $ride;
    }

    /**
     * Resolve one endpoint (pickup or destination) to coordinates and a label. A 'location' pair is
     * reverse-geocoded; an 'address' is geocoded. A failed address lookup is a curated refusal (RV-50),
     * never a (0,0) coordinate.
     *
     * @return array{lat: float, lng: float, label: string}
     */
    private function resolveEndpoint(array $data, string $role): array
    {
        if (
            isset($data[$role.'_location']) &&
            is_array($data[$role.'_location']) &&
            array_key_exists('lat', $data[$role.'_location']) &&
            array_key_exists('lng', $data[$role.'_location'])
        ) {
            $lat = (float) $data[$role.'_location']['lat'];
            $lng = (float) $data[$role.'_location']['lng'];

            return ['lat' => $lat, 'lng' => $lng, 'label' => $this->geocodingService->reverseGeocode($lat, $lng)];
        }

        if (! empty($data[$role.'_address'])) {
            $address = $data[$role.'_address'];
            $result = $this->geocodingService->geocodeAddress($address);
            if ($result === []) {
                // RV-50: a miss is a curated refusal, never a null-island (0,0) coordinate.
                throw new \InvalidArgumentException("Could not find a location for the {$role} address: {$address}");
            }

            return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng'], 'label' => $result['label']];
        }

        throw new \Exception("Missing {$role} data: must provide '{$role}_location' or '{$role}_address'.");
    }

    /**
     * The route geometry as a GeoJSON LineString, or null when the routing service returned none or a malformed point.
     */
    private function validRouteGeometry($route): ?array
    {
        if (! (isset($route['geometry']) && is_array($route['geometry']) && ! empty($route['geometry']))) {
            Log::warning('No valid geometry data received from routing service');

            return null;
        }

        foreach ($route['geometry'] as $coord) {
            if (! is_array($coord) || count($coord) < 2) {
                Log::warning('Invalid route geometry coordinates received', ['geometry' => $route['geometry']]);

                return null;
            }
        }

        return ['type' => 'LineString', 'coordinates' => $route['geometry']];
    }

    /**
     * Fetch upcoming rides.
     */
    public function getUpcomingRides(): Collection
    {
        Log::info('RideRepository: Fetching upcoming rides');

        return Ride::with('driver.profile')
            ->where('departure_time', '>', now())
            ->orderBy('departure_time', 'asc')
            ->get();
    }

    /**
     * Get ride by ID.
     */
    public function getRideById(int $rideId): Ride
    {
        Log::info('RideRepository: Fetch ride by ID', ['ride_id' => $rideId]);

        return Ride::with(['driver.profile', 'bookings.user'])->findOrFail($rideId);
    }

    /**
     * Update a ride.
     */
    public function updateRide(int $rideId, array $data): Ride
    {
        Log::info('RideRepository: Updating ride', ['ride_id' => $rideId]);

        return DB::transaction(function () use ($rideId, $data) {
            $ride = Ride::findOrFail($rideId);

            if (! empty($data['departure_time'])) {
                $data['departure_time'] = Carbon::parse($data['departure_time'])->toDateTimeString();
            }

            $rawUpdates = [];
            if (isset($data['pickup_lat'], $data['pickup_lng'])) {
                // RV-25: POINT(lat lng) via the shared GeoPoint helper (lat-first, matching
                // MySQL's EPSG:4326 axis-order). See Ride::setPickupLocationAttribute.
                $rawUpdates['pickup_location'] = GeoPoint::fromLatLng(
                    (float) $data['pickup_lat'],
                    (float) $data['pickup_lng']
                )->raw();
                unset($data['pickup_lat'], $data['pickup_lng']);
            }
            if (isset($data['destination_lat'], $data['destination_lng'])) {
                $rawUpdates['destination_location'] = GeoPoint::fromLatLng(
                    (float) $data['destination_lat'],
                    (float) $data['destination_lng']
                )->raw();
                unset($data['destination_lat'], $data['destination_lng']);
            }

            $ride->fill($data);

            if ($rawUpdates) {
                $current = $ride->getAttributes();
                $ride->setRawAttributes(array_merge($current, $rawUpdates), true);
            }

            $ride->save();

            Log::info('RideRepository: Ride updated', ['ride_id' => $ride->id]);

            return $ride->fresh();
        });
    }

    /**
     * Delete a ride.
     */
    public function deleteRide(int $rideId): bool
    {
        Log::info('RideRepository: Deleting ride', ['ride_id' => $rideId]);

        return (bool) Ride::destroy($rideId);
    }

    /**
     * Get rides for a specific driver.
     */
    public function getDriverRides(int $userId): Collection
    {
        Log::info('RideRepository: Fetching driver rides', ['user_id' => $userId]);

        return Ride::where('driver_id', $userId)
            ->with('driver.receivedRatings') // AF-4: batch the rating for RideResource
            ->withCount('bookings')
            ->orderBy('departure_time', 'desc')
            ->get();
    }

    /**
     * Book seats on a ride.
     */
    public function bookRide(int $rideId, array $bookingData): Booking
    {
        return DB::transaction(function () use ($rideId, $bookingData) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            $booking = Booking::create([
                'user_id' => $bookingData['user_id'],
                'ride_id' => $rideId,
                'seats' => $bookingData['seats'],
                'status' => $bookingData['status'],
                'communication_number' => $bookingData['communication_number'] ?? ' ',
            ]);

            if ($booking->status === Booking::CONFIRMED) {
                $ride->decrement('available_seats', $bookingData['seats']);

                if ($ride->available_seats <= 0) {
                    $ride->status = 'full';
                    $ride->save();
                }
            }

            return $booking->load('user', 'ride');
        });
    }

    /**
     * Create a ride with pre-calculated geometry.
     *
     * Uses fill() against $fillable instead of listing every column manually.
     * This means any new fillable field added to the Ride model (e.g. cash_creation_fee,
     * cash_fee_deferred) is automatically passed through without touching this method.
     *
     * Spatial columns (pickup_location, destination_location) are excluded from fill()
     * and assigned separately so the custom mutators (ST_GeomFromText) always fire.
     */
    public function createRideWithGeometry(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            $ride = new Ride;

            // Fill every $fillable column except the two spatial ones.
            // Any column present in $data AND in $fillable is written automatically —
            // new columns never need a matching line added here.
            $ride->fill(array_diff_key($data, array_flip([
                'pickup_location',
                'destination_location',
            ])));

            // Spatial columns go through the custom mutators so they are stored
            // as ST_GeomFromText(POINT(...)) rather than plain JSON.
            $ride->pickup_location = $data['pickup_location'];
            $ride->destination_location = $data['destination_location'];

            $ride->save();

            return $ride->fresh();
        });
    }

    /**
     * AF-4 (app-future audit): searchRides() and applySpatialFilters() were
     * deleted from this repository. A persistence object owning a second,
     * parallel spatial query (unstructured, string status, no eager loading) is
     * exactly the "one concept, two places" defect; the live path was
     * RideService::searchRides, which now delegates solely to RideSearchService.
     */
}
