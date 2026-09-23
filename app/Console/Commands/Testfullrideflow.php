<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserScore;
use App\Models\Wallet;
use App\Services\Ride\NoshowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
class Testfullrideflow extends Command
{
    protected $signature   = 'syride:test-noshow {--commit : Persist data instead of rolling back}';
    protected $description = 'End-to-end test: no-show reports, penalties, refunds, disputes, complaints';

    private int  $pass     = 0;
    private int  $fail     = 0;
    private bool $scenFail = false;

    /** bcrypt hash, computed once and reused across scenarios */
    private string $hash;

    /** Monotonic counter so every scenario gets unique emails / phone numbers */
    private int $seq = 0;

    public function handle(NoshowService $noshowService): int
    {
        $this->hash = Hash::make('test');

        $this->line('');
        $this->line('  ═══════════════════════════════════════════════════════');
        $this->line('    🧪 SyRide — No-Show System Test Suite');
        $this->line('    gate · penalties · refunds · disputes · complaints');
        $this->line('  ═══════════════════════════════════════════════════════');

        // ── S1: gate check ──────────────────────────────────────────────────
        $this->runScenario(
            '1 — Gate: cannot report before 1 h after departure',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->makeScenario('cash');

                $booking->ride->update(['departure_time' => now()->subMinutes(30)]);

                $gateBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $gateBlocked = true;
                }

                $this->check('Gate blocked (exception thrown)', true, $gateBlocked);
                $this->check('No NoshowReport row created',     0,    NoshowReport::where('booking_id', $booking->id)->count());
            }
        );

        // ── S2: passenger reports driver; driver −15 ─────────────────────────
        $this->runScenario(
            '2 — Cash: passenger reports driver no-show → driver −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $scoreBefore = $this->score($driver);

                $r = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('Report accepted (report_id present)',  true,      isset($r['report_id']));
                $this->check('Report status = pending',              'pending', NoshowReport::where('booking_id', $booking->id)->value('status'));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();
                $this->check('Scheduler resolved 1 report',     1,                        $resolved);
                $this->check('Report = resolved_reporter_wins', 'resolved_reporter_wins', NoshowReport::where('booking_id', $booking->id)->value('status'));
                $this->check('Booking status = no_show',        'no_show',                $booking->fresh()->status);
                $this->check('Driver score −15',                -15,                      $this->score($driver) - $scoreBefore);
            }
        );

        // ── S3: driver reports passenger; passenger −15 ───────────────────────
        $this->runScenario(
            '3 — Cash: driver reports passenger no-show → passenger −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $scoreBefore = $this->score($passenger);

                $r = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->check('Report accepted', true, isset($r['report_id']));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $this->check('Booking = no_show',   'no_show', $booking->fresh()->status);
                $this->check('Passenger score −15', -15,       $this->score($passenger) - $scoreBefore);
            }
        );

        // ── S4: both report → dispute → auto-complaint ────────────────────────
        $this->runScenario(
            '4 — Both report within 2 h → disputed + auto-complaint (type=no_show)',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $complaintsBefore = Complaint::count();

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('First report (passenger) accepted', true, isset($r1['report_id']));

                $r2 = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->check('Counter-report triggered conflict', true, $r2['conflict'] ?? false);

                $disputedCount = NoshowReport::where('booking_id', $booking->id)
                    ->where('status', 'disputed')
                    ->count();
                $this->check('At least 1 report = disputed', true, $disputedCount >= 1);

                $newComplaintCount = Complaint::count() - $complaintsBefore;
                $this->check('Complaint count +1', 1, $newComplaintCount);

                $complaint = Complaint::latest()->first();
                $this->check('Complaint type = no_show',  'no_show', $complaint->type);
                $this->check(
                    'Complaint user_id is a dispute party',
                    true,
                    in_array($complaint->user_id, [$driver->id, $passenger->id])
                );
            }
        );

        // ── S5: disputed → scheduler skips → no penalties ─────────────────────
        $this->runScenario(
            '5 — Disputed: scheduler skips; zero penalties applied',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $dScoreBefore = $this->score($driver);
                $pScoreBefore = $this->score($passenger);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $noshowService->reportPassengerNoShow($booking->id, $driver);

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();

                $this->check('Scheduler resolved 0 (disputed skipped)', 0, $resolved);
                $this->check('Driver score unchanged',                   0, $this->score($driver)    - $dScoreBefore);
                $this->check('Passenger score unchanged',                0, $this->score($passenger) - $pScoreBefore);
            }
        );

        // ── S6: e-pay — driver no-show → escrow back to passenger ─────────────
        $this->runScenario(
            '6 — E-pay: passenger reports driver no-show → escrow refunded to passenger',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $pBalanceBefore = (float) $passengerWallet->fresh()->balance;
                $dScoreBefore   = $this->score($driver);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $resolved = $noshowService->resolveExpiredReports();

                $this->check('Resolver processed 1 report (0 = wallet threw — check laravel.log)', 1, $resolved);

                $pBalanceAfter = (float) $passengerWallet->fresh()->balance;

                $this->check('Passenger balance increased (refund received)',
                    true,
                    $pBalanceAfter > $pBalanceBefore
                );
                $this->check('Refund amount matches booking cost',
                    round($escrowAmount, 2),
                    round($pBalanceAfter - $pBalanceBefore, 2)
                );
                $this->check('Driver score −15',  -15,       $this->score($driver) - $dScoreBefore);
                $this->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );

        // ── S7: e-pay — passenger no-show → escrow released to driver ─────────
        $this->runScenario(
            '7 — E-pay: driver reports passenger no-show → escrow released to driver',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $dBalanceBefore = (float) $driverWallet->fresh()->balance;
                $pScoreBefore   = $this->score($passenger);

                $noshowService->reportPassengerNoShow($booking->id, $driver);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $dBalanceAfter = (float) $driverWallet->fresh()->balance;

                $this->check('Driver balance increased (payment received)',
                    true,
                    $dBalanceAfter > $dBalanceBefore
                );
                $this->check('Driver receives ≥ 90% of fare',
                    true,
                    ($dBalanceAfter - $dBalanceBefore) >= $escrowAmount * 0.90
                );
                $this->check('Passenger score 0 (e-pay: wallet is the penalty)', 0, $this->score($passenger) - $pScoreBefore);
                $this->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );

        // ── S8: duplicate report by same role rejected ─────────────────────────
        $this->runScenario(
            '8 — Duplicate report by same role is rejected',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->makeScenario('cash');

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('First report accepted', true, isset($r1['report_id']));

                $dupBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $dupBlocked = true;
                }
                $this->check('Duplicate rejected (exception thrown)', true, $dupBlocked);
                $this->check('Still only 1 NoshowReport row',         1,    NoshowReport::where('booking_id', $booking->id)->count());
            }
        );

        // ── Summary ─────────────────────────────────────────────────────────────
        $this->line('');
        $this->line('  ═══════════════════════════════════════════════════════');
        $total  = $this->pass + $this->fail;
        $colour = $this->fail > 0 ? 'red' : 'green';
        $this->line("  <fg={$colour}>  RESULTS: {$this->pass}/{$total} passed" . ($this->fail ? ", {$this->fail} failed" : ' — all green ✅') . '</>');
        $this->line('  ═══════════════════════════════════════════════════════');

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

        $this->line('  └─ ' . ($this->scenFail ? '<fg=red>FAIL</>' : '<fg=green>PASS</>'));
    }

    // =========================================================================
    // ASSERTION HELPER
    // =========================================================================

    private function check(string $label, mixed $expected, mixed $actual): void
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
        if (is_bool($v))               return $v ? 'true' : 'false';
        if (is_null($v))               return 'null';
        if ($v instanceof \BackedEnum) return $v->value;
        if ($v instanceof \UnitEnum)   return strtolower($v->name);
        return (string) $v;
    }

    // =========================================================================
    // SCENARIO FACTORY
    // =========================================================================

    private function makeScenario(string $payment): array
    {
        $this->seq++;
        $n = $this->seq;

        $driver = User::forceCreate([
            'first_name'          => 'NS',
            'last_name'           => "Driver {$n}",
            'email'               => "ns.driver.{$n}@test.local",
            'password'            => $this->hash,
            'status'              => 1,
            'verification_status' => 'none',
            'token_version'       => 1,
        ]);

        $passenger = User::forceCreate([
            'first_name'          => 'NS',
            'last_name'           => "Passenger {$n}",
            'email'               => "ns.passenger.{$n}@test.local",
            'password'            => $this->hash,
            'status'              => 1,
            'verification_status' => 'none',
            'token_version'       => 1,
        ]);

        $rideId = DB::table('rides')->insertGetId([
            'driver_id'            => $driver->id,
            'pickup_address'       => 'Test Pickup — Damascus',
            'destination_address'  => 'Test Destination — Aleppo',
            'pickup_location'      => DB::raw("ST_GeomFromText('POINT(36.2765 33.5138)')"),
            'destination_location' => DB::raw("ST_GeomFromText('POINT(37.1343 36.2021)')"),
            'departure_time'       => now()->subHours(2),
            'status'               => 'launched',
            'available_seats'      => 2,
            'price_per_seat'       => 5000,
            'distance'             => 349.0,
            'duration'             => 240,
            'vehicle_type'         => 'sedan',
            'payment_method'       => $payment,
            'booking_type'         => 'direct',
            'communication_number' => "09100{$n}001",
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $ride = Ride::with('driver')->findOrFail($rideId);

        $bookingId = DB::table('bookings')->insertGetId([
            'ride_id'              => $ride->id,
            'user_id'              => $passenger->id,
            'seats'                => 1,
            'status'               => 'confirmed',
            'communication_number' => "09100{$n}002",
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $booking = Booking::with(['ride', 'user'])->findOrFail($bookingId);

        return compact('ride', 'booking', 'driver', 'passenger');
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

    private function setupWallets(User $driver, User $passenger, float $escrowAmount): array
    {
        $n = $this->seq;

        $adminWallet = null;

        // Roles and their config keys — must match what WalletTransactionService calls
        $roles = [
            'sycash'       => config('admin.sycash.phone'),
            'system_admin' => config('admin.system_admin.phone'),
        ];

        foreach ($roles as $role => $configPhone) {
            // ── Find the wallet by the SAME phone key the service uses ─────────
            $wallet = Wallet::where('phone_number', $configPhone)->first();

            if (! $wallet) {
                // Wallet not seeded yet — create one the service will find.
                // We need a user_id: try to resolve it from the employee record first.
                $employee  = Employee::where('role', $role)->orderBy('id')->first();
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
                        'first_name'          => 'Test',
                        'last_name'           => $role,
                        'email'               => "wallet.{$role}.{$n}@test.local",
                        'password'            => $this->hash,
                        'status'              => 1,
                        'verification_status' => 'none',
                        'token_version'       => 1,
                    ]);
                }

                $wallet = Wallet::create([
                    'user_id'      => $adminUser->id,
                    'phone_number' => $configPhone,
                    'balance'      => 0,
                ]);
            }

            // Top up so the service's assertSufficientBalance check passes.
            $wallet->increment('balance', $escrowAmount);
            $wallet->refresh();

            // sycash is the wallet actually debited by no-show settlements.
            if ($role === 'sycash' || $adminWallet === null) {
                $adminWallet = $wallet;
            }
        }

        // ── Driver wallet (empty — receives payment on passenger no-show) ──────
        $driverWallet = Wallet::create([
            'user_id'      => $driver->id,
            'phone_number' => "09100{$n}991",
            'balance'      => 0,
        ]);

        // ── Passenger wallet (empty — receives refund on driver no-show) ───────
        $passengerWallet = Wallet::create([
            'user_id'      => $passenger->id,
            'phone_number' => "09100{$n}992",
            'balance'      => 0,
        ]);

        return [$adminWallet, $driverWallet, $passengerWallet];
    }

    // =========================================================================
    // SCORE HELPER — returns 100 (starting score) if no record exists yet
    // =========================================================================

    private function score(User $user): int
    {
        return (int) (UserScore::where('user_id', $user->id)->value('score') ?? 100);
    }
}
