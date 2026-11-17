<?php

namespace Tests\Feature\Review;

use App\Modules\Ride\Models\Ride;
use App\Modules\Ride\Repositories\RideRepository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L25 (RideRepository::createRide, characterization): pins the EXACT refusal and missing-data messages
 * for the pickup and destination endpoints. RV-50 pins only fragments of the address refusal, so a
 * change to the sentence passed those tests. This file pins the full text on the unchanged code.
 */
class L25RideRepositoryRefusalWordingTest extends TestCase
{
    public function test_unresolved_pickup_address_message_is_exact(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find a location for the pickup address: xyzzy_nowhere');

        app(RideRepository::class)->createRide([
            'pickup_address' => 'xyzzy_nowhere',
            'destination_address' => 'Aleppo, Syria',
        ]);
    }

    public function test_unresolved_destination_address_message_is_exact(): void
    {
        Http::fake(function ($request) {
            $q = $request->data()['q'] ?? '';

            return str_contains($q, 'xyzzy')
                ? Http::response([], 200)
                : Http::response([[
                    'lat' => '33.5138', 'lon' => '36.2765', 'display_name' => 'Damascus, Syria',
                ]], 200);
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find a location for the destination address: xyzzy_nowhere');

        app(RideRepository::class)->createRide([
            'pickup_address' => 'Damascus, Syria',
            'destination_address' => 'xyzzy_nowhere',
        ]);
    }

    public function test_missing_pickup_message_is_exact_and_writes_no_ride(): void
    {
        $before = Ride::count();

        try {
            app(RideRepository::class)->createRide(['destination_address' => 'Aleppo, Syria']);
            $this->fail('A ride with no pickup must be refused.');
        } catch (\Exception $e) {
            $this->assertSame(
                "Missing pickup data: must provide 'pickup_location' or 'pickup_address'.",
                $e->getMessage()
            );
        }

        $this->assertSame($before, Ride::count());
    }

    public function test_missing_destination_message_is_exact(): void
    {
        // The pickup resolves first, so it needs a fake; the destination check then runs.
        Http::fake(['*' => Http::response([[
            'lat' => '33.5138', 'lon' => '36.2765', 'display_name' => 'Damascus, Syria',
        ]], 200)]);

        try {
            app(RideRepository::class)->createRide(['pickup_address' => 'Damascus, Syria']);
            $this->fail('A ride with no destination must be refused.');
        } catch (\Exception $e) {
            $this->assertSame(
                "Missing destination data: must provide 'destination_location' or 'destination_address'.",
                $e->getMessage()
            );
        }
    }
}
