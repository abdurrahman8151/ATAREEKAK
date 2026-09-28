<?php

namespace Tests\Unit\Services;

use App\Services\Geocoding\GeocodingService;
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

    // ─── geocode() ────────────────────────────────────────────────────────────

    public function test_geocode_returns_array_for_valid_address(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocode('Abdali Boulevard, Amman, Jordan');

        $this->assertIsArray($result);
    }

    public function test_geocode_returns_latitude_and_longitude_keys(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocode('Abdali Boulevard, Amman, Jordan');

        $this->assertArrayHasKey('lat', $result);
        $this->assertArrayHasKey('lng', $result);
    }

    public function test_geocode_returns_correct_coordinates(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(lat: 31.9539, lng: 35.9106), 200),
        ]);

        $result = $this->service->geocode('Abdali Boulevard, Amman, Jordan');

        $this->assertEqualsWithDelta(31.9539, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(35.9106, $result['lng'], 0.0001);
    }

    public function test_geocode_returns_null_for_invalid_address(): void
    {
        Http::fake([
            '*' => Http::response($this->emptyGeocodeResponse(), 200),
        ]);

        $result = $this->service->geocode('xyzzy_invalid_address_no_results');

        $this->assertNull($result);
    }

    public function test_geocode_returns_null_on_api_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 500),
        ]);

        $result = $this->service->geocode('Amman, Jordan');

        $this->assertNull($result);
    }

    public function test_geocode_returns_null_on_connection_timeout(): void
    {
        Http::fake([
            '*' => Http::throw(new \Illuminate\Http\Client\ConnectionException('Timed out')),
        ]);

        $result = $this->service->geocode('Amman, Jordan');

        $this->assertNull($result);
    }

    public function test_geocode_lat_is_numeric(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocode('Amman, Jordan');

        $this->assertIsNumeric($result['lat']);
    }

    public function test_geocode_lng_is_numeric(): void
    {
        Http::fake([
            '*' => Http::response($this->geocodeResponse(), 200),
        ]);

        $result = $this->service->geocode('Amman, Jordan');

        $this->assertIsNumeric($result['lng']);
    }

    // ─── reverseGeocode() ─────────────────────────────────────────────────────

    public function test_reverse_geocode_returns_string_for_valid_coordinates(): void
    {
        Http::fake([
            '*' => Http::response($this->reverseGeocodeResponse('Abdali Boulevard, Amman, Jordan'), 200),
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
            '*' => Http::throw(new \Illuminate\Http\Client\ConnectionException('Network error')),
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

        $this->service->geocode('Amman, Jordan');

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

        $this->service->geocode('Sweifieh, Amman');

        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'Sweifieh') ||
            str_contains(json_encode($request->data()), 'Sweifieh')
        );
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private function geocodeResponse(float $lat = 31.9539, float $lng = 35.9106): array
    {
        return [
            'results' => [
                [
                    'geometry' => [
                        'location' => ['lat' => $lat, 'lng' => $lng],
                    ],
                    'formatted_address' => 'Abdali Boulevard, Amman, Jordan',
                ],
            ],
            'status' => 'OK',
        ];
    }

    private function reverseGeocodeResponse(string $address): array
    {
        return [
            'results' => [
                [
                    'formatted_address' => $address,
                    'geometry'          => [
                        'location' => ['lat' => 31.9539, 'lng' => 35.9106],
                    ],
                ],
            ],
            'status' => 'OK',
        ];
    }

    private function emptyGeocodeResponse(): array
    {
        return [
            'results' => [],
            'status'  => 'ZERO_RESULTS',
        ];
    }
}
