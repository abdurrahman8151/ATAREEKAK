<?php

namespace Tests\Feature\Review;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Row 5 (owner decision): the driver's contact number (communication_number) is shown to the
 * ride's own driver only on the ride response. Other authenticated users, and unauthenticated
 * callers, do not see it. Denied and allowed cases are both asserted.
 */
class Row5DriverPhoneVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const DRIVER_PHONE = '0911222333';

    public function test_driver_sees_their_own_communication_number_on_ride_detail(): void
    {
        [$driver, $ride] = $this->makeRideWithDriver();

        $this->withToken($this->getToken($driver))
            ->getJson("/api/rides/{$ride->id}")
            ->assertOk()
            ->assertJsonPath('data.communication_number', self::DRIVER_PHONE);
    }

    public function test_another_signed_in_user_does_not_see_the_driver_number(): void
    {
        [, $ride] = $this->makeRideWithDriver();
        $stranger = User::factory()->create(['password' => bcrypt('password123')]);

        $response = $this->withToken($this->getToken($stranger))
            ->getJson("/api/rides/{$ride->id}")
            ->assertOk();

        $this->assertNull($response->json('data.communication_number'));
        $this->assertStringNotContainsString(self::DRIVER_PHONE, $response->getContent());
    }

    public function test_unauthenticated_caller_does_not_see_the_driver_number(): void
    {
        [, $ride] = $this->makeRideWithDriver();

        $response = $this->getJson("/api/rides/{$ride->id}");

        $this->assertStringNotContainsString(self::DRIVER_PHONE, $response->getContent());
    }

    /**
     * @return array{0: User, 1: Ride}
     */
    private function makeRideWithDriver(): array
    {
        $driver = User::factory()->create(['password' => bcrypt('password123')]);
        $ride = RideBuilder::for($driver)
            ->withAttributes(['communication_number' => self::DRIVER_PHONE])
            ->create();

        return [$driver, $ride];
    }

    private function getToken(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        return $response->json('tokens.access_token') ?? $response->json('access_token');
    }
}
