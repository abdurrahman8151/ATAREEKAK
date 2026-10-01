<?php

namespace Tests\Unit\Services\Geocoding;

use App\Services\Geocoding\ArabicPlaceNameService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ArabicPlaceNameServiceTest – Unit tests for ArabicPlaceNameService.
 *
 * WHY EXTENDS Laravel TestCase (not PHPUnit):
 * ArabicPlaceNameService uses the Http facade (Illuminate\Support\Facades\Http).
 * Http::fake() requires the Laravel application to be bootstrapped.
 *
 * STRATEGY:
 * - Http::fake() intercepts ALL outgoing HTTP calls – no real network traffic.
 * - We test each public method and the fallback/null-return branches.
 *
 * METHODS COVERED:
 * - geocodeWithArabicPriority()
 * - reverseGeocodeArabic()
 * - autocompleteWithArabic()
 *
 * NOTE ON PRIVATE METHODS:
 * tryNominatimArabic(), tryMapboxArabic(), and extractBestArabicName() are private.
 * They are exercised indirectly through the three public methods above.
 */
class ArabicPlaceNameServiceTest extends TestCase
{
    private ArabicPlaceNameService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ArabicPlaceNameService;
    }

    // ─── geocodeWithArabicPriority ────────────────────────────────────────────

    public function test_geocode_returns_array_with_lat_lng_label_on_success(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimSearchResponse(), 200),
            'api.mapbox.com/*' => Http::response([], 200), // never reached
        ]);

        $result = $this->service->geocodeWithArabicPriority('دمشق');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lat', $result);
        $this->assertArrayHasKey('lng', $result);
        $this->assertArrayHasKey('label', $result);
    }

    public function test_geocode_returns_arabic_label_when_available(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimSearchResponse(), 200),
        ]);

        $result = $this->service->geocodeWithArabicPriority('دمشق');

        $this->assertNotNull($result);
        $this->assertStringContainsString('دمشق', $result['label']);
    }

    public function test_geocode_falls_back_to_display_name_when_no_arabic_in_namedetails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '33.5',
                    'lon' => '36.3',
                    'display_name' => 'Damascus, Syria',
                    'namedetails' => [],          // no Arabic fields
                    'address' => [],
                ],
            ], 200),
        ]);

        $result = $this->service->geocodeWithArabicPriority('Damascus');

        $this->assertIsArray($result);
        $this->assertEquals('Damascus, Syria', $result['label']);
    }

    public function test_geocode_returns_null_when_nominatim_fails_and_no_mapbox_token(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200), // empty results
        ]);

        // RV-28: the service now reads the token via config() (config/services.php →
        // mapbox.access_token), NOT env(). putenv() would not be seen by config, so unset
        // the config key instead — "no token" → tryMapboxArabic() returns null immediately.
        config(['services.mapbox.access_token' => null]);

        $result = $this->service->geocodeWithArabicPriority('unknownplace_xyz');

        $this->assertNull($result);
    }

    public function test_geocode_returns_null_when_nominatim_throws(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response('', 500),
        ]);

        $result = $this->service->geocodeWithArabicPriority('دمشق');

        // Service should catch the exception and fall through to null
        $this->assertNull($result);
    }

    public function test_geocode_uses_mapbox_when_nominatim_empty_and_token_set(): void
    {
        // Nominatim returns empty → fall through to Mapbox
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
            'api.mapbox.com/*' => Http::response($this->mapboxResponse(), 200),
        ]);

        // RV-28: set the token through config (the service reads config(), not env()).
        config(['services.mapbox.access_token' => 'fake_test_token']);

        try {
            $result = $this->service->geocodeWithArabicPriority('حلب');

            $this->assertIsArray($result);
            $this->assertArrayHasKey('lat', $result);
            $this->assertArrayHasKey('lng', $result);
        } finally {
            config(['services.mapbox.access_token' => null]); // clean up
        }
    }

    public function test_geocode_lat_lng_are_floats(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimSearchResponse(), 200),
        ]);

        $result = $this->service->geocodeWithArabicPriority('دمشق');

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
    }

    // ─── reverseGeocodeArabic ─────────────────────────────────────────────────

    public function test_reverse_geocode_returns_arabic_string_on_success(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimReverseResponse(), 200),
        ]);

        $result = $this->service->reverseGeocodeArabic(33.5138, 36.2765);

        $this->assertIsString($result);
        $this->assertStringContainsString('دمشق', $result);
    }

    public function test_reverse_geocode_returns_null_on_failed_response(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response('error', 500),
        ]);

        $result = $this->service->reverseGeocodeArabic(0.0, 0.0);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_returns_null_when_no_arabic_name_found(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Unknown Place',  // no Arabic
                'namedetails' => [],
                'address' => [],
            ], 200),
        ]);

        $result = $this->service->reverseGeocodeArabic(0.0, 0.0);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_returns_null_on_network_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection refused');
        });

        $result = $this->service->reverseGeocodeArabic(33.5138, 36.2765);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_returns_display_name_when_it_contains_arabic(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'دمشق، سوريا',
                'namedetails' => [],
                'address' => [],
            ], 200),
        ]);

        $result = $this->service->reverseGeocodeArabic(33.5138, 36.2765);

        $this->assertEquals('دمشق، سوريا', $result);
    }

    // ─── autocompleteWithArabic ───────────────────────────────────────────────

    public function test_autocomplete_returns_array_on_success(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimSearchResponse(), 200),
        ]);

        $results = $this->service->autocompleteWithArabic('دمش');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    public function test_autocomplete_each_result_has_label_lat_lng(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimSearchResponse(), 200),
        ]);

        $results = $this->service->autocompleteWithArabic('دمش');

        foreach ($results as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('lat', $item);
            $this->assertArrayHasKey('lng', $item);
        }
    }

    public function test_autocomplete_returns_empty_array_when_nominatim_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response('error', 500),
        ]);

        $results = $this->service->autocompleteWithArabic('دمش');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_autocomplete_returns_empty_array_on_network_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Timeout');
        });

        $results = $this->service->autocompleteWithArabic('دمش');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_autocomplete_returns_empty_array_when_no_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $results = $this->service->autocompleteWithArabic('zzz_no_match');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_autocomplete_falls_back_to_display_name_when_no_arabic(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '33.5',
                    'lon' => '36.3',
                    'display_name' => 'Damascus, Syria',
                    'namedetails' => [],
                    'address' => [],
                ],
            ], 200),
        ]);

        $results = $this->service->autocompleteWithArabic('Dama');

        $this->assertCount(1, $results);
        $this->assertEquals('Damascus, Syria', $results[0]['label']);
    }

    // ─── Stub data ────────────────────────────────────────────────────────────

    /**
     * Simulate a Nominatim /search response with an Arabic name in namedetails.
     */
    private function nominatimSearchResponse(): array
    {
        return [
            [
                'lat' => '33.5138073',
                'lon' => '36.2763577',
                'display_name' => 'دمشق، سوريا',
                'namedetails' => [
                    'name' => 'دمشق',
                    'name:ar' => 'دمشق',
                    'name:en' => 'Damascus',
                ],
                'address' => [
                    'city' => 'دمشق',
                    'country' => 'سوريا',
                ],
            ],
        ];
    }

    /**
     * Simulate a Nominatim /reverse response.
     */
    private function nominatimReverseResponse(): array
    {
        return [
            'display_name' => 'دمشق، سوريا',
            'namedetails' => [
                'name' => 'دمشق',
                'name:ar' => 'دمشق',
            ],
            'address' => [
                'city' => 'دمشق',
                'country' => 'سوريا',
            ],
        ];
    }

    /**
     * Simulate a Mapbox Geocoding API response.
     */
    private function mapboxResponse(): array
    {
        return [
            'features' => [
                [
                    'place_name' => 'Aleppo, Syria',
                    'place_name_ar' => 'حلب، سوريا',
                    'geometry' => [
                        'coordinates' => [37.1612, 36.2020], // [lon, lat]
                    ],
                ],
            ],
        ];
    }
}
