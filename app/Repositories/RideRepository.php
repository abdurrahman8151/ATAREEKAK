<?php

namespace App\Repositories;

use App\Interfaces\RideRepositoryInterface;
use App\Models\Ride;
use App\Models\Booking;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;

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
    /** ~5km corridor around a ride's route, expressed in degrees (planar/SRID 0). */
    private const ROUTE_BUFFER_DEGREES = 0.05;

    public function __construct(
        // ✅ NEW: Split services
        private GeocodingService $geocodingService,
        private RouteCalculationService $routeService
    ) {}

    /**
     * Create a new ride with geocoding and routing details.
     */
    public function createRide(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            // ────────────────────────────────────────────────────────────
            // 1) Pickup: use 'pickup_location' array if provided, otherwise geocode 'pickup_address'
            // ────────────────────────────────────────────────────────────
            if (
                isset($data['pickup_location']) &&
                is_array($data['pickup_location']) &&
                array_key_exists('lat', $data['pickup_location']) &&
                array_key_exists('lng', $data['pickup_location'])
            ) {
                $pickup = [
                    'lat' => (float) $data['pickup_location']['lat'],
                    'lng' => (float) $data['pickup_location']['lng'],
                ];
                // ✅ NEW: Use GeocodingService
                $pickupLabel = $this->geocodingService->reverseGeocode(
                    $pickup['lat'],
                    $pickup['lng']
                );
            } elseif (!empty($data['pickup_address'])) {
                // ✅ NEW: Use GeocodingService
                $pickupResult = $this->geocodingService->geocodeAddress($data['pickup_address']);
                $pickup = [
                    'lat' => (float) $pickupResult['lat'],
                    'lng' => (float) $pickupResult['lng'],
                ];
                $pickupLabel = $pickupResult['label'];
            } else {
                throw new \Exception("Missing pickup data: must provide 'pickup_location' or 'pickup_address'.");
            }

            // ────────────────────────────────────────────────────────────
            // 2) Destination: same pattern as pickup
            // ────────────────────────────────────────────────────────────
            if (
                isset($data['destination_location']) &&
                is_array($data['destination_location']) &&
                array_key_exists('lat', $data['destination_location']) &&
                array_key_exists('lng', $data['destination_location'])
            ) {
                $destination = [
                    'lat' => (float) $data['destination_location']['lat'],
                    'lng' => (float) $data['destination_location']['lng'],
                ];
                // ✅ NEW: Use GeocodingService
                $destinationLabel = $this->geocodingService->reverseGeocode(
                    $destination['lat'],
                    $destination['lng']
                );
            } elseif (!empty($data['destination_address'])) {
                // ✅ NEW: Use GeocodingService
                $destResult = $this->geocodingService->geocodeAddress($data['destination_address']);
                $destination = [
                    'lat' => (float) $destResult['lat'],
                    'lng' => (float) $destResult['lng'],
                ];
                $destinationLabel = $destResult['label'];
            } else {
                throw new \Exception("Missing destination data: must provide 'destination_location' or 'destination_address'.");
            }

            // ────────────────────────────────────────────────────────────
            // 3) Route summary: distance, duration, geometry (LineString)
            // ✅ NEW: Use RouteCalculationService
            // ────────────────────────────────────────────────────────────
            $route = $this->routeService->getRouteDetails($pickup, $destination);

            // ────────────────────────────────────────────────────────────
            // 4) Create the Ride model and fill non-spatial fields
            // ────────────────────────────────────────────────────────────
            $ride = new Ride();
            $ride->driver_id = $data['driver_id'];
            $ride->pickup_address = $pickupLabel;
            $ride->destination_address = $destinationLabel;
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
                'lat' => $pickup['lat'],
                'lng' => $pickup['lng'],
            ];
            $ride->destination_location = [
                'lat' => $destination['lat'],
                'lng' => $destination['lng'],
            ];

            // 6) Assign route_geometry as GeoJSON - WITH VALIDATION
            if (isset($route['geometry']) && is_array($route['geometry']) && !empty($route['geometry'])) {
                $validCoordinates = true;
                foreach ($route['geometry'] as $coord) {
                    if (!is_array($coord) || count($coord) < 2) {
                        $validCoordinates = false;
                        break;
                    }
                }

                if ($validCoordinates) {
                    $ride->route_geometry = [
                        'type' => 'LineString',
                        'coordinates' => $route['geometry'],
                    ];
                } else {
                    Log::warning('Invalid route geometry coordinates received', ['geometry' => $route['geometry']]);
                    $ride->route_geometry = null;
                }
            } else {
                Log::warning('No valid geometry data received from routing service');
                $ride->route_geometry = null;
            }

            // ────────────────────────────────────────────────────────────
            // 7) Save and return
            // ────────────────────────────────────────────────────────────
            $ride->save();
            return $ride->fresh();
        });
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

            if (!empty($data['departure_time'])) {
                $data['departure_time'] = Carbon::parse($data['departure_time'])->toDateTimeString();
            }

            $rawUpdates = [];
            if (isset($data['pickup_lat'], $data['pickup_lng'])) {
                $rawUpdates['pickup_location'] = DB::raw(
                    sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $data['pickup_lng'], $data['pickup_lat'])
                );
                unset($data['pickup_lat'], $data['pickup_lng']);
            }
            if (isset($data['destination_lat'], $data['destination_lng'])) {
                $rawUpdates['destination_location'] = DB::raw(
                    sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $data['destination_lng'], $data['destination_lat'])
                );
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
                'communication_number' => $bookingData['communication_number'] ?? " ",
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
     * Search rides based on criteria.
     */
    public function searchRides(array $params): Collection
    {
        $query = Ride::query()
            ->whereDate('departure_time', '=', Carbon::parse($params['departure_date']))
            ->where('available_seats', '>=', $params['seats_required'])
            ->where('status', 'active');

        $this->applySpatialFilters($query, $params);

        return $query->with('driver')->get();
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
            $ride = new Ride();

            // Fill every $fillable column except the two spatial ones.
            // Any column present in $data AND in $fillable is written automatically —
            // new columns never need a matching line added here.
            $ride->fill(array_diff_key($data, array_flip([
                'pickup_location',
                'destination_location',
            ])));

            // Spatial columns go through the custom mutators so they are stored
            // as ST_GeomFromText(POINT(...)) rather than plain JSON.
            $ride->pickup_location      = $data['pickup_location'];
            $ride->destination_location = $data['destination_location'];

            $ride->save();
            return $ride->fresh();
        });
    }

    /**
     * Apply spatial filters (unchanged)
     */
    private function applySpatialFilters($query, array $params): void
    {
        $maxDistance = 20 * 1000;
        $routeBufferDegrees = self::ROUTE_BUFFER_DEGREES;
        $srcWkt = sprintf('POINT(%F %F)', $params['source_lng'], $params['source_lat']);
        $dstWkt = sprintf('POINT(%F %F)', $params['dest_lng'], $params['dest_lat']);

        $query->where(function ($q) use ($maxDistance, $routeBufferDegrees, $srcWkt, $dstWkt) {
            $q->whereRaw(
                "ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?",
                [$srcWkt, $maxDistance]
            )
                ->whereRaw(
                    "ST_Distance_Sphere(destination_location, ST_GeomFromText(?, 4326)) <= ?",
                    [$dstWkt, $maxDistance]
                )
                ->orWhere(function ($q2) use ($routeBufferDegrees, $srcWkt, $dstWkt) {
                    $q2->whereNotNull('route_geometry')
                        ->whereRaw("JSON_VALID(route_geometry)")
                        ->whereRaw("JSON_EXTRACT(route_geometry, '$.coordinates') IS NOT NULL")
                        ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates')) = 'ARRAY'")
                        // A LineString needs at least two positions, and each position
                        // must itself be an array. ST_GeomFromGeoJSON raises error 3072
                        // on anything else, and that aborts the whole query rather than
                        // skipping the row, so one malformed ride would 500 every search.
                        ->whereRaw("JSON_LENGTH(JSON_EXTRACT(route_geometry, '$.coordinates')) >= 2")
                        ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates[0]')) = 'ARRAY'")
                        ->whereRaw(
                            "ST_Distance(ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry), 1, 0), ST_GeomFromText(?, 0)) <= ?",
                            [$srcWkt, $routeBufferDegrees]
                        )
                        ->whereRaw(
                            "ST_Distance(ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry), 1, 0), ST_GeomFromText(?, 0)) <= ?",
                            [$dstWkt, $routeBufferDegrees]
                        );
                });
        });
    }
}
