<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Ride\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of RideController::cancelRide before the controller is split.
 *
 * cancelRide uses its OWN envelope - `status` ('success' | 'error') - not the `success` boolean
 * the other actions use. It also returns 422 for a domain refusal and 500 for a fault. The
 * existing tests pin the 'error' key once and never pin the success sentence, so a change to
 * "Ride cancelled successfully." would pass every test. This file pins the success envelope,
 * the exact sentence, and the envelope key, so the split cannot normalise them away.
 */
class RideControllerCancelContractCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);

        return $r->json('tokens.access_token');
    }

    public function test_driver_cancelling_their_ride_gets_the_status_envelope_and_the_exact_sentence(): void
    {
        $driver = User::factory()->create([
            'is_verified_driver' => true,
            'password' => bcrypt('password123'),
        ]);
        $ride = Ride::factory()->create(['driver_id' => $driver->id]);

        $response = $this->withToken($this->tokenFor($driver))
            ->patchJson("/api/rides/{$ride->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('message', 'Ride cancelled successfully.');
        $this->assertArrayHasKey('ride', $response->json());
        // The envelope uses `status`, not the `success` boolean used elsewhere in the controller.
        $this->assertArrayNotHasKey('success', $response->json());
    }
}
