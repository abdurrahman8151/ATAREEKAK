<?php

namespace Tests\Feature\Review;

use App\Models\Ride;
use App\Repositories\RideRepository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RV-50 (owner ruling option 1): an address that does not resolve must be refused, never
 * searched or stored from a (0,0) coordinate. A miss returns [] from geocodeAddress(), and the
 * repository turns that into a curated refusal before any ride row is written.
 *
 * GeocodingService and RouteCalculationService are final, so Mockery cannot replace them. The
 * real services run here, with the HTTP layer faked, which exercises the actual miss path.
 */
class RV50UnresolvedAddressTest extends TestCase
{
    public function test_unresolved_pickup_address_is_refused_and_writes_no_ride(): void
    {
        // Nominatim returns [] for an address it cannot place.
        Http::fake(['*' => Http::response([], 200)]);

        $repo = app(RideRepository::class);
        $before = Ride::count();

        try {
            $repo->createRide([
                'pickup_address' => 'xyzzy_nowhere',
                'destination_address' => 'Aleppo, Syria',
            ]);
            $this->fail('An unresolved pickup address must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pickup address', $e->getMessage());
            $this->assertStringContainsString('xyzzy_nowhere', $e->getMessage());
        }

        $this->assertSame($before, Ride::count(), 'No ride row may be written for an unresolved address.');
    }

    public function test_unresolved_destination_address_is_refused(): void
    {
        // Pickup resolves (Nominatim hit); destination does not (empty list).
        Http::fake(function ($request) {
            $q = $request->data()['q'] ?? '';

            return str_contains($q, 'xyzzy')
                ? Http::response([], 200)
                : Http::response([[
                    'lat' => '33.5138', 'lon' => '36.2765', 'display_name' => 'Damascus, Syria',
                ]], 200);
        });

        $repo = app(RideRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('destination address');

        $repo->createRide([
            'pickup_address' => 'Damascus, Syria',
            'destination_address' => 'xyzzy_nowhere',
        ]);
    }
}
