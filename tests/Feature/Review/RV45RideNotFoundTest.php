<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Identity\Models\Photo;
use App\Modules\Money\Models\Wallet;
use App\Modules\Ride\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Concerns\ProfileFixtures;
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
    use ProfileFixtures;
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
            $this->createProfileFor($this->driver, ['full_name' => 'Test Driver', 'number_of_rides' => 0]);
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

        $response = $this->faultOnRideRead($ride, fn () => $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}"));

        $response->assertStatus(500)
            ->assertJsonPath('message', 'An unexpected error occurred.');
        $this->assertStringNotContainsString('Ride not found', $response->getContent());
        $this->assertStringNotContainsString('available_seats', $response->getContent());
    }

    public function test_driver_view_reports_a_database_fault_as_500_not_as_ride_not_found(): void
    {
        $ride = RideBuilder::for($this->driver)->create();

        $response = $this->faultOnRideRead($ride, fn () => $this->withToken($this->token)
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
    private function faultOnRideRead(Ride $ride, callable $request)
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

            $this->restoreIndexMembership();
            $this->deleteCommittedFixtures($ride);
        }
    }

    /**
     * MySQL rewrites every index that contained `available_seats` when the column is dropped, so
     * re-adding the column leaves rides_departure_time_available_seats_index as (departure_time) and
     * rides_status_departure_seats_index as (status, departure_time) - duplicate column sets of
     * rides_departure_time and rides_status_departure. In a full-suite run that made
     * MigrationEffectsBatchTest::test_no_index_column_set_is_duplicated fail on a schema this test
     * had already restored, so the two indexes are rebuilt here exactly as the migrations define
     * them (2025_05_19_135630 for (departure_time, available_seats), 2026_08_14_144934 for
     * (status, departure_time, available_seats)).
     */
    private function restoreIndexMembership(): void
    {
        $indexes = [
            'rides_departure_time_available_seats_index' => ['departure_time', 'available_seats'],
            'rides_status_departure_seats_index' => ['status', 'departure_time', 'available_seats'],
        ];

        foreach ($indexes as $name => $columns) {
            $present = DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                ['rides', $name]
            );

            if ($present && (int) $present->n > 0) {
                Schema::table('rides', fn ($table) => $table->dropIndex($name));
            }

            Schema::table('rides', fn ($table) => $table->index($columns, $name));
        }
    }

    /**
     * Batch 229 - why this exists: the dropColumn above is DDL, and MySQL implicitly COMMITS the open
     * transaction when DDL runs. Every row this test wrote before the drop (the driver with its
     * wallet, profile, photos and refresh tokens, the ride, and the signup rating UserObserver adds)
     * is therefore committed for real and survives RefreshDatabase's rollback. A full-suite run left
     * 2 rides, 2 users and 2 user_ratings behind in the scratch schema, and the later tests that
     * assert a ride-free world (RideSearchServiceTest, the StaffOperationsController trips list, the
     * T4Batch near-location checks) failed on those rows. Delete exactly what this test created.
     */
    private function deleteCommittedFixtures(Ride $ride): void
    {
        $driverId = (int) $ride->driver_id;

        DB::table('user_ratings')->where('rated_user_id', $driverId)->delete();
        DB::table('rides')->where('id', $ride->id)->delete();
        DB::table('photos')->where('user_id', $driverId)->delete();
        DB::table('profiles')->where('user_id', $driverId)->delete();
        DB::table('refresh_tokens')->where('user_id', $driverId)->delete();
        DB::table('wallets')->where('user_id', $driverId)->delete();
        DB::table('users')->where('id', $driverId)->delete();

        // The three system wallets seedSystemWallets() created in setUp() were inside the same
        // implicitly committed transaction, so they survive the rollback too and keep the reserved
        // phones occupied for the rest of the run. Any later test that INSERTs a wallet on one of
        // those phones then dies on wallets_phone_number_unique - tests/Unit/Services/Payment's
        // fixtures do exactly that - so they are part of "what this test created" as well.
        DB::table('wallets')
            ->whereNull('user_id')
            ->whereIn('phone_number', array_filter([
                config('admin.system_admin.phone'),
                config('admin.sycash.phone'),
                config('admin.external.phone'),
            ]))
            ->delete();
    }
}
