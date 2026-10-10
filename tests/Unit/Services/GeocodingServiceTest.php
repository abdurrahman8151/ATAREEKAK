<?php

namespace Tests\Unit\Services;

use App\Services\Geocoding\GeocodingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingServiceTest extends TestCase
{
    private GeocodingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GeocodingService::class);
    }

    // ─── geocodeAddress() ────────────────────────────────────────────────────────────

    public function test_geocode_returns_array_for_valid_address(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocodeAddress('Abu Rummaneh, Damascus, Syria');

        $this->assertIsArray($result);
    }

    public function test_geocode_returns_latitude_and_longitude_keys(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocodeAddress('Abu Rummaneh, Damascus, Syria');

        $this->assertArrayHasKey('lat', $result);
        $this->assertArrayHasKey('lng', $result);
    }

    public function test_geocode_returns_correct_coordinates(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(lat: 31.9539, lng: 35.9106), 200),
        ]);

        $result = $this->service->geocodeAddress('Abu Rummaneh, Damascus, Syria');

        $this->assertEqualsWithDelta(31.9539, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(35.9106, $result['lng'], 0.0001);
    }

    public function test_geocode_returns_empty_array_for_invalid_address(): void
    {
        // RV-50 owner ruling: a successful lookup with no match returns [] (not an exception).
        Http::fake([
            '*' => Http::response($this->emptyGeocodeResponse(), 200),
        ]);

        $result = $this->service->geocodeAddress('xyzzy_invalid_address_no_results');

        $this->assertSame([], $result);
    }

    public function test_geocode_throws_on_api_error_because_it_is_a_fault(): void
    {
        // A failed lookup is a fault, not a "not found" answer, so it must not become [].
        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 500),
        ]);

        $this->expectException(\Exception::class);

        $this->service->geocodeAddress('Damascus, Syria');
    }

    public function test_geocode_throws_on_connection_timeout_because_it_is_a_fault(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Timed out');
        });

        $this->expectException(ConnectionException::class);

        $this->service->geocodeAddress('Damascus, Syria');
    }

    public function test_geocode_lat_is_numeric(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocodeAddress('Damascus, Syria');

        $this->assertIsNumeric($result['lat']);
    }

    public function test_geocode_lng_is_numeric(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocodeAddress('Damascus, Syria');

        $this->assertIsNumeric($result['lng']);
    }

    // ─── reverseGeocode() ─────────────────────────────────────────────────────

    public function test_reverse_geocode_returns_string_for_valid_coordinates(): void
    {
        Http::fake([
            '*' => Http::response($this->reverseGeocodeResponse('Abu Rummaneh, Damascus, Syria'), 200),
        ]);

        $result = $this->service->reverseGeocode(31.9539, 35.9106);

        $this->assertIsString($result);
    }

    public function test_reverse_geocode_returns_correct_address(): void
    {
        Http::fake([
            '*' => Http::response($this->reverseGeocodeResponse('Rainbow Street, Amman, Jordan'), 200),
        ]);

        $result = $this->service->reverseGeocode(31.9454, 35.9234);

        $this->assertStringContainsString('Amman', $result);
    }

    public function test_reverse_geocode_returns_null_when_no_results(): void
    {
        Http::fake([
            '*' => Http::response($this->emptyGeocodeResponse(), 200),
        ]);

        $result = $this->service->reverseGeocode(0.0, 0.0);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_returns_null_on_api_error(): void
    {
        Http::fake([
            '*' => Http::response([], 503),
        ]);

        $result = $this->service->reverseGeocode(31.9539, 35.9106);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_returns_null_on_connection_failure(): void
    {
        Http::fake([
            '*' => Http::throw(new ConnectionException('Network error')),
        ]);

        $result = $this->service->reverseGeocode(31.9539, 35.9106);

        $this->assertNull($result);
    }

    public function test_reverse_geocode_result_is_not_empty_string(): void
    {
        Http::fake([
            '*' => Http::response($this->reverseGeocodeResponse('University Street, Amman'), 200),
        ]);

        $result = $this->service->reverseGeocode(31.9539, 35.9106);

        $this->assertNotEmpty($result);
    }

    // ─── HTTP call verification ────────────────────────────────────────────────

    public function test_geocode_makes_exactly_one_http_request(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $this->service->geocodeAddress('Damascus, Syria');

        Http::assertSentCount(1);
    }

    public function test_reverse_geocode_makes_exactly_one_http_request(): void
    {
        Http::fake([
            '*' => Http::response($this->reverseGeocodeResponse('Amman'), 200),
        ]);

        $this->service->reverseGeocode(31.9539, 35.9106);

        Http::assertSentCount(1);
    }

    public function test_geocode_sends_address_in_request(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $this->service->geocodeAddress('Aleppo, Syria');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'Aleppo') ||
            str_contains(json_encode($request->data()), 'Aleppo')
        );
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    /** Nominatim search shape: a list of results, each with string lat/lon and display_name. */
    private function geocodeResponse(float $lat = 33.5138, float $lng = 36.2765): array
    {
        return [
            [
                'lat' => (string) $lat,
                'lon' => (string) $lng,
                'display_name' => 'Abu Rummaneh, Damascus, Syria',
            ],
        ];
    }

    private function reverseGeocodeResponse(string $address): array
    {
        return [
            'results' => [
                [
                    'formatted_address' => $address,
                    'geometry' => [
                        'location' => ['lat' => 31.9539, 'lng' => 35.9106],
                    ],
                ],
            ],
            'status' => 'OK',
        ];
    }

    /** Nominatim returns an empty list when nothing matches. */
    private function emptyGeocodeResponse(): array
    {
        return [];
    }
}
