<?php

namespace Tests\Feature\T3Batch;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use App\Services\Ride\Noshowservice;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * T3 batch — money-path cluster:
 *   T3-1  charge/approve transaction ids must be collision-free (UUID, not timestamp)
 *   T3-15 pending-withdraw guard must lock the wallet (no over-commit race)
 *   T3-16 no-show resolution must be idempotent under re-invocation (row lock + status recheck)
 *
 * Requires MySQL (rides table uses SPATIAL indexes; the settlement paths use
 * lockForUpdate, which is a no-op on SQLite's single-writer model).
 */
class MoneyPathBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

    private Wallet $syCash;

    private Wallet $primary;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: SPATIAL ride indexes + lockForUpdate semantics.');
        }
        parent::setUp();

        $this->syCash = Wallet::create(['user_id' => null, 'phone_number' => config('admin.sycash.phone'), 'balance' => 0]);
        $this->primary = Wallet::create(['user_id' => null, 'phone_number' => config('admin.system_admin.phone'), 'balance' => 0]);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driver->profile()->create(['full_name' => 'D', 'number_of_rides' => 0]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id, 'phone_number' => '0911'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id, 'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 1000000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    private function makeRide(array $o = []): Ride
    {
        // RV-34: shared builder. Values from the previous fixture: seats 3
        // (overridable), price 50000, e-pay/direct/active (overridable),
        // distance 320.5, duration 240, communication 0911000000.
        return RideBuilder::for($this->driver)
            ->withAttributes(array_merge([
                'available_seats' => 3,
                'price_per_seat' => 50000,
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0911000000',
            ], $o))
            ->departureTime($o['departure_time'] ?? now()->subMinutes(5))
            ->create();
    }

    private function escrowedBooking(): array
    {
        $ride = $this->makeRide();
        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => 'confirmed', 'communication_number' => '0900000000',
        ]);
        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $this->passenger);

        return [$ride, $booking];
    }

    // ── T3-1 ────────────────────────────────────────────────────────────────
    public function test_two_charges_in_the_same_second_do_not_collide(): void
    {
        // Freeze the clock so both charges compute an IDENTICAL now()->timestamp.
        // Pre-fix the id was 'ADM-'.user->id.'-'.timestamp -> the second insert
        // hit the UNIQUE transaction_id and the whole charge rolled back (500).
        Carbon::setTestNow(Carbon::now());

        $admin = $this->systemAdmin();

        $this->withToken($admin)
            ->postJson("/api/admin/passengers/{$this->passenger->id}/charge-wallet", ['amount' => 4000])
            ->assertStatus(200);

        $this->withToken($admin)
            ->postJson("/api/admin/passengers/{$this->passenger->id}/charge-wallet", ['amount' => 5000])
            ->assertStatus(200);

        $ids = WalletTransaction::where('reference', "admin_charge:{$this->passenger->id}")
            ->pluck('transaction_id')->all();

        $this->assertCount(2, $ids, 'both charges must persist (pre-fix the same-second collision rolled one back)');
        $this->assertCount(2, array_unique($ids), 'transaction ids must be distinct');
        // setUp seeded this wallet with 1,000,000; both charges (4000 + 5000)
        // must have landed — pre-fix the second one rolled back.
        $this->assertSame(1009000.0, (float) $this->passengerWallet->fresh()->balance);

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^ADM-\d+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
        }

        Carbon::setTestNow();
    }

    // ── T3-15 ────────────────────────────────────────────────────────────────
    public function test_pending_withdraw_guard_blocks_over_commit(): void
    {
        // balance 1_000_000 is shared, but this passenger wallet starts at 1M;
        // request half, then more than the remainder.
        $svc = app(WalletRequestService::class);
        $this->passengerWallet->update(['balance' => 1000]);

        $svc->requestWithdraw($this->passenger->fresh(), 600.0); // ok: 600 <= 1000

        // A second withdraw of 600 would put 1200 pending against a 1000 balance.
        $this->expectException(\DomainException::class);
        $svc->requestWithdraw($this->passenger->fresh(), 600.0);
    }

    public function test_withdraw_exceeding_balance_is_rejected(): void
    {
        $this->passengerWallet->update(['balance' => 500]);
        $svc = app(WalletRequestService::class);

        $this->expectException(\DomainException::class);
        $svc->requestWithdraw($this->passenger->fresh(), 501.0);
    }

    public function test_the_withdraw_guard_actually_locks_the_wallet_row(): void
    {
        // The sequential tests above pass pre-fix too (the check logic is the
        // same); what the fix changed is WHERE the check runs and whether the
        // wallet row is locked. Observing the real SQL is the faithful
        // instrument: a SELECT ... FOR UPDATE on wallets must appear inside
        // the transaction. Pre-fix: none exists, so this fails.
        $captured = [];
        DB::listen(function ($q) use (&$captured) {
            $captured[] = strtolower($q->sql);
        });

        app(WalletRequestService::class)->requestWithdraw($this->passenger->fresh(), 100.0);

        $locking = array_filter(
            $captured,
            fn (string $sql): bool => str_contains($sql, 'from `wallets`') && str_contains($sql, 'for update')
        );

        $this->assertNotEmpty(
            $locking,
            'the pending-withdraw guard must read the wallet under FOR UPDATE (T3-15)'
        );
    }

    // ── T3-16 ────────────────────────────────────────────────────────────────
    public function test_no_show_resolution_is_idempotent_under_re_invocation(): void
    {
        [$ride, $booking] = $this->escrowedBooking(); // escrow 50000 in SyCash

        $report = NoshowReport::create([
            'ride_id' => $ride->id, 'booking_id' => $booking->id,
            'reporter_id' => $this->driver->id, 'reporter_role' => 'driver',
            'target_id' => $this->passenger->id, 'target_role' => 'passenger',
            'payment_method' => 'e-pay', 'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $svc = app(Noshowservice::class);

        $first = $svc->resolveExpiredReports();
        $driverBalAfterFirst = (float) $this->driverWallet->fresh()->balance;

        $this->assertSame(1, $first, 'exactly one report resolved');
        $this->assertSame('resolved_reporter_wins', $report->fresh()->status);
        $this->assertGreaterThan(0, $driverBalAfterFirst, 'driver actually paid');

        // Second invocation must be a no-op: the status re-check under the row
        // lock now rejects the already-resolved report (pre-fix it was fetched
        // outside the txn and re-applied).
        $second = $svc->resolveExpiredReports();
        $this->assertSame(0, $second, 'no report re-resolved');
        $this->assertSame(
            $driverBalAfterFirst,
            (float) $this->driverWallet->fresh()->balance,
            'no double payout'
        );
        $this->assertSame(
            1,
            WalletTransaction::where('reference', "booking:{$booking->id}")
                ->where('type', 'passenger_no_show_earning')->count(),
            'exactly one earning ledger row for the booking'
        );
    }

    public function test_resolution_skips_a_disputed_report(): void
    {
        [$ride, $booking] = $this->escrowedBooking();
        NoshowReport::create([
            'ride_id' => $ride->id, 'booking_id' => $booking->id,
            'reporter_id' => $this->driver->id, 'reporter_role' => 'driver',
            'target_id' => $this->passenger->id, 'target_role' => 'passenger',
            'payment_method' => 'e-pay', 'status' => 'disputed',
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, app(Noshowservice::class)->resolveExpiredReports());
        $this->assertSame(0.0, (float) $this->driverWallet->fresh()->balance, 'no money moved for a disputed report');
    }

    public function test_the_penalty_is_locked_before_any_money_moves(): void
    {
        // Sequential idempotency above also held pre-fix (the fetch filters on
        // status='pending'). What the fix changed is the transaction protocol:
        // the report row is re-read FOR UPDATE and the penalty only then runs.
        // True concurrent invocation is not observable here (RefreshDatabase
        // wraps every connection in a transaction, so a second connection
        // cannot see the fixtures), so the faithful instrument is the real
        // emitted SQL: the lock must appear BEFORE the first ledger insert.
        $captured = [];
        DB::listen(function ($q) use (&$captured) {
            $captured[] = strtolower($q->sql);
        });

        [$ride, $booking] = $this->escrowedBooking();
        $captured = []; // ignore the escrow-charge SQL

        NoshowReport::create([
            'ride_id' => $ride->id, 'booking_id' => $booking->id,
            'reporter_id' => $this->driver->id, 'reporter_role' => 'driver',
            'target_id' => $this->passenger->id, 'target_role' => 'passenger',
            'payment_method' => 'e-pay', 'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        app(Noshowservice::class)->resolveExpiredReports();

        $lockAt = null;
        $firstTx = null;
        foreach ($captured as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from `noshow_reports`') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($firstTx === null && str_contains($sql, 'insert into `wallet_transactions`')) {
                $firstTx = $i;
            }
        }

        $this->assertNotNull($lockAt, 'the resolver must lock the report row FOR UPDATE (T3-16)');
        $this->assertNotNull($firstTx, 'test sanity: the settlement must have written a ledger row');
        $this->assertLessThan(
            $firstTx,
            $lockAt,
            'the report lock must be taken BEFORE any money moves, not after'
        );
    }

    // ── helper ────────────────────────────────────────────────────────────────
    private function systemAdmin(): string
    {
        $e = Employee::create([
            'username' => 'sysadmin_'.uniqid(), 'email' => 'sa_'.uniqid().'@t.com',
            'password' => 'Password123!', 'first_name' => 'S', 'last_name' => 'A',
            'role' => 'system_admin', 'is_active' => true, 'token_version' => 0,
        ]);

        return (string) $this->postJson('/api/admin/login', [
            'username' => $e->username, 'password' => 'Password123!',
        ])->json('tokens.access_token');
    }
}
