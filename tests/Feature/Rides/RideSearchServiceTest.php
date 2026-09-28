<?php

namespace Tests\Feature\Rides;

use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
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

        // AF-4: this test used to assert nothing when the (brokenly stored)
        // fixtures made $results empty — a risky/vacuous pass. It now proves the
        // date filter kept only the target day, on the fixed fixture.
        $this->assertNotEmpty($results, 'the target-date ride must be found');
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

        // AF-4: was guarded by `if ($results->isNotEmpty())` — silently vacuous
        // while the fixture bug kept results empty. Assert it properly now.
        $this->assertNotEmpty($results);
        $this->assertNotNull($results->first()->driver);
        $this->assertTrue($results->first()->relationLoaded('driver'));
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

        // AF-4: the `count() >= 2` guard made this assertion vacuous on broken
        // fixtures. It now asserts the ordering directly.
        $this->assertCount(2, $results, 'both rides must match the search');
        $times = $results->map(fn ($r) => Carbon::parse($r->departure_time)->timestamp)->all();
        $this->assertSame(
            $times,
            $this->callMethodSort($times),
            'results must be ordered by departure_time ascending'
        );
    }

    /** @param int[] $ts */
    private function callMethodSort(array $ts): array
    {
        sort($ts);

        return $ts;
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

    // AF-4: the live search path serializes raw models through this service,
    // and the presenter (RideResource) used to render driver.rating from a
    // nonexistent `driver_rating` column — so EVERY driver was shown as rating
    // 0 regardless of user_ratings. The resource now averages the batch-loaded
    // receivedRatings. This asserts the presenter output directly (the endpoint
    // itself returns raw models and never runs the resource).
    public function test_search_results_render_the_real_driver_rating_not_zero(): void
    {
        $date = now()->addDays(3)->toDateString();
        $ride = $this->insertRide(['departure_date' => $date]);

        // Deterministic fixture: whatever else seeded this driver's ratings,
        // the assertion below is over EXACTLY these two rows.
        UserRating::where('rated_user_id', $this->driver->id)->delete();
        UserRating::create(['rater_id' => User::factory()->create()->id, 'rated_user_id' => $this->driver->id, 'rating' => 5]);
        UserRating::create(['rater_id' => User::factory()->create()->id, 'rated_user_id' => $this->driver->id, 'rating' => 3]);

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);

        $row = (new RideResource($ride->fresh(['driver', 'driver.receivedRatings'])))->toArray(request());

        $this->assertSame(
            4.0,
            (float) $row['driver']['rating'],
            'driver rating must be the real average (5+3)/2 = 4, not the old constant 0'
        );
    }

    // AF-4: eager-loading proof — the list query must batch-load ratings, not
    // issue one user_ratings query per result row (N+1).
    public function test_search_does_not_query_ratings_per_ride(): void
    {
        $date = now()->addDays(3)->toDateString();
        for ($i = 0; $i < 4; $i++) {
            $rider = User::factory()->create(['is_verified_driver' => true]);
            $this->insertRide(['departure_date' => $date], $rider);
            UserRating::create([
                'rater_id' => $this->driver->id,
                'rated_user_id' => $rider->id,
                'rating' => 4,
            ]);
        }

        $ratingQueries = 0;
        DB::listen(function ($q) use (&$ratingQueries): void {
            if (str_contains($q->sql, 'from `user_ratings`')) {
                $ratingQueries++;
            }
        });

        $results = $this->service->searchRides([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]);
        // Render every row, which is where a lazy accessor would fire.
        RideResource::collection($results)->toArray(request());

        $this->assertCount(4, $results);
        $this->assertSame(
            1,
            $ratingQueries,
            'ratings must arrive in ONE batched eager load, not once per ride'
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = [], ?User $driver = null): Ride
    {
        $status = $overrides['status'] ?? 'active';
        $seats = $overrides['available_seats'] ?? 4;
        $departureDate = $overrides['departure_date'] ?? now()->addDays(3)->toDateString();
        $hour = $overrides['departure_hour'] ?? 10;
        $departureTime = Carbon::parse($departureDate)->setHour($hour)->format('Y-m-d H:i:s');
        $driver = $driver ?? $this->driver;

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(36.2765 33.5138)', 4326),
                ST_GeomFromText('POINT(37.1343 36.2021)', 4326),
                ?, ?, 50000, 'cash', 'direct', ?, 320500, 14400, ?, NOW(), NOW()
            )
        ", [$driver->id, $departureTime, $seats, $status, '09'.rand(1000000, 9999999)]);

        return Ride::latest('id')->first();
    }
}
