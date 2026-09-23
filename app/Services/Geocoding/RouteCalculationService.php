<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Route Calculation Service
 *
 * EXTRACTED from OpenRouteService
 *
 * Single Responsibility: Calculate routes between points
 *
 * Uses OpenRouteService API for routing only
 */
final class RouteCalculationService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openrouteservice.org/';
    private int $cacheTtl = 3600;

    /**
     * NOTE: a missing OPENROUTE_API_KEY must never throw here. This service is
     * constructor-injected into RideController and RideRepository, so throwing
     * took down every ride/booking endpoint — including the ones that do no
     * routing at all — with a 500 before any controller code could run.
     *
     * Without a key we degrade to the straight-line fallback below instead.
     */
    public function __construct()
    {
        $this->apiKey = (string) config('services.openroute.api_key', '');
    }

    /**
     * Whether the remote routing API is usable. When false, callers silently
     * receive fallback estimates (marked with is_fallback => true).
     */
    private function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Get route details between two points
     */
    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        // No key: skip the guaranteed-401 round trip, and don't cache the
        // degraded result so routing recovers immediately once a key is set.
        if (!$this->hasApiKey()) {
            return $this->calculateFallbackRoute($origin, $destination);
        }

        $cacheKey = "route:v2:" . md5(json_encode([$origin, $destination]));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination) {
            try {
                return $this->fetchRoute($origin, $destination);
            } catch (\Throwable $e) {
                Log::warning('Route calculation failed, using fallback', [
                    'error' => $e->getMessage()
                ]);

                return $this->calculateFallbackRoute($origin, $destination);
            }
        });
    }

    /**
     * Get multiple route alternatives
     */
    public function getRouteAlternatives(array $origin, array $destination, int $maxAlternatives = 3): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        if (!$this->hasApiKey()) {
            return [$this->calculateFallbackRoute($origin, $destination)];
        }

        $cacheKey = "routes:v2:" . md5(json_encode([$origin, $destination, $maxAlternatives]));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $maxAlternatives) {
            try {
                return $this->fetchAlternatives($origin, $destination, $maxAlternatives);
            } catch (\Throwable $e) {
                Log::warning('Alternative routes failed, trying single route', [
                    'error' => $e->getMessage()
                ]);

                // ORS refuses the alternative-routes algorithm above ~100 km
                // (error 2004), which is common for intercity trips here. A single
                // real road route is far better than a straight line, so only fall
                // back to haversine if the plain directions call fails too.
                try {
                    return [$this->fetchRoute($origin, $destination) + ['route_index' => 0]];
                } catch (\Throwable $inner) {
                    Log::warning('Single route also failed, using fallback', [
                        'error' => $inner->getMessage()
                    ]);

                    return [$this->calculateFallbackRoute($origin, $destination)];
                }
            }
        });
    }

    /**
     * Fetch route from OpenRouteService API
     */
    private function fetchRoute(array $origin, array $destination): array
    {
        $url = $this->baseUrl . 'v2/directions/driving-car/json';

        $payload = [
            'coordinates' => [
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
            ],
            'instructions' => false,
            'geometry' => true,
        ];

        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Authorization' => $this->apiKey])
            ->timeout(30)
            ->post($url, $payload);

        if (!$response->successful()) {
            throw new \Exception("Route API error: HTTP {$response->status()}");
        }

        $data = $response->json();

        if (empty($data['routes'][0]['summary'])) {
            throw new \Exception('Invalid route response');
        }

        $route = $data['routes'][0];

        return [
            'distance' => (float) $route['summary']['distance'], // meters
            'duration' => (float) $route['summary']['duration'], // seconds
            'geometry' => $this->decodeGeometry($route['geometry'] ?? null),
        ];
    }

    /**
     * Fetch route alternatives from API
     */
    private function fetchAlternatives(array $origin, array $destination, int $count): array
    {
        $url = $this->baseUrl . 'v2/directions/driving-car/json';

        $payload = [
            'coordinates' => [
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
            ],
            'instructions' => false,
            'geometry' => true,
            'alternative_routes' => [
                'target_count' => min($count, 3),
                'weight_factor' => 1.4,
                'share_factor' => 0.6,
            ],
        ];

        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Authorization' => $this->apiKey])
            ->timeout(30)
            ->post($url, $payload);

        if (!$response->successful() || empty($response->json()['routes'])) {
            throw new \Exception('No routes found');
        }

        $routes = [];

        foreach ($response->json()['routes'] as $index => $route) {
            if (!isset($route['summary']['distance'], $route['summary']['duration'])) {
                continue;
            }

            $routes[] = [
                'distance' => (float) $route['summary']['distance'],
                'duration' => (float) $route['summary']['duration'],
                'geometry' => $this->decodeGeometry($route['geometry'] ?? null),
                'route_index' => $index,
            ];
        }

        return $routes;
    }

    /**
     * Normalise the `geometry` field of an OpenRouteService route.
     *
     * The /v2/directions/{profile}/json endpoint returns geometry as a Google
     * *encoded polyline string*, not GeoJSON. The previous code read
     * $route['geometry']['coordinates'], which is null for a string — so every
     * route came back with an empty geometry and the app had no line to draw.
     *
     * Accepts either shape and always returns [[lng, lat], …], matching the
     * GeoJSON axis order used by calculateFallbackRoute().
     */
    private function decodeGeometry(mixed $geometry): array
    {
        if (is_array($geometry)) {
            // Already GeoJSON-shaped (e.g. if the /geojson endpoint is ever used).
            return $geometry['coordinates'] ?? $geometry;
        }

        if (!is_string($geometry) || $geometry === '') {
            return [];
        }

        return $this->decodePolyline($geometry);
    }

    /**
     * Decode a precision-5 encoded polyline into [[lng, lat], …].
     *
     * ORS emits (lat, lng) deltas like Google's algorithm; we flip each pair on
     * output so callers get GeoJSON order.
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index  = 0;
        $length = strlen($encoded);
        $lat    = 0;
        $lng    = 0;

        while ($index < $length) {
            foreach (['lat', 'lng'] as $axis) {
                $shift  = 0;
                $result = 0;

                do {
                    if ($index >= $length) {
                        return $points; // truncated payload — return what we have
                    }

                    $byte    = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1f) << $shift;
                    $shift  += 5;
                } while ($byte >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);

                if ($axis === 'lat') {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }

            $points[] = [$lng * 1e-5, $lat * 1e-5];
        }

        return $points;
    }

    /**
     * Calculate fallback route using straight-line distance
     */
    private function calculateFallbackRoute(array $origin, array $destination): array
    {
        $distance = $this->calculateHaversineDistance($origin, $destination);

        // Add 30% for realistic driving distance
        $drivingDistance = $distance * 1.3;

        // Assume 50 km/h average speed
        $drivingTime = ($drivingDistance / 1000) * 72; // seconds

        return [
            'distance' => $drivingDistance,
            'duration' => $drivingTime,
            'geometry' => [
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
            ],
            'is_fallback' => true,
        ];
    }

    /**
     * Calculate distance using Haversine formula
     */
    private function calculateHaversineDistance(array $point1, array $point2): float
    {
        $earthRadius = 6371000; // meters

        $lat1 = deg2rad($point1['lat']);
        $lon1 = deg2rad($point1['lng']);
        $lat2 = deg2rad($point2['lat']);
        $lon2 = deg2rad($point2['lng']);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1) * cos($lat2) *
            sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Validate coordinates
     */
    private function validateCoordinates(array $coords, string $label): void
    {
        if (!isset($coords['lat'], $coords['lng'])) {
            throw new \InvalidArgumentException("{$label}: Missing lat or lng");
        }

        if (!is_numeric($coords['lat']) || !is_numeric($coords['lng'])) {
            throw new \InvalidArgumentException("{$label}: Coordinates must be numeric");
        }

        if ($coords['lat'] < -90 || $coords['lat'] > 90) {
            throw new \InvalidArgumentException("{$label}: Invalid latitude");
        }

        if ($coords['lng'] < -180 || $coords['lng'] > 180) {
            throw new \InvalidArgumentException("{$label}: Invalid longitude");
        }
    }
}
