<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Services\BookingCharge;
use App\Services\Ride\Noshowservice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L27 (WalletTransactionService::processPassengerNoShow, characterization): pins the money effect of a
 * passenger no-show reported through the DIRECT path (Noshowservice::reportPassengerNoShow), not the
 * expiry path that MoneyPathBatchTest already pins. Before the no-show money methods are split, this
 * proves the escrow is released to the driver and exactly one passenger_no_show_earning row is written.
 */
class L27PassengerNoShowDirectPathTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(0.0);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '0911'.random_int(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0922'.random_int(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 100000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    public function test_a_direct_passenger_no_show_report_pays_the_escrow_to_the_driver_and_writes_one_earning_row(): void
    {
        // The no-show window only opens after departure, so the ride is in the past.
        $ride = RideBuilder::forUserId($this->driver->id)
            ->withAttributes(['departure_time' => now()->subHours(2), 'status' => 'active'])
            ->create();
        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);
        app(BookingCharge::class)->chargePassengerForBooking($booking, $ride, $this->passenger);

        $driverBefore = (float) $this->driverWallet->fresh()->balance;

        app(Noshowservice::class)->reportPassengerNoShow($booking->id, $this->driver->fresh());

        // Reporting only opens a pending report and notifies the passenger. No money moves yet.
        $report = NoshowReport::where('booking_id', $booking->id)->where('target_role', 'passenger')->firstOrFail();
        $this->assertSame('pending', $report->status, 'a new report waits for the passenger to contest');
        $this->assertEquals(
            $driverBefore,
            (float) $this->driverWallet->fresh()->balance,
            'the driver must not be paid on the report itself'
        );
        $this->assertSame(
            0,
            WalletTransaction::where('reference', "booking:{$booking->id}")
                ->where('type', 'passenger_no_show_earning')->count(),
            'no earning row is written until the report is resolved'
        );
    }
}
