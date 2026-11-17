<?php

namespace Tests\Feature\Review;

use App\Enums\PaymentMethod;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Services\CashRideFeeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Characterization of CashRideFeeService::chargeCashRideCreationFee, pinned on the UNCHANGED code
 * before the method is moved.
 *
 * Existing tests pin the immediate charge type ('cash_ride_creation_fee') but not the deferred
 * branch ('cash_ride_fee_deferred'), the primary receiving leg ('cash_ride_creation_fee_received'),
 * the replay refusal, or the missing-primary refusal. A move that renamed any of those would pass the
 * existing suite. This file pins them through the real service with real rows and no money mocks.
 */
class CashRideCreationFeeCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY_PHONE = '0900000001';

    private const DRIVER_PHONE = '0900000002';

    private CashRideFeeService $service;

    private User $driver;

    private Wallet $driverWallet;

    private Wallet $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CashRideFeeService::class);

        $this->primary = Wallet::create([
            'name' => 'Primary Admin',
            'phone_number' => config('admin.system_admin.phone'),
            'balance' => 100.00,
        ]);

        $this->driver = User::factory()->create();
        $this->driverWallet = Wallet::create([
            'phone_number' => self::DRIVER_PHONE,
            'user_id' => $this->driver->id,
            'balance' => 50.00,
            'cash_ride_debt' => 0.00,
        ]);
    }

    private function makeRide(bool $deferred, float $fee = 5.00): Ride
    {
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
        $ride->status = 'scheduled';
        $ride->cash_creation_fee = $fee;
        $ride->cash_fee_deferred = $deferred;
        $ride->departure_time = Carbon::now()->addHours(3);
        // Spatial columns must be set via their mutators, not through create().
        $ride->pickup_location = ['lat' => 33.5138, 'lng' => 36.2765];
        $ride->destination_location = ['lat' => 33.5000, 'lng' => 36.3000];
        $ride->save();

        return $ride;
    }

    public function test_immediate_charge_moves_the_fee_from_driver_to_primary_and_posts_both_legs(): void
    {
        $ride = $this->makeRide(deferred: false);

        $this->service->chargeCashRideCreationFee($ride, $this->driver);

        $this->assertEquals(45.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(105.0, (float) $this->primary->fresh()->balance);

        $this->assertSame(1, WalletTransaction::where('type', 'cash_ride_creation_fee')
            ->where('wallet_id', $this->driverWallet->id)->count());
        $this->assertSame(1, WalletTransaction::where('type', 'cash_ride_creation_fee_received')
            ->where('wallet_id', $this->primary->id)->count());
    }

    public function test_deferred_fee_adds_debt_without_moving_balance_and_posts_no_transfer_legs(): void
    {
        $ride = $this->makeRide(deferred: true);

        $this->service->chargeCashRideCreationFee($ride, $this->driver);

        $this->assertEquals(50.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(100.0, (float) $this->primary->fresh()->balance);
        $this->assertEquals(5.0, (float) $this->driverWallet->fresh()->cash_ride_debt);

        $this->assertSame(1, WalletTransaction::where('type', 'cash_ride_fee_deferred')
            ->where('wallet_id', $this->driverWallet->id)->count());
        $this->assertSame(0, WalletTransaction::where('type', 'cash_ride_creation_fee_received')->count());
    }

    public function test_replaying_the_immediate_charge_is_refused_and_moves_no_money_again(): void
    {
        $ride = $this->makeRide(deferred: false);
        $this->service->chargeCashRideCreationFee($ride, $this->driver);

        $thrown = null;
        try {
            $this->service->chargeCashRideCreationFee($ride->fresh(), $this->driver);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A second immediate charge for the same ride must be refused.');
        $this->assertStringStartsWith('RV-02 L2: money movement already posted', $thrown->getMessage());
        $this->assertEquals(45.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(105.0, (float) $this->primary->fresh()->balance);
    }

    public function test_immediate_charge_refuses_loudly_when_the_primary_wallet_is_missing(): void
    {
        Wallet::where('phone_number', config('admin.system_admin.phone'))->delete();
        $ride = $this->makeRide(deferred: false);

        $thrown = null;
        try {
            $this->service->chargeCashRideCreationFee($ride, $this->driver);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The immediate charge must refuse when the primary wallet is missing.');
        $this->assertSame(
            'Primary Admin wallet not found. Run: php artisan db:seed --class=SystemWalletSeeder',
            $thrown->getMessage()
        );
        $this->assertEquals(50.0, (float) $this->driverWallet->fresh()->balance);
    }
}
