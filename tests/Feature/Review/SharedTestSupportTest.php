<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\GeoPoint;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-34 — pins the new shared test-support layer itself.
 *
 * The acceptance criterion for RV-34 is measured in errors across the suite, so
 * the support layer needs its own proof that each piece actually works:
 *   - SeedsSystemWallets creates both system wallets by phone, idempotently;
 *   - ActsAsStaff mints a REAL staff/admin token through the real doors
 *     (RV-04: staff and user are separate audiences, so a staff helper that
 *     accidentally produced a user token would be silently wrong);
 *   - RideBuilder inserts a ride with real geometry at an explicit SRID, and its
 *     geometry order is the one the existing raw-SQL fixtures use — RV-34 must not
 *     change what those tests assert while centralising them.
 */
class SharedTestSupportTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    public function test_system_wallets_are_seeded_by_phone_and_are_idempotent(): void
    {
        $wallets = $this->seedSystemWallets();

        $this->assertSame(config('admin.system_admin.phone'), $wallets['primary']->phone_number);
        $this->assertSame(config('admin.sycash.phone'), $wallets['sycash']->phone_number);
        $this->assertNull($wallets['primary']->user_id, 'system wallets have no user');
        $this->assertNull($wallets['sycash']->user_id);
        $this->assertSame(2, Wallet::whereNull('user_id')->count());

        // Idempotent: a second call must not create duplicates.
        $again = $this->seedSystemWallets();
        $this->assertSame($wallets['sycash']->id, $again['sycash']->id);
        $this->assertSame(2, Wallet::whereNull('user_id')->count(), 'seeding twice must not duplicate');
    }

    public function test_staff_and_admin_tokens_are_minted_through_the_real_doors(): void
    {
        $staff = $this->staffToken();
        $this->assertNotEmpty($staff, 'staff login must yield an access token');

        $admin = $this->adminToken();
        $this->assertNotEmpty($admin, 'admin login (by username) must yield an access token');

        // RV-04: these must NOT be interchangeable audiences.
        $this->assertNotSame($staff, $admin);
    }

    public function test_staff_token_is_still_rejected_by_the_user_guard(): void
    {
        // Guards against a regression in the helper that would mint the wrong
        // audience and quietly weaken the RV-04 fix.
        $employee = $this->employee();
        $token = $this->staffToken($employee);

        User::factory()->create(['id' => $employee->id, 'status' => 1, 'token_version' => 1]);

        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
    }

    public function test_ride_builder_inserts_real_geometry_at_an_explicit_srid(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $ride = RideBuilder::for($driver)->create();

        $this->assertTrue($ride->exists);
        $this->assertSame($driver->id, $ride->driver_id);

        $wkt = DB::selectOne(
            'SELECT ST_AsText(pickup_location) AS wkt, ST_SRID(pickup_location) AS srid FROM rides WHERE id = ?',
            [$ride->id]
        );

        $this->assertSame(4326, (int) $wkt->srid, 'geometry must carry an explicit SRID');
        $this->assertMatchesRegularExpression(
            '/^POINT\([\d.]+ [\d.]+\)$/',
            $wkt->wkt,
            'pickup geometry must be a POINT'
        );
    }

    public function test_ride_builder_preserves_the_existing_fixture_geometry(): void
    {
        // The raw-SQL fixtures across the suite write POINT(33.5138 36.2765) for
        // pickup. RV-34 centralises the INSERT but must NOT change the value, or
        // every migrated test would silently start asserting a different place.
        //
        // Compare the parsed coordinates rather than the literal text: MySQL
        // re-serialises a POINT with its own precision (POINT(33.513800 36.276500)),
        // so a string compare would assert MySQL's float formatting rather than the
        // fixture's meaning.
        $ride = RideBuilder::for()->create();

        $wkt = DB::selectOne(
            'SELECT ST_AsText(pickup_location) AS wkt FROM rides WHERE id = ?',
            [$ride->id]
        )->wkt;

        $this->assertSame(
            [33.5138, 36.2765],
            array_map('floatval', array_slice(explode(' ', trim(str_replace(
                ['POINT(', ')'],
                '',
                $wkt
            ))), 0, 2)),
            'RV-34: the builder must reproduce the fixtures\' existing geometry verbatim'
        );
    }

    public function test_geo_point_makes_the_axis_order_explicit(): void
    {
        $this->assertSame('POINT(1.000000 2.000000)', GeoPoint::make(1, 2, GeoPoint::LAT_LNG)->wkt());
        $this->assertSame('POINT(2.000000 1.000000)', GeoPoint::make(1, 2, GeoPoint::LNG_LAT)->wkt());
        // Named constructor follows the MODEL mutator's convention.
        $this->assertSame('POINT(2.000000 1.000000)', GeoPoint::fromLatLng(1, 2)->wkt());
    }

    public function test_ride_builder_overrides_apply_and_support_the_money_path(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        $passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);

        $syCash = $this->syCashWallet();
        Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.system_admin.phone'),
            'balance' => 0,
        ]);

        $passengerWallet = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'balance' => 1000000,
        ]);
        $passenger->update(['wallet_id' => $passengerWallet->id]);

        $ride = RideBuilder::for($driver)
            ->status('active')
            ->price(50000)
            ->seats(3)
            ->paymentMethod('e-pay')
            ->departureTime(now()->subMinutes(5))
            ->create();

        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $passenger);

        $this->assertSame(50000.0, (float) $syCash->fresh()->balance, 'the fare must reach SyCash escrow');
        $this->assertSame(950000.0, (float) $passengerWallet->fresh()->balance);
    }
}
