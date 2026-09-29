<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\AdminReportService;
use App\Services\JwtService;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Admin financial report — escrow-out accounting (T1-2 regression coverage).
 *
 * The live payout path (BookingService::passengerConfirmCompletion →
 * releaseEscrowToDriver) writes the SINGULAR 'escrow_release' type, one row
 * per confirmed booking. The report must therefore count that spelling;
 * otherwise total_escrow_out reports 0.00 forever while SyCash actually drains.
 *
 * The plural 'escrow_released' is retained because the legacy ride-wide
 * release wrote it, and historical rows must stay in the total.
 *
 * NOTE: the rides table uses SPATIAL indexes, so this suite requires MySQL —
 * it cannot run on the SQLite connection configured in phpunit.xml.
 */
class AdminFinancialReportEscrowTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $p1;

    private User $p2;

    private Wallet $driverWallet;

    private Wallet $syCash;

    private Wallet $primary;

    private string $p1Token;

    private string $p2Token;

    protected function setUp(): void
    {
        // RefreshDatabase migrates inside parent::setUp(), so this guard must run
        // first — otherwise the suite errors instead of skipping on SQLite.
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped(
                'Requires MySQL: the rides table uses SPATIAL indexes unsupported by SQLite.'
            );
        }

        parent::setUp();

        $this->syCash = Wallet::create([
            'user_id' => null, 'phone_number' => config('admin.sycash.phone'), 'balance' => 0,
        ]);
        $this->primary = Wallet::create([
            'user_id' => null, 'phone_number' => config('admin.system_admin.phone'), 'balance' => 0,
        ]);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driver->profile()->create(['full_name' => 'Driver', 'number_of_rides' => 0]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id, 'phone_number' => '0911'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $tokenService = app(JwtService::class);

        foreach ([['p1', '0922', 'p1Token'], ['p2', '0933', 'p2Token']] as [$prop, $prefix, $tokenProp]) {
            $u = User::factory()->create(['is_verified_passenger' => true]);
            $u->profile()->create(['full_name' => $prop, 'number_of_rides' => 0]);
            $w = Wallet::create([
                'user_id' => $u->id, 'phone_number' => $prefix.rand(100000, 999999),
                'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 1_000_000,
            ]);
            $u->update(['wallet_id' => $w->id]);
            $this->{$prop} = $u;
            $this->{$tokenProp} = $tokenService->generateTokenPair($u)['access_token'];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function book(Ride $ride, User $passenger): Booking
    {
        $booking = Booking::create([
            'user_id' => $passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => 'confirmed', 'communication_number' => '0900000000',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $passenger);

        return $booking;
    }

    private function financialStats(): array
    {
        return app(AdminReportService::class)
            ->generateReport(null, null)['financial_stats'];
    }

    // ── Escrow-out accounting ─────────────────────────────────────────────────

    public function test_report_counts_per_passenger_escrow_release(): void
    {
        $ride = $this->makeRide();
        $b1 = $this->book($ride, $this->p1);
        $b2 = $this->book($ride, $this->p2);

        // Both fares are escrowed; nothing released yet.
        $before = $this->financialStats();
        $this->assertStringContainsString('100,000.00', $before['sycash']['total_escrow_in']);
        $this->assertStringContainsString('0.00', $before['sycash']['total_escrow_out']);

        // Passenger 1 confirms: 50,000 leaves escrow.
        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(200);

        $afterFirst = $this->financialStats();
        $this->assertStringContainsString(
            '50,000.00',
            $afterFirst['sycash']['total_escrow_out'],
            'the report must reflect the escrow actually released for p1'
        );

        // Passenger 2 confirms: escrow fully drained.
        $this->withToken($this->p2Token)
            ->postJson("/api/bookings/{$b2->id}/passenger-confirm")
            ->assertStatus(200);

        $afterBoth = $this->financialStats();
        $this->assertStringContainsString(
            '100,000.00',
            $afterBoth['sycash']['total_escrow_out'],
            'the report must reflect all escrow released'
        );

        // Escrow in and escrow out now reconcile, and SyCash is genuinely empty.
        $this->assertEquals(0.0, (float) $this->syCash->fresh()->balance);
        $this->assertEquals(95_000.0, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_report_out_matches_sycash_balance_drop(): void
    {
        $ride = $this->makeRide();
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(200);

        // The report's escrow-out figure must equal what actually left SyCash.
        $stats = $this->financialStats();
        $out = (float) str_replace([' ', 'SYP', ','], '', $stats['sycash']['total_escrow_out']);
        $dropped = 50_000.0 - (float) $this->syCash->fresh()->balance;

        $this->assertEquals($dropped, $out);
    }

    public function test_legacy_plural_escrow_released_rows_still_counted(): void
    {
        // Historical rows written by the retired ride-wide release must not
        // disappear from the total just because the live path changed spelling.
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $this->syCash->id,
            'user_id' => null,
            'type' => 'escrow_released',
            'amount' => -12_345.00,
            'previous_balance' => 12_345.00,
            'new_balance' => 0.00,
            'description' => 'legacy ride-wide release',
            'transaction_id' => 'SYCASH_LEGACY_'.Str::random(8),
            'status' => 'completed',
            'reference' => 'ride:999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = $this->financialStats();
        $this->assertStringContainsString('12,345.00', $stats['sycash']['total_escrow_out']);
    }

    public function test_no_payout_means_no_escrow_out(): void
    {
        $ride = $this->makeRide();
        $this->book($ride, $this->p1);

        // Booked but never confirmed — escrow stays put.
        $stats = $this->financialStats();
        $this->assertStringContainsString('50,000.00', $stats['sycash']['total_escrow_in']);
        $this->assertStringContainsString('0.00', $stats['sycash']['total_escrow_out']);
        $this->assertEquals(50_000.0, (float) $this->syCash->fresh()->balance);
    }
}
