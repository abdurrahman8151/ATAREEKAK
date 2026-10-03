<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Decision 11 (owner, 2026-10-02): KYC gates the ACTIONS, not the account.
 *
 * The owner's words: "the user can still search and everything even if he is not verified but he
 * cant make or book rides."
 *
 * Measured before writing this: BOTH halves were already true in the code and nothing pinned
 * either - `RideService::createRide` -> `RideValidationService::validateDriverCanCreateRide`
 * (refuses `is_verified_driver = false`, a missing profile, and missing documents) and
 * `BookingService::bookRide` -> `validatePassengerCanBook` (refuses `is_verified_passenger = false`),
 * while `api.php` puts search/autocomplete behind auth + throttle only, with no verification check.
 *
 * So this task delivers the PIN, not a behaviour change: if someone later adds a verification
 * check to search (which would lock unverified users out of browsing - the opposite of what the
 * owner asked for), or drops one of the two action gates, this fails.
 */
class KycActionGateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private string $unverifiedToken;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create([
            'is_verified_driver' => false,
            'is_verified_passenger' => false,
            'verification_status' => 'none',
            'password' => bcrypt('password123'),
        ]);

        $this->unverifiedToken = $this->tokenFor($user);
    }

    /** @test */
    public function an_unverified_user_can_still_search_for_rides(): void
    {
        $date = now()->addDays(2)->toDateString();
        RideBuilder::forUserId($this->verifiedDriverId())
            ->departureTime(now()->addDays(2))
            ->create();

        $response = $this->withToken($this->unverifiedToken)->getJson('/api/rides/search?'.http_build_query([
            'departure_date' => $date,
            'seats_required' => 1,
            'source_lat' => 33.5138,
            'source_lng' => 36.2765,
            'dest_lat' => 36.2021,
            'dest_lng' => 37.1343,
        ]));

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data') ?? $response->json(),
            'an unverified user must still be able to browse rides - decision 11');
    }

    /** @test */
    public function an_unverified_user_cannot_create_a_ride(): void
    {
        // Payload field names match CreateRideDTO::fromRequest exactly - an earlier draft used
        // `from_*`/`to_*` and got a 422 on a missing pickup_lat, which would have made this test
        // pass for the WRONG reason (validation, not the verification gate).
        $response = $this->withToken($this->unverifiedToken)
            ->postJson('/api/rides/create-with-route', [
                'pickup_address' => 'Damascus',
                'destination_address' => 'Aleppo',
                'pickup_lat' => 33.5138, 'pickup_lng' => 36.2765,
                'destination_lat' => 36.2021, 'destination_lng' => 37.1343,
                'departure_time' => now()->addDay()->format('Y-m-d H:i:s'),
                'available_seats' => 3,
                'price_per_seat' => 5000,
                'vehicle_type' => 'sedan',
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'communication_number' => '0912345678',
            ]);

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString(
            'verified',
            strtolower((string) $response->json('message')),
            'the refusal must be the VERIFICATION gate, not a validation error - decision 11'
        );
    }

    /** @test */
    public function an_unverified_user_cannot_book_a_ride(): void
    {
        $ride = RideBuilder::forUserId($this->verifiedDriverId())
            ->departureTime(now()->addDays(2))
            ->create();

        $response = $this->withToken($this->unverifiedToken)
            ->postJson("/api/rides/{$ride->id}/book", [
                'seats' => 1,
                'communication_number' => '0912345678',
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString(
            'verified',
            strtolower((string) $response->json('message')),
            'the refusal must be the VERIFICATION gate, not a validation error - decision 11'
        );
    }

    private function verifiedDriverId(): int
    {
        $driver = User::factory()->create([
            'is_verified_driver' => true,
            'verification_status' => 'approved',
        ]);

        if (! $driver->profile) {
            $driver->profile()->create(['full_name' => 'Verified Driver', 'number_of_rides' => 0]);
        }

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::firstOrCreate(
                ['user_id' => $driver->id, 'type' => $type],
                ['path' => "verifications/{$type}.jpg"],
            );
        }

        return (int) $driver->id;
    }

    private function tokenFor(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $response->json('tokens.access_token');
        $this->assertNotNull($token, 'login must return a token for the gate test to run');

        return (string) $token;
    }
}
