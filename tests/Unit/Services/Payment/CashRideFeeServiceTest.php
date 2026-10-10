<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\CashRideFeeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @covers \App\Services\Payment\CashRideFeeService
 */
class CashRideFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashRideFeeService $service;

    private User $driver;

    private Wallet $wallet;

    // =========================================================================
    // SET UP
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CashRideFeeService::class);

        // System wallet — located by config('admin.system_admin.phone').
        // Seeded with 100.00 to represent fees already collected by the platform,
        // so that non-deferred refund tests can actually pay out to the driver.
        Wallet::create([
            'name' => 'Primary Escrow',
            'phone_number' => config('admin.system_admin.phone'),
            'balance' => 100.00,
        ]);

        // Driver under test
        $this->driver = User::factory()->create();
        $this->wallet = Wallet::create([
            'phone_number' => '0900000002',
            'user_id' => $this->driver->id,
            'balance' => 0.00,
            'cash_ride_debt' => 0.00,
        ]);
    }
    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Seed a cash ride at a given percentage through its scheduled window.
     * Uses the Ride model's spatial mutators directly because pickup_location
     * and destination_location are NOT in $fillable — Ride::create() would
     * silently drop them and MySQL would reject the INSERT.
     */
    private function seedRide(
        float $elapsedPct = 0,
        float $fee = 5.00,
        bool $deferred = false,
        string $status = 'active',
    ): Ride {
        $window = 10.0;
        $elapsedHrs = $window * $elapsedPct / 100;

        $ride = new Ride;
        $ride->driver_id = $this->driver->id;
        $ride->payment_method = PaymentMethod::CASH->value;
        $ride->booking_type = 'direct';
        $ride->price_per_seat = 25.00;
        $ride->available_seats = 4;
        $ride->vehicle_type = 'car';
        $ride->pickup_address = 'Test Origin';
        $ride->destination_address = 'Test Destination';
        $ride->communication_number = '0900000000';
        $ride->distance = 0;
        $ride->duration = 0;
        $ride->status = $status;
        $ride->cash_creation_fee = $fee;
        $ride->cash_fee_deferred = $deferred;
        $ride->departure_time = Carbon::now()->addHours($window - $elapsedHrs);
        // Spatial columns must be set via their mutators, not through create().
        $ride->pickup_location = ['lat' => 33.5138, 'lng' => 36.2765];
        $ride->destination_location = ['lat' => 33.5000, 'lng' => 36.3000];
        $ride->created_at = Carbon::now()->subHours($elapsedHrs);
        $ride->save();

        return $ride;
    }

    /**
     * Attach one confirmed booking so time-based refund tiers apply.
     */
    private function attachBooking(Ride $ride): Booking
    {
        $passenger = User::factory()->create();

        return Booking::create([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => BookingStatus::CONFIRMED->value,
            'communication_number' => '0900000000',
        ]);
    }

    // =========================================================================
    // SECTION 1 — WALLET EXISTENCE GATE
    // =========================================================================

    /** @test */
    public function test_driver_without_wallet_is_blocked(): void
    {
        $walletlessDriver = User::factory()->create();

        $result = $this->service->canCreateCashRide($walletlessDriver, 5.00);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsStringIgnoringCase('wallet', $result['reason']);
    }

    // =========================================================================
    // SECTION 2 — FREE QUOTA (RIDES 1 AND 2, DEFERRED)
    // =========================================================================

    /** @test */
    public function test_first_cash_ride_is_deferred(): void
    {
        $result = $this->service->canCreateCashRide($this->driver, 5.00);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['deferred']);
        $this->assertEquals(5.00, $result['fee']);
    }

    /** @test */
    public function test_second_cash_ride_is_deferred(): void
    {
        $this->seedRide();

        $result = $this->service->canCreateCashRide($this->driver, 5.00);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['deferred']);
    }

    // =========================================================================
    // SECTION 3 — DEBT GATE (RIDE 3+)
    // =========================================================================

    /** @test */
    public function test_third_ride_blocked_when_debt_outstanding(): void
    {
        $this->seedRide();
        $this->seedRide();
        $this->wallet->update(['cash_ride_debt' => 10.00]);

        $result = $this->service->canCreateCashRide($this->driver, 5.00);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsStringIgnoringCase('debt', $result['reason']);
    }

    /** @test */
    public function test_third_ride_allowed_when_debt_zero(): void
    {
        $this->seedRide();
        $this->seedRide();
        $this->wallet->update(['balance' => 50.00, 'cash_ride_debt' => 0.00]);

        $result = $this->service->canCreateCashRide($this->driver, 5.00);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['deferred']);
    }

    // =========================================================================
    // SECTION 4 — CHARGING
    // =========================================================================

    /** @test */
    public function test_deferred_charge_increases_debt_only(): void
    {
        $ride = $this->seedRide(fee: 5.00, deferred: true);

        $this->service->chargeCashRideCreationFee($ride, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(0.00, $this->wallet->balance);
        $this->assertEquals(5.00, $this->wallet->cash_ride_debt);
    }

    /** @test */
    public function test_two_deferred_charges_accumulate_debt(): void
    {
        $ride1 = $this->seedRide(fee: 5.00, deferred: true);
        $this->service->chargeCashRideCreationFee($ride1, $this->driver);

        $ride2 = $this->seedRide(fee: 3.75, deferred: true);
        $this->service->chargeCashRideCreationFee($ride2, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(8.75, $this->wallet->cash_ride_debt);
        $this->assertEquals(0.00, $this->wallet->balance);
    }

    /** @test */
    public function test_immediate_charge_deducts_from_balance(): void
    {
        $this->wallet->update(['balance' => 50.00]);
        $ride = $this->seedRide(fee: 5.00, deferred: false);

        $this->service->chargeCashRideCreationFee($ride, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(45.00, $this->wallet->balance);
        $this->assertEquals(0.00, $this->wallet->cash_ride_debt);
    }

    // =========================================================================
    // SECTION 5 — REFUNDS: NO BOOKINGS (ALWAYS 100 %)
    // =========================================================================

    /** @test */
    public function test_no_booking_refund_is_always_full_regardless_of_elapsed(): void
    {
        $ride = $this->seedRide(elapsedPct: 80, fee: 5.00);
        $this->wallet->update(['balance' => 0.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(5.00, $this->wallet->balance);
    }

    // =========================================================================
    // SECTION 6 — REFUNDS: WITH BOOKINGS (TIME-BASED TIERS)
    // =========================================================================

    /** @test */
    public function test_refund_tier_0_to_30_percent_returns_full(): void
    {
        $ride = $this->seedRide(elapsedPct: 20, fee: 5.00);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(5.00, $this->wallet->balance);
    }

    /** @test */
    public function test_refund_tier_30_to_50_percent_returns_70_percent(): void
    {
        $ride = $this->seedRide(elapsedPct: 40, fee: 5.00);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->wallet->refresh();
        $this->assertEquals(3.50, $this->wallet->balance);
    }

    /** @test */
    public function test_refund_tier_50_to_70_percent_returns_50_percent(): void
    {
        $ride = $this->seedRide(elapsedPct: 60, fee: 5.00);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->wallet->refresh();
        $this->assertEquals(2.50, $this->wallet->balance);
    }

    /** @test */
    public function test_refund_tier_70_to_100_percent_returns_nothing(): void
    {
        $ride = $this->seedRide(elapsedPct: 80, fee: 5.00);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->wallet->refresh();
        $this->assertEquals(0.00, $this->wallet->balance);
    }

    // =========================================================================
    // SECTION 7 — DEFERRED REFUNDS (DEBT REDUCTION)
    // =========================================================================

    /** @test */
    public function test_deferred_full_refund_zeroes_debt_not_balance(): void
    {
        $ride = $this->seedRide(elapsedPct: 20, fee: 5.00, deferred: true);
        $this->wallet->update(['balance' => 0.00, 'cash_ride_debt' => 5.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(0.00, $this->wallet->cash_ride_debt);
        $this->assertEquals(0.00, $this->wallet->balance);
    }

    /** @test */
    public function test_deferred_partial_refund_reduces_debt_proportionally(): void
    {
        $ride = $this->seedRide(elapsedPct: 40, fee: 5.00, deferred: true);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00, 'cash_ride_debt' => 5.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->wallet->refresh();
        $this->assertEqualsWithDelta(1.50, $this->wallet->cash_ride_debt, 0.01);
        $this->assertEquals(0.00, $this->wallet->balance);
    }

    /** @test */
    public function test_deferred_zero_refund_leaves_debt_unchanged(): void
    {
        $ride = $this->seedRide(elapsedPct: 80, fee: 5.00, deferred: true);
        $this->attachBooking($ride);
        $this->wallet->update(['balance' => 0.00, 'cash_ride_debt' => 5.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->wallet->refresh();
        $this->assertEquals(5.00, $this->wallet->cash_ride_debt);
        $this->assertEquals(0.00, $this->wallet->balance);
    }

    // =========================================================================
    // SECTION 8 — AUTO DEBT CLEARING
    // =========================================================================

    /** @test */
    public function test_auto_clear_deducts_debt_when_balance_is_sufficient(): void
    {
        $this->wallet->update(['balance' => 20.00, 'cash_ride_debt' => 10.00]);

        $this->service->autoClearDebt($this->wallet, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(10.00, $this->wallet->balance);
        $this->assertEquals(0.00, $this->wallet->cash_ride_debt);
    }

    /** @test */
    public function test_auto_clear_skips_when_balance_is_insufficient(): void
    {
        $this->wallet->update(['balance' => 3.00, 'cash_ride_debt' => 10.00]);

        $this->service->autoClearDebt($this->wallet, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(3.00, $this->wallet->balance);
        $this->assertEquals(10.00, $this->wallet->cash_ride_debt);
    }

    /** @test */
    public function test_auto_clear_is_no_op_when_no_debt_exists(): void
    {
        $this->wallet->update(['balance' => 50.00, 'cash_ride_debt' => 0.00]);

        $this->service->autoClearDebt($this->wallet, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(50.00, $this->wallet->balance);
        $this->assertEquals(0.00, $this->wallet->cash_ride_debt);
    }

    /** @test */
    public function test_auto_clear_when_balance_exactly_equals_debt(): void
    {
        $this->wallet->update(['balance' => 7.50, 'cash_ride_debt' => 7.50]);

        $this->service->autoClearDebt($this->wallet, $this->driver);

        $this->wallet->refresh();
        $this->assertEquals(0.00, $this->wallet->balance);
        $this->assertEquals(0.00, $this->wallet->cash_ride_debt);
    }
}
