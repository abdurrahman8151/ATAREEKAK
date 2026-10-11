<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-45 evidence: RideController::show and driverView map a real "ride not found" to 404, but must
 * NOT map a database fault to 404. Before the fix both catches were \Throwable, so a fault was
 * reported as "Ride not found" and the caller was told the ride does not exist.
 *
 * Proof of the fault branch: the ride read selects `rides.available_seats`. The test drops that
 * column for the duration of one request and restores it in finally, so the fault is a real
 * QueryException from the database, not a mock. The schema is restored before the test ends.
 */
class RV45RideNotFoundTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000);

        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        if (! $this->driver->profile) {
            $this->driver->profile()->create(['full_name' => 'Test Driver', 'number_of_rides' => 0]);
        }

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create([
                'user_id' => $this->driver->id,
                'type' => $type,
                'path' => "verifications/{$type}/test.jpg",
            ]);
        }

        $wallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '091'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-DRV-'.Str::random(6),
            'balance' => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);

        $this->token = $this->getToken($this->driver);
    }

    public function test_show_returns_404_for_a_ride_that_does_not_exist(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/rides/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ride not found');
    }

    public function test_driver_view_returns_404_for_a_ride_that_does_not_exist(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/rides/999999/passengers')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ride not found');
    }

    public function test_show_reports_a_database_fault_as_500_not_as_ride_not_found(): void
    {
        $ride = RideBuilder::for($this->driver)->create();

        $response = $this->faultOnRideRead(fn () => $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}"));

        $response->assertStatus(500)
            ->assertJsonPath('message', 'An unexpected error occurred.');
        $this->assertStringNotContainsString('Ride not found', $response->getContent());
        $this->assertStringNotContainsString('available_seats', $response->getContent());
    }

    public function test_driver_view_reports_a_database_fault_as_500_not_as_ride_not_found(): void
    {
        $ride = RideBuilder::for($this->driver)->create();

        $response = $this->faultOnRideRead(fn () => $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}/passengers"));

        $response->assertStatus(500)
            ->assertJsonPath('message', 'An unexpected error occurred.');
        $this->assertStringNotContainsString('Ride not found', $response->getContent());
        $this->assertStringNotContainsString('available_seats', $response->getContent());
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }

    /**
     * Run $request with rides.available_seats dropped, then restore it. The drop is a real schema
     * change confined to the scratch test database and always reverted in finally.
     */
    private function faultOnRideRead(callable $request)
    {
        $this->assertTrue(Schema::hasColumn('rides', 'available_seats'), 'precondition: column present');

        Schema::table('rides', function ($table) {
            $table->dropColumn('available_seats');
        });

        try {
            return $request();
        } finally {
            Schema::table('rides', function ($table) {
                $table->unsignedSmallInteger('available_seats');
            });
        }
    }
}
