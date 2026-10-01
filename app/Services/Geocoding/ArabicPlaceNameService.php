<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Arabic Place Name Service
 *
 * EXTRACTED from OpenRouteService
 *
 * Single Responsibility: Handle Arabic place names
 *
 * Why separate?
 * - Arabic handling is complex (Nominatim, Mapbox, name extraction)
 * - Different from core geocoding logic
 * - Can be reused independently
 */
final class ArabicPlaceNameService
{
    /**
     * Geocode with Arabic name priority
     */
    public function geocodeWithArabicPriority(string $address): ?array
    {
        // Try Nominatim with Arabic
        $result = $this->tryNominatimArabic($address);
        if ($result) {
            return $result;
        }

        // Try Mapbox (if configured)
        $result = $this->tryMapboxArabic($address);
        if ($result) {
            return $result;
        }

        return null;
    }

    /**
     * Reverse geocode with Arabic result
     */
    public function reverseGeocodeArabic(float $lat, float $lng): ?string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse';

        $params = [
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'accept-language' => 'ar,en',
            'addressdetails' => 1,
            'namedetails' => 1,
        ];

        try {
            // RV-22: TLS peer verification is ON (was ->withoutVerifying()). Verified
            // HTTPS to nominatim.openstreetmap.org works from the deployment box (a CA
            // bundle is configured), so this WAMP-dev workaround has no reason to ride
            // into production, where a MITM on geocoding can forge addresses.
            $response = Http::withHeaders([
                'User-Agent' => 'SyRide-App/1.0',
                'Accept-Language' => 'ar,en;q=0.8',
            ])
                ->timeout(10)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // Extract best Arabic name
                $arabicName = $this->extractBestArabicName($data);

                if ($arabicName) {
                    return $arabicName;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Arabic reverse geocoding failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Autocomplete with Arabic results
     */
    public function autocompleteWithArabic(string $partial): array
    {
        $url = 'https://nominatim.openstreetmap.org/search';

        $params = [
            'q' => $partial,
            'format' => 'json',
            'limit' => 6,
            'addressdetails' => 1,
            'accept-language' => 'ar,en',
            'countrycodes' => 'sy',
            'namedetails' => 1,
        ];

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SyRide-App/1.0',
                'Accept-Language' => 'ar,en;q=0.8',
            ])
                ->timeout(10)
                ->get($url, $params);

            if ($response->successful()) {
                return collect($response->json())
                    ->map(function ($result) {
                        $arabicName = $this->extractBestArabicName($result);

                        return [
                            'label' => $arabicName ?: $result['display_name'],
                            'lat' => $result['lat'],
                            'lng' => $result['lon'],
                        ];
                    })
                    ->all();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('Arabic autocomplete failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Try Nominatim with Arabic
     */
    private function tryNominatimArabic(string $address): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search';

        $params = [
            'q' => $address,
            'format' => 'json',
            'limit' => 3,
            'addressdetails' => 1,
            'accept-language' => 'ar,en',
            'countrycodes' => 'sy',
            'namedetails' => 1,
        ];

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SyRide-App/1.0',
                'Accept-Language' => 'ar,en;q=0.8',
            ])
                ->timeout(10)
                ->get($url, $params);

            if (! $response->successful() || empty($response->json())) {
                return null;
            }

            $data = $response->json();

            // Find best result with Arabic name
            foreach ($data as $result) {
                $arabicName = $this->extractBestArabicName($result);

                if ($arabicName) {
                    return [
                        'lat' => (float) $result['lat'],
                        'lng' => (float) $result['lon'],
                        'label' => $arabicName,
                    ];
                }
            }

            // No Arabic found, return first result
            return [
                'lat' => (float) $data[0]['lat'],
                'lng' => (float) $data[0]['lon'],
                'label' => $data[0]['display_name'],
            ];
        } catch (\Exception $e) {
            Log::debug('Nominatim Arabic failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Try Mapbox with Arabic
     */
    private function tryMapboxArabic(string $address): ?array
    {
        // RV-28: read via config (not env()) so `php artisan config:cache` cannot silently
        // blank the token in production. Owned at config/services.php → mapbox.access_token.
        $token = config('services.mapbox.access_token');

        if (! $token) {
            return null;
        }

        $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/'.urlencode($address).'.json';

        $params = [
            'access_token' => $token,
            'country' => 'sy',
            'language' => 'ar',
            'limit' => 1,
        ];

        try {
            $response = Http::timeout(10)->get($url, $params);

            if ($response->successful() && ! empty($response->json()['features'])) {
                $feature = $response->json()['features'][0];

                return [
                    'lat' => (float) $feature['geometry']['coordinates'][1],
                    'lng' => (float) $feature['geometry']['coordinates'][0],
                    'label' => $feature['place_name_ar'] ?? $feature['place_name'],
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Mapbox failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extract best Arabic name from Nominatim result
     */
    private function extractBestArabicName(array $result): ?string
    {
        // Check namedetails for Arabic
        if (isset($result['namedetails'])) {
            $arabicFields = ['name:ar', 'alt_name:ar', 'official_name:ar', 'name'];

            foreach ($arabicFields as $field) {
                if (isset($result['namedetails'][$field]) && $this->containsArabic($result['namedetails'][$field])) {
                    return $result['namedetails'][$field];
                }
            }
        }

        // Check display_name
        if (isset($result['display_name']) && $this->containsArabic($result['display_name'])) {
            return $result['display_name'];
        }

        // Build from address components
        if (isset($result['address'])) {
            return $this->buildArabicAddress($result['address']);
        }

        return null;
    }

    /**
     * Check if text contains Arabic
     */
    private function containsArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /**
     * Build Arabic address from components
     */
    private function buildArabicAddress(array $address): ?string
    {
        $arabicParts = [];
        $components = ['road', 'neighbourhood', 'suburb', 'city', 'county', 'state'];

        foreach ($components as $component) {
            if (isset($address[$component])) {
                $value = $address[$component];

                if ($this->containsArabic($value) || empty($arabicParts)) {
                    $arabicParts[] = $value;
                }
            }
        }

        return ! empty($arabicParts) ? implode(', ', array_unique($arabicParts)) : null;
    }
}
