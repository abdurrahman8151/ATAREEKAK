<?php

namespace App\Console\Testing;

use App\Console\Commands\GuardsAgainstProductionExecution;
use App\Console\Testing\Support\RideGatedInteractionScenarios;
use App\Models\Booking;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Services\Profile\ProfileInteractionService;
use App\Support\GeoPoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Corrected for actual DB schema:
 *   profile_comments.user_id  = commenter  (not commenter_id)
 *   user_ratings.rater_id     = rater       (not user_id)
 *
 * PLACE IN: app/Console/Commands/TestRideGatedInteractionCommand.php
 * RUN:      php artisan syride:test-ride-interaction
 * COMMIT:   php artisan syride:test-ride-interaction --commit
 */
class Testridegatedinteractioncommand extends Command
{
    use GuardsAgainstProductionExecution; // AF-4
    use RideGatedInteractionScenarios;

    protected $signature = 'syride:test-ride-interaction {--commit : Persist data instead of rolling back}';

    /** AF-3: hidden from artisan list; still runnable by name (developer harness, refuses production). */
    protected $hidden = true;

    protected $description = '[DEV] End-to-end test: ride-gated comments & ratings';

    private int $pass = 0;

    private int $fail = 0;

    private bool $scenFail = false;

    private int $seq = 0;   // increments per scenario — for unique emails

    private int $opSeq = 0;   // increments per makeRide/makeBooking — for unique comm numbers

    private string $hash;

    public function handle(ProfileInteractionService $service): int
    {
        $this->hash = Hash::make('test');

        $this->renderHeader();

        // ── S1 ────────────────────────────────────────────────────────────────
        $this->scenarioCommentAllowedAfterCompletedRide($service);

        // ── S2 ────────────────────────────────────────────────────────────────
        $this->scenarioRatingAllowedAfterCompletedRide($service);

        // ── S3 ────────────────────────────────────────────────────────────────
        $this->scenarioTwoRidesTwoCommentsAllowed($service);

        // ── S4 ────────────────────────────────────────────────────────────────
        $this->scenarioTwoRidesTwoRatingsAllowed($service);

        // ── S5 ────────────────────────────────────────────────────────────────
        $this->scenarioNoBookingCommentBlocked($service);

        // ── S6 ────────────────────────────────────────────────────────────────
        $this->scenarioPendingBookingRatingBlocked($service);

        // ── S7 ────────────────────────────────────────────────────────────────
        $this->scenarioConfirmedBookingCommentBlocked($service);

        // ── S8 ────────────────────────────────────────────────────────────────
        $this->scenarioCancelledBookingRatingBlocked($service);

        // ── S9 ────────────────────────────────────────────────────────────────
        $this->scenarioCommentOnUnrelatedDriverBlocked($service);

        // ── S10 ───────────────────────────────────────────────────────────────
        $this->scenarioDuplicateCommentBlocked($service);

        // ── S11 ───────────────────────────────────────────────────────────────
        $this->scenarioDuplicateRatingBlocked($service);

        // ── Summary ──────────────────────────────────────────────────────────
        $this->renderSummary();

        return $this->fail > 0 ? 1 : 0;
    }

    private function renderHeader(): void
    {
        $this->line('');
        $this->line('  ╔═══════════════════════════════════════════════════════╗');
        $this->line('    🧪 SyRide — Ride-Gated Interaction Test Suite');
        $this->line('    comments · ratings · eligibility · duplicate guard');
        $this->line('  ╚═══════════════════════════════════════════════════════╝');
    }

    private function renderSummary(): void
    {
        $this->line('');
        $this->line('  ╔═══════════════════════════════════════════════════════╗');
        $total = $this->pass + $this->fail;
        $colour = $this->fail > 0 ? 'red' : 'green';
        $badge = $this->fail === 0 ? ' — all green ✅' : ", {$this->fail} FAILED ❌";
        $this->line("  <fg={$colour}>  RESULTS: {$this->pass}/{$total} passed{$badge}</>");
        $this->line('  ╚═══════════════════════════════════════════════════════╝');

        if ($this->option('commit')) {
            $this->info('  Committed — check the DB.');
        } else {
            $this->info('  All transactions rolled back — DB untouched. Use --commit to persist.');
        }
    }

    /**
     * Runs an action that is expected to be rejected and reports whether it threw, together
     * with the HTTP code carried by the exception (403 uneligible, 409 duplicate).
     *
     * @return array{0: bool, 1: int}
     */
    private function attemptBlocked(callable $action): array
    {
        $blocked = false;
        $code = 0;

        try {
            $action();
        } catch (\Exception $e) {
            $blocked = true;
            $code = $e->getCode();
        }

        return [$blocked, $code];
    }

    // =========================================================================
    // SCENARIO RUNNER
    // =========================================================================

    private function runScenario(string $name, callable $fn): void
    {
        $this->line("\n  ┌─ {$name}");
        $this->scenFail = false;

        DB::beginTransaction();
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->line("  │  💥 UNEXPECTED THROW: {$e->getMessage()}");
            $this->line("  │     {$e->getFile()}:{$e->getLine()}");
            $this->fail++;
            $this->scenFail = true;
        }

        if ($this->option('commit')) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        $this->line('  └─ '.($this->scenFail ? '<fg=red>FAIL</>' : '<fg=green>PASS</>'));
    }

    // =========================================================================
    // ASSERTION HELPER
    // =========================================================================

    private function check(string $label, mixed $expected, mixed $actual): void
    {
        $norm = fn ($v) => $v instanceof \BackedEnum ? $v->value : $v;
        $e = $norm($expected);
        $a = $norm($actual);

        if ($e == $a) {
            $this->line("  │  ✅  {$label}  ({$this->v($actual)})");
            $this->pass++;
        } else {
            $this->line("  │  ❌  {$label}");
            $this->line("  │       got='{$this->v($actual)}'  expected='{$this->v($expected)}'");
            $this->fail++;
            $this->scenFail = true;
        }
    }

    private function v(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_null($v)) {
            return 'null';
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }

        return (string) $v;
    }

    // =========================================================================
    // FACTORY HELPERS
    // =========================================================================

    private function makeScenario(?string $bookingStatus): array
    {
        $this->seq++;
        $driver = $this->makeUser("Driver-{$this->seq}");
        $passenger = $this->makeUser("Passenger-{$this->seq}");

        $this->makeProfile($driver);

        $ride = $this->makeRide($driver);
        $booking = $bookingStatus !== null
            ? $this->makeBooking($ride, $passenger, $bookingStatus)
            : null;

        return compact('driver', 'passenger', 'ride', 'booking');
    }

    private function makeUser(string $label): User
    {
        return User::forceCreate([
            'first_name' => 'Test',
            'last_name' => $label,
            'email' => strtolower("test.{$label}.{$this->seq}@test.local"),
            'password' => $this->hash,
            'status' => 1,
            'token_version' => 1,   // remove this line if the column doesn't exist
        ]);
    }

    private function makeProfile(User $user): Profile
    {
        return Profile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'description' => null,
                'address' => null,
                'gender' => null,
                'type_of_car' => null,
                'color_of_car' => null,
                'number_of_seats' => null,
                'radio' => false,
                'smoking' => false,
                'number_of_rides' => 0,
            ]
        );
    }

    private function makeRide(User $driver): Ride
    {
        $this->opSeq++;
        $n = $this->opSeq;

        $rideId = DB::table('rides')->insertGetId([
            'driver_id' => $driver->id,
            'pickup_address' => 'Test Pickup — Damascus',
            'destination_address' => 'Test Destination — Aleppo',
            // RV-25: shared GeoPoint writer emits POINT(lat lng) (latitude first) — same real
            // cities (Damascus / Aleppo), matching the production convention.
            'pickup_location' => GeoPoint::fromLatLng(33.5138, 36.2765)->raw(),
            'destination_location' => GeoPoint::fromLatLng(36.2021, 37.1343)->raw(),
            'departure_time' => now()->subHours(3),
            'status' => 'finished',
            'available_seats' => 3,
            'price_per_seat' => 5000,
            'distance' => 349.0,
            'duration' => 210,
            'vehicle_type' => 'sedan',
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'communication_number' => "091{$n}00001",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Ride::findOrFail($rideId);
    }

    private function makeBooking(Ride $ride, User $passenger, string $status): Booking
    {
        $this->opSeq++;
        $n = $this->opSeq;

        return Booking::create([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => $status,
            'communication_number' => "091{$n}00002",
        ]);
    }
}
