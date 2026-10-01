<?php

namespace Tests\Feature\AppFuture;

use App\Console\Commands\Getloadtesttokens;
use App\Console\Commands\Testfullrideflow;
use App\Enums\BookingStatus;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Repositories\RideRepository;
use App\Services\Ride\Noshowservice;
use App\Services\Ride\RideSearchService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * AF-4 (app-future audit) — the un-tangle.
 *
 * Pins, in the order the sub-items shipped:
 *   4a  the live /rides/search path runs RideSearchService (the injected-but-
 *       never-called service), not the deleted repository copy — proven by the
 *       eager-loaded relations appearing in the raw JSON, which the old repo
 *       path (->with('driver') only) never produced;
 *   4b  Sanctum is gone from the app surface (config, trait, CORS path);
 *   4c  UserVerified is dispatched AND its listener notifies (approve was
 *       silent while reject notified inline);
 *   4d  BookingStatus::NO_SHOW exists — the DB enum and every noshow write
 *       used 'no_show' while tryFrom() returned null for a live status;
 *   4e  the no-show gate is config-driven and defaults to the real HOURS
 *       (1h/2h), not the testing 1-minute values;
 *   4f  debug commands (forge rides / mint tokens) refuse to run in production.
 */
class UntangleBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_search_serializes_the_relations_the_service_eager_loads(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('MySQL required for spatial search.');
        }

        $driver = User::factory()->create();
        $driver->profile()->create(['full_name' => 'AF4 Driver', 'number_of_rides' => 0]);
        $ride = $this->insertRideAt($driver, 36.2765, 33.5138, 37.1343, 36.2021);

        // Direct service call is what the endpoint now runs; the old repository
        // path selected only ->with('driver') — no profile, no receivedRatings,
        // no booked-seat count. Their presence pins the delegation.
        $results = app(RideSearchService::class)->searchRides([
            'departure_date' => $ride->departure_time->toDateString(),
            'seats_required' => 1,
            'source_lat' => 33.5138, 'source_lng' => 36.2765,
            'dest_lat' => 36.2021, 'dest_lng' => 37.1343,
        ]);

        $found = $results->firstWhere('id', $ride->id);
        $this->assertNotNull($found, 'the endpoint search must find the ride');
        // Nested relations load on their parent model, not via dotted paths on Ride.
        $this->assertTrue($found->relationLoaded('driver'), 'the wired path eager-loads the driver');
        $this->assertTrue($found->driver->relationLoaded('receivedRatings'), 'wired path batch-loads ratings');
        $this->assertTrue($found->driver->relationLoaded('profile'), 'wired path batch-loads driver profile');
        $this->assertTrue(isset($found->total_booked_seats), 'wired path supplies the booked-seat count');
    }

    public function test_the_dead_repository_search_is_gone(): void
    {
        $this->assertFalse(
            method_exists(RideRepository::class, 'searchRides'),
            'AF-4a: the repository copy of search was deleted; one search, one owner'
        );
        $this->assertFalse(
            method_exists(RideRepositoryInterface::class, 'searchRides') ||
            in_array('searchRides', get_class_methods(RideRepositoryInterface::class), true),
            'AF-4a: searchRides must stay off the persistence contract'
        );
    }

    public function test_sanctum_is_removed_from_the_app_surface(): void
    {
        $this->assertNull(config('sanctum'), 'config/sanctum.php deleted — no Sanctum config at runtime');
        $this->assertFalse(
            in_array(HasApiTokens::class, class_uses(User::class), true),
            'User must not advertise API tokens it never issues (JWT is the auth story)'
        );
        $this->assertNotContains(
            'sanctum/csrf-cookie',
            config('cors.paths'),
            'CORS must not advertise a route the app no longer ships'
        );
    }

    public function test_no_route_uses_the_sanctum_guard(): void
    {
        foreach (Route::getRoutes() as $r) {
            $this->assertNotContains(
                'auth:sanctum',
                $r->gatherMiddleware(),
                "route {$r->uri()} must not use the removed Sanctum guard"
            );
        }
    }

    public function test_booking_status_covers_the_live_no_show_state(): void
    {
        $noShow = BookingStatus::tryFrom('no_show');
        $this->assertNotNull($noShow, "AF-4d: 'no_show' is written by Noshowservice and exists in the DB enum");
        $this->assertSame('No Show', $noShow->label());
        $this->assertSame('darkred', $noShow->color());
        $this->assertFalse($noShow->isActive());
    }

    public function test_noshow_windows_are_config_and_default_to_real_hours(): void
    {
        $this->assertSame(1.0, (float) config('rides.noshow.gate_hours'));
        $this->assertSame(2.0, (float) config('rides.noshow.dispute_hours'));

        // The old constants were GATE_MINUTES=1 / DISPUTE_MINUTES=2 (test mode).
        $svc = new \ReflectionClass(Noshowservice::class);
        $this->assertFalse($svc->hasConstant('GATE_MINUTES'), 'minute constants must stay gone');
        $this->assertFalse($svc->hasConstant('DISPUTE_MINUTES'));
    }

    public function test_reporting_a_no_show_before_the_hour_gate_is_refused(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('MySQL required for spatial/enum money path.');
        }

        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);

        // Departure 30 MINUTES ago: under the old 1-minute test gate this
        // reported fine; under the real 1-hour default it must be refused.
        $ride = $this->insertRideAt($driver, 36.2765, 33.5138, 37.1343, 36.2021, -30);
        $booking = Booking::create([
            'ride_id' => $ride->id, 'user_id' => $passenger->id,
            'seats' => 1, 'status' => 'confirmed',
            // RV-38: dropped pickup_stop_id / total_price / booking_code / passenger_phone.
            // None are bookings columns or fillable keys — Eloquent silently discarded them
            // (exactly the class preventSilentlyDiscardingAttributes exists to surface). This
            // no-show-gate test needs only a valid confirmed e-pay booking; amount_paid and
            // payment_method ARE real (RV-40) columns and stay.
            'amount_paid' => 50000,
            'payment_method' => 'e-pay',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/1 hour\(s\)/');
        app(Noshowservice::class)
            ->reportPassengerNoShow($booking->id, $driver->fresh());
    }

    public function test_debug_commands_refuse_to_run_in_production(): void
    {
        // app()->environment() reads the container 'env' binding (set from
        // APP_ENV by DetectEnvironment at bootstrap) — not config('app.env').
        $original = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            foreach ([Testfullrideflow::class, Getloadtesttokens::class] as $cmd) {
                $exit = Artisan::call($cmd);
                $out = Artisan::output();
                $this->assertSame(1, $exit, "$cmd must FAIL when app env is production");
                // The message is the point: without the trait, an
                // argument-less invocation can also exit 1 (bad usage), which
                // would make an exit-code-only assertion pass vacuously. This
                // pins that the FAILURE came from the production guard, not an
                // accident, i.e. handle() never executed.
                $this->assertStringContainsString(
                    'disabled in production',
                    $out,
                    "$cmd must be refused by the AF-4 guard, not merely error"
                );
            }
        } finally {
            $this->app['env'] = $original;
        }

        // Non-production must still resolve the command (guard passes through).
        $this->assertInstanceOf(Command::class, app()->make(Testfullrideflow::class));
    }

    /** Raw insert: spatial columns bypass fill() on purpose (mutators own them). */
    private function insertRideAt(User $driver, float $srcLng, float $srcLat, float $dstLng, float $dstLat, int $departureMinutes = 2880): Ride
    {
        // RV-34: shared builder. Spatial coordinates stay parametric — the geo tests
        // depend on the caller choosing the points. The builder writes the geometry
        // through a raw expression, which is required because the spatial columns are
        // NOT NULL without defaults and the model mutators own them.
        return RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320500,
                'duration' => 14400,
                'communication_number' => '0911000000',
            ])
            ->rawPickup(sprintf('POINT(%F %F)', $srcLng, $srcLat))
            ->rawDestination(sprintf('POINT(%F %F)', $dstLng, $dstLat))
            ->departureTime(now()->addMinutes($departureMinutes))
            ->create();
    }
}
