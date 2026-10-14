<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\AdminDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L23 (AdminDriverService::getDriverDashboard, characterization): pins the money figures the admin
 * driver dashboard reports before the method is split into helpers.
 *
 * Pins: ledger_earnings and total_earnings are the sum of COMPLETED ride-earning rows only, counting
 * both spellings (ride_earnings and ride_earning); estimated_gross is the seats x price x driver share
 * projection; and the stats key shape the admin front-end reads.
 */
class L23AdminDriverDashboardLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private AdminDriverService $service;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000.0);
        $this->service = app(AdminDriverService::class);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $wallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '09'.rand(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);
    }

    /** One ledger row for the driver, with the fields the dashboard reads. */
    private function ledgerRow(string $type, float $amount, string $status = 'completed'): void
    {
        WalletTransaction::create([
            'wallet_id' => $this->driver->wallet_id,
            'user_id' => $this->driver->id,
            'type' => $type,
            'amount' => $amount,
            'previous_balance' => 0,
            'new_balance' => $amount,
            'description' => 'L23 fixture',
            'transaction_id' => 'TX-'.Str::uuid(),
            'status' => $status,
            'reference' => 'L23:'.Str::uuid(),
        ]);
    }

    public function test_ledger_earnings_sums_completed_rows_of_both_ride_earning_spellings(): void
    {
        $this->ledgerRow('ride_earnings', 100.0);
        $this->ledgerRow('ride_earning', 50.0);
        $this->ledgerRow('ride_earnings', 25.5);

        $stats = $this->service->getDriverDashboard($this->driver->id)['stats'];

        $this->assertSame(175.5, $stats['ledger_earnings']);
        $this->assertSame(175.5, $stats['total_earnings']);
    }

    public function test_pending_ledger_rows_and_other_types_are_not_counted_as_earnings(): void
    {
        $this->ledgerRow('ride_earnings', 100.0);
        $this->ledgerRow('ride_earnings', 999.0, 'pending');
        $this->ledgerRow('deposit', 500.0);

        $stats = $this->service->getDriverDashboard($this->driver->id)['stats'];

        $this->assertSame(100.0, $stats['ledger_earnings']);
    }

    public function test_estimated_gross_is_seats_times_price_times_driver_share_for_completed_bookings(): void
    {
        $ride = RideBuilder::forUserId($this->driver->id)
            ->withAttributes(['status' => 'finished', 'price_per_seat' => 100.00])
            ->create();
        Booking::create([
            'user_id' => User::factory()->create()->id,
            'ride_id' => $ride->id,
            'seats' => 2,
            'status' => 'completed',
            'communication_number' => '0900000000',
        ]);

        $stats = $this->service->getDriverDashboard($this->driver->id)['stats'];

        // 2 seats x 100.00 x driver_share_rate (0.95 by default) = 190.0
        $this->assertSame(
            round(2 * 100.00 * (float) config('fees.driver_share_rate', 0.95), 2),
            $stats['estimated_gross']
        );
    }

    public function test_stats_keeps_the_key_shape_the_admin_front_end_reads(): void
    {
        $stats = $this->service->getDriverDashboard($this->driver->id)['stats'];

        $this->assertSame(
            ['total_rides', 'completed_rides', 'cancelled_rides', 'cancel_rate', 'ledger_earnings', 'estimated_gross', 'total_earnings'],
            array_keys($stats)
        );
    }

    public function test_a_driver_with_no_rows_reports_zero_earnings(): void
    {
        $stats = $this->service->getDriverDashboard($this->driver->id)['stats'];

        $this->assertSame(0.0, $stats['ledger_earnings']);
        $this->assertSame(0.0, $stats['total_earnings']);
    }
}
