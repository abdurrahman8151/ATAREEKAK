<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-45 (row 120): a refused ride creation is a curated domain refusal and must return 422
 * with its message. A genuine fault must stay a generic 500 and must not leak (RV-13).
 *
 * The allowed case uses a driver with ALL four required documents, because a missing
 * document raises a plain \Exception (RV-53, owner decision still open), and a driver
 * wallet, because a cash ride needs one (RideService.php:63).
 */
class RV45CreateRideStatusTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private function payload(): array
    {
        return [
            'pickup_address' => 'Damascus',
            'pickup_lat' => 33.5138,
            'pickup_lng' => 36.2765,
            'destination_address' => 'Homs',
            'destination_lat' => 34.7324,
            'destination_lng' => 36.7137,
            'departure_time' => now()->addHours(48)->toISOString(),
            'available_seats' => 2,
            'price_per_seat' => 50000,
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'communication_number' => '0911000000',
            'vehicle_type' => 'Toyota Corolla',
            // `rides.distance` and `rides.duration` are NOT NULL; the plain /api/rides path
            // carries no route data, so a create without them raises 1048 (separate finding,
            // see R2 sec 151). Supply them the way RideTest's ride fixture does.
            'distance' => 320.5,
            'duration' => 240,
        ];
    }

    private function driverWith(array $attributes, bool $withDocuments): User
    {
        $driver = User::factory()->create(array_merge(
            ['status' => 1, 'password' => bcrypt('password123')],
            $attributes,
        ));

        if ($withDocuments) {
            foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
                Photo::create(['user_id' => $driver->id, 'type' => $type, 'path' => "docs/{$type}.jpg"]);
            }

            $this->seedSystemWallets(10_000_000);
            $phone = '091'.random_int(1000000, 9999999);
            $wallet = Wallet::create([
                'user_id' => $driver->id,
                'phone_number' => $phone,
                'wallet_number' => 'WLT-DRV-'.Str::random(6),
                'balance' => 1_000_000,
            ]);
            $driver->update(['wallet_id' => $wallet->id]);
        }

        return $driver;
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->json('tokens.access_token');
    }

    public function test_denied_unverified_driver_gets_422_with_curated_message(): void
    {
        $driver = $this->driverWith(['is_verified_driver' => false], withDocuments: true);

        $response = $this->withToken($this->tokenFor($driver))->postJson('/api/rides', $this->payload());

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You must be verified as a driver to create rides');
    }

    public function test_genuine_fault_stays_generic_500_and_does_not_leak(): void
    {
        // A non-domain fault must fall through to the catch-all: 500, and the internal
        // message must never reach the client (RV-13). A verified driver with NO documents
        // raises a plain \Exception ("Missing required driver verification documents ...")
        // from DocumentVerificationService, which is not a domain refusal. The message is
        // internal wording, so the client must still get only the generic sentence.
        $driver = $this->driverWith(['is_verified_driver' => true], withDocuments: false);

        $response = $this->withToken($this->tokenFor($driver))->postJson('/api/rides', $this->payload());

        $response->assertStatus(500)
            ->assertJsonPath('message', 'The request could not be completed. Please try again.');
        $this->assertStringNotContainsString('Missing required driver verification', $response->getContent());
    }

    public function test_allowed_verified_documented_driver_gets_201(): void
    {
        $driver = $this->driverWith(['is_verified_driver' => true], withDocuments: true);

        $response = $this->withToken($this->tokenFor($driver))->postJson('/api/rides', $this->payload());

        $response->assertStatus(201);
    }
}
