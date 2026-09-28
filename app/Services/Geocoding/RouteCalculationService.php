<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function __construct()
    {
        $this->apiKey = config('services.openroute.api_key');

        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException(
                'OpenRouteService API key is required. Please set OPENROUTE_API_KEY in your .env file.'
            );
        }
    }

    /**
     * Get route details between two points
     */
    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        $cacheKey = 'route:v2:'.md5(json_encode([$origin, $destination]));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination) {
            try {
                return $this->fetchRoute($origin, $destination);
            } catch (\Exception $e) {
                Log::warning('Route calculation failed, using fallback', [
                    'error' => $e->getMessage(),
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

        $cacheKey = 'routes:v2:'.md5(json_encode([$origin, $destination, $maxAlternatives]));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $maxAlternatives) {
            try {
                return $this->fetchAlternatives($origin, $destination, $maxAlternatives);
            } catch (\Exception $e) {
                Log::warning('Alternative routes failed, using fallback', [
                    'error' => $e->getMessage(),
                ]);

                return [$this->calculateFallbackRoute($origin, $destination)];
            }
        });
    }

    /**
     * Fetch route from OpenRouteService API
     */
    private function fetchRoute(array $origin, array $destination): array
    {
        $url = $this->baseUrl.'v2/directions/driving-car/json';

        $payload = [
            'coordinates' => [
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
            ],
            'instructions' => false,
            'geometry' => true,
        ];

        // AF-1: verified TLS (see the comment at getRouteInfo() for the story).
        $response = Http::withHeaders(['Authorization' => $this->apiKey])
            ->timeout(30)
            ->post($url, $payload);

        if (! $response->successful()) {
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
            'geometry' => $route['geometry']['coordinates'] ?? [],
        ];
    }

    /**
     * Fetch route alternatives from API
     */
    private function fetchAlternatives(array $origin, array $destination, int $count): array
    {
        $url = $this->baseUrl.'v2/directions/driving-car/json';

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

        // AF-1 (app-future audit): `withOptions(['verify' => false])` was
        // disabled TLS peer verification on every provider call. It existed only
        // to work around a missing CA bundle on one WAMP dev box — but it shipped
        // to the 5 production replicas too, where every outbound route request
        // was MITM-able (this response drives ride DISTANCE, and distance drives
        // fare). Verified TLS was proven working against
        // api.openrouteservice.org from this very machine (HTTP 200 with
        // VERIFYPEER+VERIFYHOST enabled, CA from php.ini curl.cainfo), so the
        // workaround had outlived its excuse.
        $response = Http::withHeaders(['Authorization' => $this->apiKey])
            ->timeout(30)
            ->post($url, $payload);

        if (! $response->successful() || empty($response->json()['routes'])) {
            throw new \Exception('No routes found');
        }

        $routes = [];

        foreach ($response->json()['routes'] as $index => $route) {
            if (! isset($route['summary']['distance'], $route['summary']['duration'])) {
                continue;
            }

            $routes[] = [
                'distance' => (float) $route['summary']['distance'],
                'duration' => (float) $route['summary']['duration'],
                'geometry' => $route['geometry']['coordinates'] ?? [],
                'route_index' => $index,
            ];
        }

        return $routes;
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
        if (! isset($coords['lat'], $coords['lng'])) {
            throw new \InvalidArgumentException("{$label}: Missing lat or lng");
        }

        if (! is_numeric($coords['lat']) || ! is_numeric($coords['lng'])) {
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
