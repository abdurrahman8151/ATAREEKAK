<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ride\BookingService;
use App\Support\GeoPoint;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY test command — never ship to production.
 *
 * Usage:
 *   php artisan syride:test-completion               # cash, 2 passengers, rolls back
 *   php artisan syride:test-completion --passengers=3
 *   php artisan syride:test-completion --payment=e-pay
 *   php artisan syride:test-completion --commit       # commits data so you can inspect in DB
 */
class Testridecompletionflow extends Command
{
    use GuardsAgainstProductionExecution; // AF-4

    protected $signature = 'syride:test-completion
                            {--payment=cash     : Payment method to test — cash or e-pay}
                            {--passengers=2     : Number of passengers to simulate (1–4)}
                            {--commit           : Commit data to DB instead of rolling back}';

    protected $description = '[DEV] Test the passenger-driven ride completion flow end-to-end';

    private ?int $rideId = null;

    private array $bookingIds = [];

    private array $userIds = [];

    // =========================================================================
    // MAIN
    // =========================================================================

    public function handle(BookingService $bookingService): int
    {
        $payment = $this->option('payment');
        $numPass = min(4, max(1, (int) $this->option('passengers')));
        $doCommit = (bool) $this->option('commit');

        $this->banner($payment, $numPass);

        DB::beginTransaction();

        try {
            $this->info('  [1/3] Creating test scenario…');
            $driver = $this->makeUser('Driver');
            $passengers = array_map(fn ($i) => $this->makeUser("Passenger {$i}"), range(1, $numPass));
            $ride = $this->makeRide($driver, $payment, $numPass);
            $bookings = array_map(fn ($p) => $this->makeBooking($ride, $p), $passengers);

            $this->printState('INITIAL STATE', $ride->fresh(), array_map(fn ($b) => $b->fresh(), $bookings));

            $this->info("\n  [2/3] Running confirmations…");
            $allOk = true;

            foreach ($passengers as $i => $passenger) {
                $n = $i + 1;
                $booking = $bookings[$i];

                $this->line("\n  <fg=cyan>── Passenger {$n} confirms (".$passenger->first_name.' '.$passenger->last_name.') ──────────────────</>');

                try {
                    $result = $bookingService->passengerConfirmCompletion($booking->id, $passenger);

                    $icon = $result['ride_finished'] ? '🏁' : '✅';
                    $label = $result['ride_finished'] ? '<fg=blue>YES — ride finished</>' : '<fg=yellow>NO — more passengers pending</>';
                    $this->line("  {$icon} {$result['message']}");
                    $this->line("     ride_complete → {$label}");
                } catch (\Throwable $e) {
                    $this->error("\n  ✗ passengerConfirmCompletion() THREW:");
                    $this->error('    '.get_class($e).': '.$e->getMessage());
                    $this->line('    File: '.$e->getFile().':'.$e->getLine());
                    $this->line("\n  Stack trace:");
                    foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 10) as $frame) {
                        $this->line("    {$frame}");
                    }
                    $allOk = false;
                    break;
                }

                $this->printState(
                    "STATE AFTER PASSENGER {$n}",
                    $ride->fresh(),
                    array_map(fn ($b) => $b->fresh(), $bookings)
                );
            }

            $this->line("\n  [3/3] Final check…");
            $this->printSummary($ride->fresh(), array_map(fn ($b) => $b->fresh(), $bookings));

            if ($doCommit) {
                DB::commit();
                $this->newline();
                $this->components->info('Data committed. To clean up:');
                $this->line('  DELETE FROM bookings WHERE id IN ('.implode(', ', $this->bookingIds).');');
                $this->line('  DELETE FROM rides    WHERE id  = '.$this->rideId.';');
                $this->line('  DELETE FROM users    WHERE id IN ('.implode(', ', $this->userIds).');');
            } else {
                DB::rollBack();
                $this->newline();
                $this->components->info('Transaction rolled back — DB untouched. Use --commit to persist.');
            }

            return $allOk ? self::SUCCESS : self::FAILURE;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Test setup failed: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function makeUser(string $label): User
    {
        $slug = strtolower(str_replace(' ', '_', $label)).'_'.now()->timestamp;

        try {
            $user = User::factory()->create([
                'first_name' => 'Test',
                'last_name' => $label,
            ]);
        } catch (\Throwable) {
            $user = User::create([
                'first_name' => 'Test',
                'last_name' => $label,
                'email' => "{$slug}@syride.test",
                'password' => bcrypt('password'),
            ]);
        }

        $this->userIds[] = $user->id;
        $this->line("    👤 {$label} → ID {$user->id}");

        return $user;
    }

    private function makeRide(User $driver, string $payment, int $seats): Ride
    {
        // DB::table bypasses $fillable so spatial/raw expressions always land in SQL.
        $now = now();

        // Damascus → Aleppo straight-line ≈ 349 km / ~3.5 h.
        // Adjust units below if your migration stores metres or seconds instead.
        $rideId = DB::table('rides')->insertGetId([
            'driver_id' => $driver->id,
            'pickup_address' => 'Test Pickup — Damascus',
            'destination_address' => 'Test Destination — Aleppo',

            // Spatial POINT columns. RV-25: the shared GeoPoint writer emits POINT(lat lng)
            // (latitude first), matching MySQL's EPSG:4326 axis-order and the production
            // convention. These are the same real cities (Damascus / Aleppo).
            'pickup_location' => GeoPoint::fromLatLng(33.5138, 36.2765)->raw(),
            'destination_location' => GeoPoint::fromLatLng(36.2021, 37.1343)->raw(),

            // ── Columns that are NOT NULL with no default ──────────────────────
            // Change the unit comments if your schema differs.
            'distance' => 349.0,          // km
            'duration' => 210,            // minutes (3 h 30 m) — remove if your column doesn't exist
            // 'route'              => DB::raw("ST_GeomFromText('LINESTRING(36.2765 33.5138, 37.1343 36.2021)')"),
            //   ↑ Uncomment if your rides table has a NOT NULL 'route' geometry column.

            'departure_time' => $now->copy()->subHours(3),
            'status' => RideStatus::ACTIVE->value,
            'available_seats' => $seats,
            'price_per_seat' => 5000,
            'vehicle_type' => 'sedan',
            'payment_method' => $payment,
            'booking_type' => BookingType::DIRECT->value,
            'communication_number' => '0910000001',
            'created_at' => $now,
            'updated_at' => $now,

            // ⚠ If you still hit "doesn't have a default value" for another column,
            //   add it here and tell me its name — I'll set the right dummy value.
        ]);

        $ride = Ride::findOrFail($rideId);

        $this->rideId = $rideId;
        $this->line("    🚗 Ride      → ID {$ride->id} | departure: {$ride->departure_time} | status: {$ride->status}");

        return $ride;
    }

    private function makeBooking(Ride $ride, User $passenger): Booking
    {
        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => BookingStatus::CONFIRMED->value,
            'communication_number' => '0910000001',
            // ⚠ Add other required booking columns here if you get a DB error.
        ]);

        $this->bookingIds[] = $booking->id;
        $this->line("    📋 Booking   → ID {$booking->id} | passenger #{$passenger->id}");

        return $booking;
    }

    // =========================================================================
    // DISPLAY HELPERS
    // =========================================================================

    private function printState(string $title, Ride $ride, array $bookings): void
    {
        $this->newline();
        $this->line("  <fg=yellow>▶ {$title}</>");

        $rc = match ($ride->status) {
            'active' => 'green',
            'full' => 'cyan',
            'launched' => 'yellow',
            'finished' => 'blue',
            'cancelled' => 'red',
            default => 'white',
        };
        $this->line("    Ride #{$ride->id}: <fg={$rc}>{$ride->status}</>");

        foreach ($bookings as $b) {
            $bc = $b->status === 'completed' ? 'green' : ($b->status === 'confirmed' ? 'yellow' : 'red');
            $confirmed = $b->completed_at
                ? '<fg=green>'.Carbon::parse($b->completed_at)->format('H:i:s').'</>'
                : '<fg=gray>null</>';
            $this->line("    Booking #{$b->id} (pax #{$b->user_id}): <fg={$bc}>{$b->status}</>  confirmed_at: {$confirmed}");
        }
    }

    private function printSummary(Ride $ride, array $bookings): void
    {
        $this->newline();
        $this->line('  ═══════════════════════════════════════════════════════');
        $this->line('    PASS / FAIL SUMMARY');
        $this->line('  ═══════════════════════════════════════════════════════');

        $rideOk = $ride->status === RideStatus::FINISHED->value;
        $this->line('    Ride status = '.$ride->status.'  '.($rideOk ? '✅ PASS' : '❌ FAIL — expected: finished'));

        foreach ($bookings as $b) {
            $statusOk = $b->status === BookingStatus::COMPLETED->value;
            $confirmedOk = $b->completed_at !== null;
            $ok = $statusOk && $confirmedOk;
            $this->line("    Booking #{$b->id}: status={$b->status}  confirmed_at={$b->completed_at}  ".($ok ? '✅ PASS' : '❌ FAIL'));
        }

        $this->line('  ═══════════════════════════════════════════════════════');
    }

    private function banner(string $payment, int $n): void
    {
        $this->newline();
        $this->line('  <fg=magenta>═══════════════════════════════════════════════════════</>');
        $this->line('  <fg=magenta>  🧪 SyRide — Ride Completion Flow Test</>');
        $this->line("  <fg=magenta>  payment={$payment}  passengers={$n}</>");
        $this->line('  <fg=magenta>═══════════════════════════════════════════════════════</>');
        $this->newline();
    }
}
