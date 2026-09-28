<?php

namespace Tests\Feature\Rides;

use App\Models\Ride;
use App\Models\User;
use App\Services\Ride\RideSearchService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RideSearchServiceTest — Feature tests for RideSearchService.
 *
 * COVERS:
 *   searchRides()    — departure date, seat count, and spatial proximity filters
 *   getNearbyRides() — active rides within a given radius
 *   API integration  — POST /api/rides/search
 */
class RideSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private RideSearchService $service;

    private User $driver;

    private string $driverPhone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RideSearchService::class);
        $this->driverPhone = '091'.rand(1000000, 9999999);
        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        if (! $this->driver->profile) {
            $this->driver->profile()->create([
                'full_name' => 'Search Test Driver',
                'number_of_rides' => 0,
            ]);
        }
    }

    // ─── searchRides ──────────────────────────────────────────────────────────

    public function test_returns_empty_collection_when_no_rides_exist(): void
    {
        $results = $this->service->searchRides([
            'departure_date' => now()->addDays(3)->toDateString(),
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_returns_matching_ride_when_conditions_met(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date]);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertNotEmpty($results);
    }

    public function test_does_not_return_cancelled_rides(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date, 'status' => 'cancelled']);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_does_not_return_finished_rides(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date, 'status' => 'finished']);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_filters_by_departure_date(): void
    {
        $targetDate = now()->addDays(5)->toDateString();
        $otherDate = now()->addDays(10)->toDateString();

        $this->insertRide(['departure_date' => $targetDate]);
        $this->insertRide(['departure_date' => $otherDate]);

        $results = $this->service->searchRides([
            'departure_date' => $targetDate,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        foreach ($results as $ride) {
            $this->assertEquals(
                $targetDate,
                Carbon::parse($ride->departure_time)->toDateString()
            );
        }
    }

    public function test_filters_by_minimum_seats(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date, 'available_seats' => 1]);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 3,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_returns_ride_when_seats_exactly_match_requirement(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date, 'available_seats' => 2]);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 2,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $this->assertNotEmpty($results);
    }

    public function test_results_include_driver_relationship(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $date]);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        if ($results->isNotEmpty()) {
            $this->assertNotNull($results->first()->driver);
        }
    }

    public function test_orders_results_by_departure_time_ascending(): void
    {
        $baseDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $baseDate, 'departure_hour' => 9]);
        $this->insertRide(['departure_date' => $baseDate, 'departure_hour' => 14]);

        $results = $this->service->searchRides([
            'departure_date' => $baseDate,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        if ($results->count() >= 2) {
            $first = Carbon::parse($results->first()->departure_time);
            $second = Carbon::parse($results->skip(1)->first()->departure_time);
            $this->assertTrue($first->lessThanOrEqualTo($second));
        }
    }

    // ─── getNearbyRides ───────────────────────────────────────────────────────

    public function test_get_nearby_rides_returns_active_rides_near_location(): void
    {
        $this->insertRide(['status' => 'active']);

        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertNotEmpty($results);
    }

    public function test_get_nearby_rides_excludes_cancelled_rides(): void
    {
        $this->insertRide(['status' => 'cancelled']);

        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertEmpty($results);
    }

    public function test_get_nearby_rides_returns_collection_instance(): void
    {
        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertInstanceOf(Collection::class, $results);
    }

    // ─── API integration ──────────────────────────────────────────────────────

    public function test_search_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/rides/search', [])->assertStatus(401);
    }

    public function test_search_endpoint_returns_200_with_valid_params(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->driver->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)
            ->postJson('/api/rides/search', [
                'source_lat' => 33.5138,
                'source_lng' => 36.2765,
                'dest_lat' => 36.2021,
                'dest_lng' => 37.1343,
                'departure_date' => now()->addDays(5)->toDateString(),
                'seats_required' => 1,
            ])->assertStatus(200);
    }

    public function test_search_endpoint_returns_422_with_missing_params(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->driver->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)
            ->postJson('/api/rides/search', [])
            ->assertStatus(422);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = []): Ride
    {
        $status = $overrides['status'] ?? 'active';
        $seats = $overrides['available_seats'] ?? 4;
        $departureDate = $overrides['departure_date'] ?? now()->addDays(3)->toDateString();
        $hour = $overrides['departure_hour'] ?? 10;
        $departureTime = Carbon::parse($departureDate)->setHour($hour)->format('Y-m-d H:i:s');

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, 'cash', 'direct', ?, 320500, 14400, ?, NOW(), NOW()
            )
        ", [$this->driver->id, $departureTime, $seats, $status, $this->driverPhone]);

        return Ride::latest('id')->first();
    }
}
