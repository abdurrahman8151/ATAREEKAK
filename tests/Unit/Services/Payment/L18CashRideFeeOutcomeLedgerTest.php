<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Services\CashRideFeeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L18 (modular-monolith slice 16, MONEY): ledger characterization of the three outcomes of
 * CashRideFeeService::refundCashRideCreationFee (now cancelDeferredDebt, recordNoRefund, refundToDriver).
 *
 * The existing CashRideFeeServiceTest pins the amounts (full / 70% / 50% / 0% and the debt reduction).
 * It does NOT pin the ledger rows each outcome writes. This file does, so a refactor that drops a row
 * fails here even when the balances still match.
 */
class L18CashRideFeeOutcomeLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CashRideFeeService $service;

    private User $driver;

    private Wallet $driverWallet;

    private Wallet $primaryWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CashRideFeeService::class);

        // Primary Admin wallet (config admin.system_admin.phone) funds the refund.
        $this->primaryWallet = Wallet::create([
            'name' => 'Primary Escrow',
            'phone_number' => config('admin.system_admin.phone'),
            'balance' => 100.00,
        ]);

        $this->driver = User::factory()->create();
        $this->driverWallet = Wallet::create([
            'phone_number' => '0900000003',
            'user_id' => $this->driver->id,
            'balance' => 0.00,
            'cash_ride_debt' => 0.00,
        ]);
    }

    /** Same spatial-column pattern as CashRideFeeServiceTest::seedRide. */
    private function seedRide(float $elapsedPct, float $fee, bool $deferred): Ride
    {
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
        $ride->status = 'active';
        $ride->cash_creation_fee = $fee;
        $ride->cash_fee_deferred = $deferred;
        $ride->departure_time = Carbon::now()->addHours($window - $elapsedHrs);
        $ride->pickup_location = ['lat' => 33.5138, 'lng' => 36.2765];
        $ride->destination_location = ['lat' => 33.5000, 'lng' => 36.3000];
        $ride->created_at = Carbon::now()->subHours($elapsedHrs);
        $ride->save();

        return $ride;
    }

    private function attachBooking(Ride $ride): void
    {
        $passenger = User::factory()->create();

        Booking::create([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => BookingStatus::CONFIRMED->value,
            'communication_number' => '0900000000',
        ]);
    }

    /** All ledger rows for this ride's cash fee, keyed by type. */
    private function rowsByType(Ride $ride): array
    {
        return WalletTransaction::where('reference', "ride:{$ride->id}")
            ->get()
            ->groupBy('type')
            ->map(fn ($g) => $g->count())
            ->all();
    }

    public function test_deferred_fee_writes_only_a_debt_cancel_row_and_moves_no_money(): void
    {
        $ride = $this->seedRide(elapsedPct: 20, fee: 5.00, deferred: true);
        $this->attachBooking($ride);
        $this->driverWallet->update(['cash_ride_debt' => 5.00]);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $rows = $this->rowsByType($ride);
        $this->assertSame(1, $rows['cash_ride_fee_debt_cancelled'] ?? 0, 'one debt-cancel audit row');
        $this->assertArrayNotHasKey('cash_ride_fee_refund', $rows, 'a deferred fee must not write a refund leg');
        $this->assertArrayNotHasKey('cash_ride_fee_refund_issued', $rows, 'a deferred fee must not debit the Primary Admin');
        $this->assertEquals(100.00, $this->primaryWallet->fresh()->balance, 'the Primary Admin balance must not move');
        $this->assertEquals(0.00, $this->driverWallet->fresh()->balance, 'the driver balance must not move');
    }

    public function test_no_refund_writes_only_a_no_refund_audit_row_and_moves_no_money(): void
    {
        // Late cancellation with passengers: 80% elapsed → refund 0%.
        $ride = $this->seedRide(elapsedPct: 80, fee: 5.00, deferred: false);
        $this->attachBooking($ride);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $rows = $this->rowsByType($ride);
        $this->assertSame(1, $rows['cash_ride_fee_no_refund'] ?? 0, 'one no-refund audit row');
        $this->assertArrayNotHasKey('cash_ride_fee_refund', $rows, 'no refund leg when the refund is zero');
        $this->assertEquals(100.00, $this->primaryWallet->fresh()->balance, 'the Primary Admin balance must not move');
        $this->assertEquals(0.00, $this->driverWallet->fresh()->balance, 'the driver must receive nothing');
    }

    public function test_partial_refund_writes_a_driver_leg_and_a_matching_primary_admin_leg(): void
    {
        // 40% elapsed with passengers → 70% refund on a 5.00 fee = 3.50.
        $ride = $this->seedRide(elapsedPct: 40, fee: 5.00, deferred: false);
        $this->attachBooking($ride);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $driverLeg = WalletTransaction::where('reference', "ride:{$ride->id}")
            ->where('type', 'cash_ride_fee_refund')->first();
        $primaryLeg = WalletTransaction::where('reference', "ride:{$ride->id}")
            ->where('type', 'cash_ride_fee_refund_issued')->first();

        $this->assertNotNull($driverLeg, 'the driver refund leg must be written');
        $this->assertNotNull($primaryLeg, 'the Primary Admin refund leg must be written');
        $this->assertEquals(3.50, (float) $driverLeg->amount, 'driver is credited the refund');
        $this->assertEquals(-3.50, (float) $primaryLeg->amount, 'Primary Admin is debited the same refund');
        $this->assertEquals(0.0, (float) $driverLeg->amount + (float) $primaryLeg->amount, 'the two legs net to zero');
    }

    public function test_partial_refund_moves_the_same_amount_out_of_primary_admin_and_into_the_driver(): void
    {
        $ride = $this->seedRide(elapsedPct: 40, fee: 5.00, deferred: false);
        $this->attachBooking($ride);

        $this->service->refundCashRideCreationFee($ride, $this->driver, hadActiveBookings: true);

        $this->assertEquals(3.50, (float) $this->driverWallet->fresh()->balance, 'driver balance rises by the refund');
        $this->assertEquals(96.50, (float) $this->primaryWallet->fresh()->balance, 'Primary Admin balance falls by the refund');
    }
}
