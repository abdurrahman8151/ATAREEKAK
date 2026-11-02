<?php

namespace App\Console\Testing;

use App\Console\Commands\GuardsAgainstProductionExecution;
use App\Console\Testing\Support\FullRideFlowScenarios;
use App\Console\Testing\Support\RideFlowHarness;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserScore;
use App\Models\Wallet;
use App\Modules\Ride\Services\Noshowservice;
use App\Support\GeoPoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
/**
 * PLACE IN:  app/Console/Commands/TestNoshowFlow.php
 *
 * Run (dry, DB rolled back):
 *   docker exec -it syride_app1 php artisan syride:test-noshow
 *
 * Run (commit to DB, inspect afterwards):
 *   docker exec -it syride_app1 php artisan syride:test-noshow --commit
 *
 * Scenarios covered:
 *   S1  Gate check — cannot report before 1 h after departure
 *   S2  Cash: passenger reports driver no-show → driver −15 pts
 *   S3  Cash: driver reports passenger no-show → passenger −15 pts
 *   S4  Both report within 2 h window → disputed + auto-complaint (type = no_show)
 *   S5  Disputed reports → scheduler skips them → nobody penalised
 *   S6  E-pay: passenger reports driver → escrow refunded to passenger + driver −15
 *   S7  E-pay: driver reports passenger -> escrow released to driver + passenger 0 pts
 *   S8  Duplicate report by same role rejected
 */
use Illuminate\Support\Facades\Hash;

class Testfullrideflow extends Command implements RideFlowHarness
{
    private ?FullRideFlowScenarios $scenarios = null;

    /**
     * The scenarios live in their own class and reach the command's helpers through the
     * RideFlowHarness interface; the command hands itself in as that harness. Built lazily so the
     * command keeps its parent constructor (which registers the signature) untouched.
     */
    private function scenarios(): FullRideFlowScenarios
    {
        return $this->scenarios ??= new FullRideFlowScenarios($this);
    }

    use GuardsAgainstProductionExecution; // AF-4

    protected $signature = 'syride:test-noshow {--commit : Persist data instead of rolling back}';

    /** AF-3: hidden from artisan list; still runnable by name (developer harness, refuses production). */
    protected $hidden = true;

    protected $description = 'End-to-end test: no-show reports, penalties, refunds, disputes, complaints';

    private int $pass = 0;

    private int $fail = 0;

    private bool $scenFail = false;

    /** bcrypt hash, computed once and reused across scenarios */
    private string $hash;

    /** Monotonic counter so every scenario gets unique emails / phone numbers */
    private int $seq = 0;

    public function handle(Noshowservice $noshowService): int
    {
        $this->hash = Hash::make('test');

        $this->renderHeader();

        // ── S1: gate check ──────────────────────────────────────────────────
        $this->scenarios()->scenarioGateBeforeOneHour($noshowService);

        // ── S2: passenger reports driver; driver −15 ─────────────────────────
        $this->scenarios()->scenarioCashPassengerReportsDriver($noshowService);

        // ── S3: driver reports passenger; passenger −15 ───────────────────────
        $this->scenarios()->scenarioCashDriverReportsPassenger($noshowService);

        // ── S4: both report → dispute → auto-complaint ────────────────────────
        $this->scenarios()->scenarioBothReportDispute($noshowService);

        // ── S5: disputed → scheduler skips → no penalties ─────────────────────
        $this->scenarios()->scenarioDisputedSkipsPenalties($noshowService);

        // ── S6: e-pay — driver no-show → escrow back to passenger ─────────────
        $this->scenarios()->scenarioEPayDriverNoShowRefund($noshowService);

        // ── S7: e-pay — passenger no-show → escrow released to driver ─────────
        $this->scenarios()->scenarioEPayPassengerNoShowRelease($noshowService);

        // ── S8: duplicate report by same role rejected ─────────────────────────
        $this->scenarios()->scenarioDuplicateReportRejected($noshowService);

        // ── Summary ─────────────────────────────────────────────────────────────
        $this->renderSummary();

        return $this->fail > 0 ? 1 : 0;
    }

    private function renderHeader(): void
    {
        $this->line('');
        $this->line('  ═══════════════════════════════════════════════════════');
        $this->line('    🧪 SyRide — No-Show System Test Suite');
        $this->line('    gate · penalties · refunds · disputes · complaints');
        $this->line('  ═══════════════════════════════════════════════════════');
    }

    private function renderSummary(): void
    {
        $this->line('');
        $this->line('  ═══════════════════════════════════════════════════════');
        $total = $this->pass + $this->fail;
        $colour = $this->fail > 0 ? 'red' : 'green';
        $this->line("  <fg={$colour}>  RESULTS: {$this->pass}/{$total} passed".($this->fail ? ", {$this->fail} failed" : ' — all green ✅').'</>');
        $this->line('  ═══════════════════════════════════════════════════════');

        if ($this->option('commit')) {
            $this->info('  Committed — check the DB.');
        } else {
            $this->info('  All transactions rolled back — DB untouched. Use --commit to persist.');
        }
    }

    // =========================================================================
    // SCENARIO RUNNER
    // =========================================================================

    public function runScenario(string $name, callable $fn): void
    {
        $this->line("\n  ┌─ {$name}");
        $this->scenFail = false;
        DB::beginTransaction();

        try {
            $fn();
        } catch (\Throwable $e) {
            $this->line("  │  💥 THREW: {$e->getMessage()}");
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

    public function check(string $label, mixed $expected, mixed $actual): void
    {
        $norm = fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \UnitEnum ? strtolower($v->name) : $v);
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
        if ($v instanceof \UnitEnum) {
            return strtolower($v->name);
        }

        return (string) $v;
    }

    // =========================================================================
    // SCENARIO FACTORY
    // =========================================================================

    public function makeScenario(string $payment): array
    {
        $this->seq++;
        $n = $this->seq;

        [$driver, $passenger] = $this->makeScenarioUsers($n);

        $ride = $this->makeScenarioRide($driver, $payment, $n);

        $booking = $this->makeScenarioBooking($ride, $passenger, $n);

        return compact('ride', 'booking', 'driver', 'passenger');
    }

    /**
     * Create the scenario's driver and passenger rows.
     */
    private function makeScenarioUsers(int $n): array
    {
        $driver = User::forceCreate([
            'first_name' => 'NS',
            'last_name' => "Driver {$n}",
            'email' => "ns.driver.{$n}@test.local",
            'password' => $this->hash,
            'status' => 1,
            'verification_status' => 'none',
            'token_version' => 1,
        ]);

        $passenger = User::forceCreate([
            'first_name' => 'NS',
            'last_name' => "Passenger {$n}",
            'email' => "ns.passenger.{$n}@test.local",
            'password' => $this->hash,
            'status' => 1,
            'verification_status' => 'none',
            'token_version' => 1,
        ]);

        return [$driver, $passenger];
    }

    /**
     * Create the scenario ride (raw insert so spatial columns always land).
     */
    private function makeScenarioRide(User $driver, string $payment, int $n): Ride
    {
        $rideId = DB::table('rides')->insertGetId([
            'driver_id' => $driver->id,
            'pickup_address' => 'Test Pickup — Damascus',
            'destination_address' => 'Test Destination — Aleppo',
            // RV-25: shared GeoPoint writer emits POINT(lat lng) (latitude first) — same real
            // cities (Damascus / Aleppo), matching the production convention.
            'pickup_location' => GeoPoint::fromLatLng(33.5138, 36.2765)->raw(),
            'destination_location' => GeoPoint::fromLatLng(36.2021, 37.1343)->raw(),
            'departure_time' => now()->subHours(2),
            'status' => 'launched',
            'available_seats' => 2,
            'price_per_seat' => 5000,
            'distance' => 349.0,
            'duration' => 240,
            'vehicle_type' => 'sedan',
            'payment_method' => $payment,
            'booking_type' => 'direct',
            'communication_number' => "09100{$n}001",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ride = Ride::with('driver')->findOrFail($rideId);

        return $ride;
    }

    /**
     * Create the scenario booking for the passenger.
     */
    private function makeScenarioBooking(Ride $ride, User $passenger, int $n): Booking
    {
        $bookingId = DB::table('bookings')->insertGetId([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => 'confirmed',
            'communication_number' => "09100{$n}002",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $booking = Booking::with(['ride', 'user'])->findOrFail($bookingId);

        return $booking;
    }

    // =========================================================================
    // WALLET SETUP (e-pay scenarios only)
    //
    // ROOT CAUSE (definitive)
    // ────────────────────────
    // WalletTransactionService resolves ALL admin wallets via:
    //
    //   lockWalletByPhone(config('admin.sycash.phone'))
    //   lockWalletByPhone(config('admin.system_admin.phone'))
    //
    // Previous versions of setupWallets() looked up wallets by USER_ID, which
    // produced a completely different row from the one the service reads.
    // The service found the real wallet (correct phone) but with zero balance.
    //
    // THE FIX
    // ───────
    // Look up each admin wallet by the same config phone key the service uses.
    // If that wallet doesn't yet exist, create it (with that phone) so the
    // service can find it. Then increment its balance so the service passes the
    // assertSufficientBalance guard.
    //
    // Both increments run inside the scenario's outer DB::beginTransaction()
    // so they roll back automatically — the real DB is never permanently changed.
    //
    // Returns [$adminWallet, $driverWallet, $passengerWallet].
    // $adminWallet is the sycash wallet (the one actually debited for no-shows).
    // =========================================================================

    public function setupWallets(User $driver, User $passenger, float $escrowAmount): array
    {
        $n = $this->seq;

        $adminWallet = null;

        // Roles and their config keys — must match what WalletTransactionService calls
        $roles = [
            'sycash' => config('admin.sycash.phone'),
            'system_admin' => config('admin.system_admin.phone'),
        ];

        foreach ($roles as $role => $configPhone) {
            $wallet = $this->resolveAdminWallet($role, $configPhone, $escrowAmount, $n);

            // sycash is the wallet actually debited by no-show settlements.
            if ($role === 'sycash' || $adminWallet === null) {
                $adminWallet = $wallet;
            }
        }
        // ── Driver wallet (empty — receives payment on passenger no-show) ──────
        $driverWallet = Wallet::create([
            'user_id' => $driver->id,
            'phone_number' => "09100{$n}991",
            'balance' => 0,
        ]);

        // ── Passenger wallet (empty — receives refund on driver no-show) ───────
        $passengerWallet = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => "09100{$n}992",
            'balance' => 0,
        ]);

        return [$adminWallet, $driverWallet, $passengerWallet];
    }

    /**
     * Find (or create) the admin wallet the settlement service looks up by phone,
     * then top it up so assertSufficientBalance passes.
     */
    private function resolveAdminWallet(string $role, $configPhone, float $escrowAmount, int $n): Wallet
    {
        // ── Find the wallet by the SAME phone key the service uses ─────────
        $wallet = Wallet::where('phone_number', $configPhone)->first();

        if (! $wallet) {
            // Wallet not seeded yet — create one the service will find.
            // We need a user_id: try to resolve it from the employee record first.
            $employee = Employee::where('role', $role)->orderBy('id')->first();
            $adminUser = null;

            if ($employee?->user_id) {
                $adminUser = User::find($employee->user_id);
            }
            if (! $adminUser && ! empty($employee?->email)) {
                $adminUser = User::where('email', $employee->email)->first();
            }
            if (! $adminUser) {
                // No employee row exists either — create a shadow user just
                // to satisfy the wallets.user_id FK.
                $adminUser = User::forceCreate([
                    'first_name' => 'Test',
                    'last_name' => $role,
                    'email' => "wallet.{$role}.{$n}@test.local",
                    'password' => $this->hash,
                    'status' => 1,
                    'verification_status' => 'none',
                    'token_version' => 1,
                ]);
            }

            $wallet = Wallet::create([
                'user_id' => $adminUser->id,
                'phone_number' => $configPhone,
                'balance' => 0,
            ]);
        }

        // Top up so the service's assertSufficientBalance check passes.
        $wallet->increment('balance', $escrowAmount);
        $wallet->refresh();

        return $wallet;
    }

    // =========================================================================
    // SCORE HELPER — returns 100 (starting score) if no record exists yet
    // =========================================================================

    public function score(User $user): int
    {
        return (int) (UserScore::where('user_id', $user->id)->value('score') ?? 100);
    }
}
