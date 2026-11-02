<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Services\BookingCharge;
use App\Modules\Money\Services\FeeSplit;
use App\Modules\Money\Services\RidePayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L21 (modular-monolith slice 19, MONEY): ledger-row characterization of
 * WalletTransactionService::releaseEarningsToDriver (refactored: the driver row and the platform row
 * are now recordDriverShare and recordPlatformShare).
 *
 * Pins, for a completed ride:
 *   - one escrow_released debit from SyCash for the whole total;
 *   - one ride_earnings row for the driver (95%) and one platform_fee row for the platform (5%);
 *   - the driver and platform shares come from FeeSplit and sum to the total to the cent;
 *   - an odd-tenth total (RV-19, the historic one-cent bug) still balances.
 */
class L21RideCompletionPayoutLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private BookingCharge $bookingCharge;

    private RidePayout $ridePayout;

    private User $driver;

    private Wallet $driverWallet;

    private Wallet $primaryWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingCharge = app(BookingCharge::class);
        $this->ridePayout = app(RidePayout::class);

        $this->seedSystemWallets(10_000_000.0);
        $this->primaryWallet = Wallet::where('phone_number', config('admin.system_admin.phone'))->first();

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '091'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);
    }

    /** A completed-ride set: one confirmed, escrow-charged booking per passenger, all on one ride. */
    private function completedRide(float $pricePerSeat, array $seatCounts): array
    {
        // RideBuilder::price() takes an int and would truncate 1000.50 to 1000, so the fractional price
        // is set through withAttributes(), which accepts any value (the column is decimal(8,2)).
        $ride = RideBuilder::for($this->driver)
            ->withAttributes([
                'price_per_seat' => $pricePerSeat,
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'departure_time' => now()->addHours(3),
            ])
            ->create();

        $bookings = [];
        foreach ($seatCounts as $seats) {
            $passenger = User::factory()->create(['is_verified_passenger' => true]);
            $wallet = Wallet::create([
                'user_id' => $passenger->id,
                'phone_number' => '092'.rand(1000000, 9999999),
                'wallet_number' => 'WLT-'.Str::random(10),
                'balance' => 1_000_000,
            ]);
            $passenger->update(['wallet_id' => $wallet->id]);

            $booking = Booking::create([
                'user_id' => $passenger->id,
                'ride_id' => $ride->id,
                'seats' => $seats,
                'status' => 'confirmed',
                'communication_number' => '0900000000',
            ]);
            $this->bookingCharge->chargePassengerForBooking($booking, $ride->fresh(), $passenger->fresh());
            $bookings[] = $booking->fresh();
        }

        return [$ride->fresh(), new Collection($bookings)];
    }

    /** Ledger rows of one type for this ride (the payout rows are written under the ride reference). */
    private function rows(Ride $ride, string $type): \Illuminate\Support\Collection
    {
        return WalletTransaction::where('reference', "ride:{$ride->id}")->where('type', $type)->get();
    }

    public function test_a_completed_ride_writes_one_escrow_debit_one_driver_row_and_one_platform_row(): void
    {
        [$ride, $bookings] = $this->completedRide(50_000, [1, 2]);
        $total = (float) $bookings->sum(fn (Booking $b) => $b->seats * $ride->price_per_seat);

        $this->ridePayout->releaseEarningsToDriver($ride, $bookings);

        $this->assertCount(1, $this->rows($ride, 'escrow_released'), 'one escrow debit for the whole total');
        $this->assertCount(1, $this->rows($ride, 'ride_earnings'), 'one earnings row for the driver');
        $this->assertCount(1, $this->rows($ride, 'platform_fee'), 'one platform fee row');
        $this->assertEqualsWithDelta(-$total, (float) $this->rows($ride, 'escrow_released')->first()->amount, 0.01);
    }

    public function test_the_driver_gets_95_percent_and_the_platform_5_percent_from_fee_split(): void
    {
        [$ride, $bookings] = $this->completedRide(50_000, [1, 2]);
        $total = (float) $bookings->sum(fn (Booking $b) => $b->seats * $ride->price_per_seat);
        $expected = FeeSplit::driverAndPlatform($total);

        $this->ridePayout->releaseEarningsToDriver($ride, $bookings);

        $this->assertEqualsWithDelta($expected['driver'], (float) $this->rows($ride, 'ride_earnings')->first()->amount, 0.001);
        $this->assertEqualsWithDelta($expected['platform'], (float) $this->rows($ride, 'platform_fee')->first()->amount, 0.001);
    }

    public function test_the_two_shares_sum_to_the_escrow_taken_to_the_cent(): void
    {
        [$ride, $bookings] = $this->completedRide(50_000, [1, 2]);

        $this->ridePayout->releaseEarningsToDriver($ride, $bookings);

        $out = abs((float) $this->rows($ride, 'escrow_released')->sum('amount'));
        $in = (float) $this->rows($ride, 'ride_earnings')->sum('amount') + (float) $this->rows($ride, 'platform_fee')->sum('amount');
        $this->assertEqualsWithDelta($out, $in, 0.001, 'escrow out must equal driver plus platform in');
    }

    public function test_an_odd_tenth_total_still_balances_to_the_cent(): void
    {
        // 1000.50 is the RV-19 case: independent rounding made the legs one cent larger than the debit.
        [$ride, $bookings] = $this->completedRide(1000.50, [1]);
        $total = (float) $bookings->sum(fn (Booking $b) => $b->seats * $ride->price_per_seat);
        $this->assertEqualsWithDelta(1000.50, $total, 0.001, 'the fixture total must be the RV-19 odd-tenth amount');

        $this->ridePayout->releaseEarningsToDriver($ride, $bookings);

        $driver = (float) $this->rows($ride, 'ride_earnings')->first()->amount;
        $platform = (float) $this->rows($ride, 'platform_fee')->first()->amount;
        $this->assertEqualsWithDelta($total, round($driver + $platform, 2), 0.001, 'driver plus platform must equal the total to the cent');
    }
}
