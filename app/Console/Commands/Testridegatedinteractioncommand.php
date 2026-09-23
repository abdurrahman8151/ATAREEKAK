<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Profile;
use App\Models\ProfileComment;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Profile\ProfileInteractionService;
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
    protected $signature   = 'syride:test-ride-interaction {--commit : Persist data instead of rolling back}';
    protected $description = '[DEV] End-to-end test: ride-gated comments & ratings';

    private int  $pass     = 0;
    private int  $fail     = 0;
    private bool $scenFail = false;
    private int  $seq      = 0;   // increments per scenario — for unique emails
    private int  $opSeq    = 0;   // increments per makeRide/makeBooking — for unique comm numbers
    private string $hash;

    public function handle(ProfileInteractionService $service): int
    {
        $this->hash = Hash::make('test');

        $this->line('');
        $this->line('  ╔═══════════════════════════════════════════════════════╗');
        $this->line('    🧪 SyRide — Ride-Gated Interaction Test Suite');
        $this->line('    comments · ratings · eligibility · duplicate guard');
        $this->line('  ╚═══════════════════════════════════════════════════════╝');

        // ── S1 ────────────────────────────────────────────────────────────────
        $this->runScenario('1 — Comment ALLOWED after completed ride', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $result = $service->addComment($passenger->id, $driver->id, 'Great driver!', $ride->id);

            $this->check('Returns a comment array',         true,            isset($result['id']));
            $this->check('Comment text stored correctly',   'Great driver!', $result['comment']);
            $this->check('ride_id stored on the comment',  $ride->id,        $result['ride_id']);
            $this->check('Commenter ID in response',       $passenger->id,   $result['commenter']['id']);
            $this->check('DB row created',                 1,
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
        });

        // ── S2 ────────────────────────────────────────────────────────────────
        $this->runScenario('2 — Rating ALLOWED after completed ride', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $stats = $service->rateUser($passenger->id, $driver->id, 4.5, $ride->id);

            $this->check('Returns stats array',   true, isset($stats['average']));
            $this->check('Average is 3.75',       3.75,  (float) $stats['average']);
            $this->check('total_ratings is 2',    2,    $stats['total_ratings']);
            $this->check('DB row created',        1,
                UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Rating value stored',   4.5,
                (float) UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('rating'));
        });

        // ── S3 ────────────────────────────────────────────────────────────────
        $this->runScenario('3 — Two rides with same driver → two comments allowed', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride1] =
                $this->makeScenario('completed');

            $ride2 = $this->makeRide($driver);
            $this->makeBooking($ride2, $passenger, 'completed');

            $r1 = $service->addComment($passenger->id, $driver->id, 'First ride was great', $ride1->id);
            $r2 = $service->addComment($passenger->id, $driver->id, 'Second ride too',      $ride2->id);

            $this->check('First comment created',           true, isset($r1['id']));
            $this->check('Second comment created',          true, isset($r2['id']));
            $this->check('Comments have different ride_id', true, $r1['ride_id'] !== $r2['ride_id']);
            $this->check('Two comments in DB',              2,
                ProfileComment::where('user_id', $passenger->id)->count());
        });

        // ── S4 ────────────────────────────────────────────────────────────────
        $this->runScenario('4 — Two rides with same driver → two ratings allowed', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride1] =
                $this->makeScenario('completed');

            $ride2 = $this->makeRide($driver);
            $this->makeBooking($ride2, $passenger, 'completed');

            $stats1 = $service->rateUser($passenger->id, $driver->id, 5.0, $ride1->id);
            $stats2 = $service->rateUser($passenger->id, $driver->id, 3.0, $ride2->id);

            $this->check('First rating saved',          true, $stats1['total_ratings'] === 2);
            $this->check('Second rating saved',         true, $stats2['total_ratings'] === 3);
            $this->check('Average of 5+3+seed=3.67',   3.67,  (float) $stats2['average']);
            $this->check('Two rating rows in DB',       2,
                UserRating::where('rater_id', $passenger->id)->count());
        });

        // ── S5 ────────────────────────────────────────────────────────────────
        $this->runScenario('5 — No booking → comment BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario(null);

            $blocked = false; $code = 0;
            try {
                $service->addComment($passenger->id, $driver->id, 'Should never appear', $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Exception thrown',     true, $blocked);
            $this->check('HTTP code is 403',     403,  $code);
            $this->check('Zero DB rows created', 0,
                ProfileComment::where('user_id', $passenger->id)->count());
        });

        // ── S6 ────────────────────────────────────────────────────────────────
        $this->runScenario('6 — Pending booking → rating BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('pending');

            $blocked = false; $code = 0;
            try {
                $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Exception thrown',        true, $blocked);
            $this->check('HTTP code is 403',        403,  $code);
            $this->check('Zero rating rows in DB',  0,
                UserRating::where('rater_id', $passenger->id)->count());
        });

        // ── S7 ────────────────────────────────────────────────────────────────
        $this->runScenario('7 — Confirmed booking (ride not done) → comment BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('confirmed');

            $blocked = false; $code = 0;
            try {
                $service->addComment($passenger->id, $driver->id, 'Too early', $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Exception thrown for confirmed booking', true, $blocked);
            $this->check('HTTP code is 403',                       403,  $code);
        });

        // ── S8 ────────────────────────────────────────────────────────────────
        $this->runScenario('8 — Cancelled booking → rating BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('cancelled');

            $blocked = false; $code = 0;
            try {
                $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Exception thrown for cancelled booking', true, $blocked);
            $this->check('HTTP code is 403',                       403,  $code);
        });

        // ── S9 ────────────────────────────────────────────────────────────────
        $this->runScenario('9 — Completed ride with driver A → cannot comment on driver B (403)', function () use ($service) {
            ['driver' => $driverA, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $driverB = $this->makeUser('Unrelated-Driver');
            $this->makeProfile($driverB);

            $blocked = false; $code = 0;
            try {
                $service->addComment($passenger->id, $driverB->id, 'Never rode with B', $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Exception thrown (wrong driver)', true, $blocked);
            $this->check('HTTP code is 403',                403,  $code);
            $this->check('No comment on driverB profile',  0,
                ProfileComment::where('user_id', $passenger->id)->count());
        });

        // ── S10 ───────────────────────────────────────────────────────────────
        $this->runScenario('10 — Duplicate comment on same ride → BLOCKED (409)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $first = $service->addComment($passenger->id, $driver->id, 'First and only', $ride->id);
            $this->check('First comment saved', true, isset($first['id']));

            $blocked = false; $code = 0;
            try {
                $service->addComment($passenger->id, $driver->id, 'Trying again', $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Duplicate blocked',       true,            $blocked);
            $this->check('HTTP code is 409',        409,             $code);
            $this->check('Still only 1 row in DB',  1,
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Original text preserved', 'First and only',
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('comment'));
        });

        // ── S11 ───────────────────────────────────────────────────────────────
        $this->runScenario('11 — Duplicate rating on same ride → BLOCKED (409), first preserved', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $stats1 = $service->rateUser($passenger->id, $driver->id, 4.0, $ride->id);
            $this->check('First rating (4.0) saved', 2, $stats1['total_ratings']);

            $blocked = false; $code = 0;
            try {
                $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id);
            } catch (\Exception $e) { $blocked = true; $code = $e->getCode(); }

            $this->check('Duplicate rating blocked',             true, $blocked);
            $this->check('HTTP code is 409',                     409,  $code);
            $this->check('Still only 1 rating row',              1,
                UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Original rating 4.0 not overwritten',  4.0,
                (float) UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('rating'));
        });

        // ── S12 ───────────────────────────────────────────────────────────────
        $this->runScenario('12 — Driver ratings & comments on Passenger ALLOWED after completed ride', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $comment = $service->addComment($driver->id, $passenger->id, 'Great passenger!', $ride->id);
            $this->check('Driver comment created', true, isset($comment['id']));

            $stats = $service->rateUser($driver->id, $passenger->id, 5.0, $ride->id);
            $this->check('Driver rated passenger successfully', true, isset($stats['average']));
            $this->check('Rating stored in DB', 5.0,
                (float) UserRating::where('rater_id', $driver->id)
                    ->where('rated_user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('rating'));
        });

        // ── Summary ──────────────────────────────────────────────────────────
        $this->line('');
        $this->line('  ╔═══════════════════════════════════════════════════════╗');
        $total  = $this->pass + $this->fail;
        $colour = $this->fail > 0 ? 'red' : 'green';
        $badge  = $this->fail === 0 ? ' — all green ✅' : ", {$this->fail} FAILED ❌";
        $this->line("  <fg={$colour}>  RESULTS: {$this->pass}/{$total} passed{$badge}</>");
        $this->line('  ╚═══════════════════════════════════════════════════════╝');

        if ($this->option('commit')) {
            $this->info('  Committed — check the DB.');
        } else {
            $this->info('  All transactions rolled back — DB untouched. Use --commit to persist.');
        }

        return $this->fail > 0 ? 1 : 0;
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

        $this->line('  └─ ' . ($this->scenFail ? '<fg=red>FAIL</>' : '<fg=green>PASS</>'));
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
        if (is_bool($v))               return $v ? 'true' : 'false';
        if (is_null($v))               return 'null';
        if ($v instanceof \BackedEnum) return $v->value;
        return (string) $v;
    }

    // =========================================================================
    // FACTORY HELPERS
    // =========================================================================

    private function makeScenario(?string $bookingStatus): array
    {
        $this->seq++;
        $driver    = $this->makeUser("Driver-{$this->seq}");
        $passenger = $this->makeUser("Passenger-{$this->seq}");

        $this->makeProfile($driver);

        $ride    = $this->makeRide($driver);
        $booking = $bookingStatus !== null
            ? $this->makeBooking($ride, $passenger, $bookingStatus)
            : null;

        return compact('driver', 'passenger', 'ride', 'booking');
    }

    private function makeUser(string $label): User
    {
        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => $label,
            'email'         => strtolower("test.{$label}.{$this->seq}@test.local"),
            'password'      => $this->hash,
            'status'        => 1,
            'token_version' => 1,   // remove this line if the column doesn't exist
        ]);
    }

    private function makeProfile(User $user): Profile
    {
        return Profile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'description'     => null,
                'address'         => null,
                'gender'          => null,
                'type_of_car'     => null,
                'color_of_car'    => null,
                'number_of_seats' => null,
                'radio'           => false,
                'smoking'         => false,
                'number_of_rides' => 0,
            ]
        );
    }

    private function makeRide(User $driver): Ride
    {
        $this->opSeq++;
        $n = $this->opSeq;

        $rideId = DB::table('rides')->insertGetId([
            'driver_id'            => $driver->id,
            'pickup_address'       => 'Test Pickup — Damascus',
            'destination_address'  => 'Test Destination — Aleppo',
            'pickup_location'      => DB::raw("ST_GeomFromText('POINT(36.2765 33.5138)')"),
            'destination_location' => DB::raw("ST_GeomFromText('POINT(37.1343 36.2021)')"),
            'departure_time'       => now()->subHours(3),
            'status'               => 'finished',
            'available_seats'      => 3,
            'price_per_seat'       => 5000,
            'distance'             => 349.0,
            'duration'             => 210,
            'vehicle_type'         => 'sedan',
            'payment_method'       => 'cash',
            'booking_type'         => 'direct',
            'communication_number' => "091{$n}00001",
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return Ride::findOrFail($rideId);
    }

    private function makeBooking(Ride $ride, User $passenger, string $status): Booking
    {
        $this->opSeq++;
        $n = $this->opSeq;

        return Booking::create([
            'ride_id'              => $ride->id,
            'user_id'              => $passenger->id,
            'seats'                => 1,
            'status'               => $status,
            'communication_number' => "091{$n}00002",
        ]);
    }
}
