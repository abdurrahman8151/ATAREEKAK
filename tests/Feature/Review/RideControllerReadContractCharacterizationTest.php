<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Ride\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of the read contracts of RideController before the controller is split.
 *
 * The existing RideController tests pin status codes but not the response envelope for the
 * list endpoints. A split could change `success`, the `data` key, or the status and the tests
 * would still pass. These pin the real envelope for the two list actions that the split moves:
 *
 *   - GET /api/rides        (getRides, driver's own rides)
 *
 * The 500 branch of getRides ("Failed to fetch rides.") is unreachable from a valid request:
 * RideRepository::getDriverRides is a plain query, so it cannot be driven without an artificial
 * seam. It is therefore not faked here; the success envelope is what clients depend on.
 */
class RideControllerReadContractCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);

        return $r->json('tokens.access_token');
    }

    public function test_driver_rides_list_returns_the_success_envelope_with_the_drivers_rides(): void
    {
        $driver = User::factory()->create([
            'is_verified_driver' => true,
            'password' => bcrypt('password123'),
        ]);
        $other = User::factory()->create(['is_verified_driver' => true]);

        Ride::factory()->create(['driver_id' => $driver->id]);
        Ride::factory()->create(['driver_id' => $other->id]);

        $response = $this->withToken($this->tokenFor($driver))->getJson('/api/rides');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertArrayHasKey('data', $response->json());
        // Only the caller's own ride is returned, never another driver's.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_driver_rides_list_requires_authentication(): void
    {
        $this->getJson('/api/rides')->assertStatus(401);
    }
}
