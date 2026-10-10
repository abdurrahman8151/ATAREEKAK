<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocoding Service
 *
 * EXTRACTED from OpenRouteService god class
 *
 * Single Responsibility: Convert addresses ↔ coordinates
 *
 * Before: 500+ line OpenRouteService doing everything
 * After: 150 lines focused on geocoding only
 */
final class GeocodingService
{
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ArabicPlaceNameService $arabicService
    ) {}

    /**
     * Convert address to coordinates with Arabic support
     */
    public function geocodeAddress(string $address): array
    {
        $cacheKey = 'geocode:v2:'.md5($address);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($address) {
            // Try Arabic-optimized geocoding first
            $result = $this->arabicService->geocodeWithArabicPriority($address);

            if ($result) {
                return $result;
            }

            // Fallback to English
            return $this->geocodeEnglish($address);
        });
    }

    /**
     * Convert coordinates to address (reverse geocoding)
     */
    public function reverseGeocode(float $lat, float $lng): string
    {
        $cacheKey = 'reverse:v2:'.md5("{$lat},{$lng}");

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($lat, $lng) {
            // Try Arabic first
            $arabicResult = $this->arabicService->reverseGeocodeArabic($lat, $lng);

            if ($arabicResult) {
                return $arabicResult;
            }

            // Fallback to English
            return $this->reverseGeocodeEnglish($lat, $lng);
        });
    }

    /**
     * Get autocomplete suggestions
     */
    public function autocomplete(string $partial): array
    {
        $cacheKey = 'autocomplete:v2:'.md5($partial);

        return Cache::remember($cacheKey, self::CACHE_TTL / 2, function () use ($partial) {
            return $this->arabicService->autocompleteWithArabic($partial);
        });
    }

    /**
     * English geocoding (fallback)
     */
    private function geocodeEnglish(string $address): array
    {
        $url = 'https://nominatim.openstreetmap.org/search';

        $params = [
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'accept-language' => 'en',
            'countrycodes' => 'sy',
        ];

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SyRide-App/1.0',
            ])
                ->timeout(10)
                ->get($url, $params);

            if (! $response->successful()) {
                // A failed lookup is a fault, not a "not found" answer: keep it an exception.
                throw new \Exception("Geocoding API error (HTTP {$response->status()}) for: {$address}");
            }

            if (empty($response->json())) {
                // RV-50 (owner ruling): a successful lookup with no match returns [] so callers can
                // refuse the address explicitly instead of catching an exception.
                return [];
            }

            $data = $response->json()[0];

            return [
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lon'],
                'label' => $data['display_name'],
            ];
        } catch (\Exception $e) {
            Log::error('Geocoding failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * English reverse geocoding (fallback)
     */
    private function reverseGeocodeEnglish(float $lat, float $lng): string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse';

        $params = [
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'accept-language' => 'en',
        ];

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SyRide-App/1.0',
            ])
                ->timeout(10)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                return $data['display_name'] ?? "Location: {$lat}, {$lng}";
            }

            return "Location: {$lat}, {$lng}";
        } catch (\Exception $e) {
            Log::warning('Reverse geocoding failed', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);

            return "الموقع: {$lat}, {$lng}";
        }
    }
    /**
     * Get multiple route alternatives between two points
     * Delegates to OpenRouteService which handles ORS API + waypoint fallback
     */
}
