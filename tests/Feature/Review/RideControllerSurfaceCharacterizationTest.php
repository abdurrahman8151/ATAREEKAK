<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Ride\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Characterization of two RideController failure surfaces BEFORE the controller is split.
 *
 * Why this file exists: the existing RideController tests pin status codes but almost never the
 * user-facing message, and some accept either of two codes. A split that moved a branch could
 * change the sentence or the status a client sees and most tests would still pass. These pin the
 * real contract for the two surfaces the split touches:
 *
 *   1. POST /api/rides/route-options with the upstream route service failing -> 200 with one
 *      straight-line fallback route. RouteCalculationService::getRouteAlternatives catches the
 *      upstream error itself, so a valid request never reaches the controller's 500 branch.
 *   2. POST /api/rides/{id}/book refused by a domain gate (unverified passenger) -> 422 and the
 *      curated domain sentence, NOT the generic "could not be completed" text.
 *
 * No mocks of final classes: the upstream failure is driven through Http::fake, the same seam
 * used by RV50UnresolvedAddressTest.
 */
class RideControllerSurfaceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_FAILURE = 'The request could not be completed. Please try again.';

    private function tokenFor(User $user): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);

        return $r->json('tokens.access_token');
    }

    public function test_route_options_upstream_failure_falls_back_to_a_200_straight_line_route(): void
    {
        $user = User::factory()->create([
            'is_verified_passenger' => true,
            'password' => bcrypt('password123'),
        ]);

        Http::fake(['*' => Http::response(['error' => 'upstream down'], 500)]);

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/rides/route-options', [
                'pickup_lat' => 6.9271,
                'pickup_lng' => 79.8612,
                'destination_lat' => 7.2906,
                'destination_lng' => 80.6337,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.pickup.lat', 6.9271);
        $response->assertJsonPath('data.destination.lng', 80.6337);
        $this->assertCount(1, $response->json('data.routes'));
    }

    public function test_unverified_passenger_booking_refusal_is_422_with_the_domain_sentence(): void
    {
        $owner = User::factory()->create(['is_verified_driver' => true, 'password' => bcrypt('password123')]);
        $ride = Ride::factory()->create(['driver_id' => $owner->id]);

        $unverified = User::factory()->create([
            'is_verified_passenger' => false,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->withToken($this->tokenFor($unverified))
            ->postJson("/api/rides/{$ride->id}/book", [
                'seats' => 1,
                'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        // The message must be the curated domain sentence, never the generic fault text.
        $this->assertNotSame(self::GENERIC_FAILURE, $response->json('message'));
        $this->assertNotEmpty($response->json('message'));
    }
}
