<?php

namespace Tests\Unit\Domain;

use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentResult;
use App\Domain\Payment\Strategies\PaymentStrategy;
use App\Domain\Payment\Strategies\RefundResult;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

class EPayPaymentStrategyTest extends TestCase
{
    use RefreshDatabase;

    private EPayPaymentStrategy $strategy;

    private User $driver;

    private User $passenger;

    private Wallet $passengerWallet;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = app(EPayPaymentStrategy::class);

        Config::set('admin.system_admin', [
            'email' => 'sysadm@epay.test',
            'password' => 'pass',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'phone' => '0910000010',
            'wallet_prefix' => 'SYS',
            'permissions' => ['*'],
        ]);
        Config::set('admin.sycash', [
            'email' => 'sycash@epay.test',
            'password' => 'pass',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'phone' => '0910000011',
            'wallet_prefix' => 'SYC',
            'permissions' => ['view_wallet'],
        ]);

        // Admin wallets
        foreach (['system_admin', 'sycash'] as $type) {
            $cfg = config("admin.{$type}");
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                ['first_name' => $type, 'last_name' => 'Admin',
                    'password' => bcrypt($cfg['password']), 'gender' => 'M',
                    'address' => 'دمشق', 'status' => 1]
            );
            if (! $user->wallet_id) {
                $w = Wallet::create([
                    // RV-21: system wallets MUST have user_id NULL (as SystemWalletSeeder
                    // and every other money test do). This fixture attached them to an
                    // admin User — precisely the user-owned-on-system-phone configuration
                    // the money boundary now rejects, so the test was encoding the bug
                    // rather than production reality.
                    'user_id' => null,
                    'phone_number' => $cfg['phone'],
                    'balance' => 10_000_000,
                ]);
                $user->update(['wallet_id' => $w->id]);
            }
        }

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '091'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 500_000,
        ]);
        $this->driver->update(['wallet_id' => $driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '092'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 1_000_000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);

        $this->ride = $this->makeRide();
    }

    // ─── canProcess ────────────────────────────────────────────────────────

    public function test_can_process_returns_true_for_e_pay(): void
    {
        $this->assertTrue($this->strategy->canProcess('e-pay'));
    }

    public function test_can_process_returns_false_for_cash(): void
    {
        $this->assertFalse($this->strategy->canProcess('cash'));
    }

    public function test_can_process_returns_false_for_empty_string(): void
    {
        $this->assertFalse($this->strategy->canProcess(''));
    }

    public function test_can_process_returns_false_for_unknown_method(): void
    {
        $this->assertFalse($this->strategy->canProcess('bitcoin'));
    }

    // ─── getPaymentMethod ──────────────────────────────────────────────────

    public function test_get_payment_method_returns_e_pay(): void
    {
        $this->assertEquals('e-pay', $this->strategy->getPaymentMethod());
    }

    // ─── processBookingPayment ─────────────────────────────────────────────

    public function test_process_booking_payment_returns_success_when_balance_sufficient(): void
    {
        $booking = $this->makeBooking(1);

        $result = $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_process_booking_payment_deducts_from_passenger_wallet(): void
    {
        $booking = $this->makeBooking(2);
        $amount = $booking->seats * $this->ride->price_per_seat;
        $before = (float) $this->passengerWallet->fresh()->balance;

        $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);

        $this->assertEquals($before - $amount, (float) $this->passengerWallet->fresh()->balance);
    }

    public function test_process_booking_payment_returns_failure_when_balance_insufficient(): void
    {
        $this->passengerWallet->update(['balance' => 0]);
        $booking = $this->makeBooking(1);

        $result = $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);

        $this->assertFalse($result->success);
    }

    public function test_process_booking_payment_returns_failure_when_no_wallet(): void
    {
        $noWalletPassenger = User::factory()->create();
        $booking = $this->makeBooking(1);

        $result = $this->strategy->processBookingPayment($booking, $this->ride, $noWalletPassenger);

        $this->assertFalse($result->success);
    }

    // ─── processRefund ─────────────────────────────────────────────────────

    public function test_process_refund_returns_refund_result_instance(): void
    {
        $booking = $this->makeBooking(1, 'confirmed');

        // First charge so escrow (sycash) has funds
        $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);

        $result = $this->strategy->processRefund($booking, $this->ride, $this->passenger);

        $this->assertInstanceOf(RefundResult::class, $result);
    }

    public function test_process_refund_returns_success_when_escrow_has_balance(): void
    {
        $booking = $this->makeBooking(1, 'confirmed');
        $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);

        $result = $this->strategy->processRefund($booking, $this->ride, $this->passenger);

        $this->assertTrue($result->success);
    }

    // ─── Implements interface ──────────────────────────────────────────────

    public function test_strategy_implements_payment_strategy_interface(): void
    {
        $this->assertInstanceOf(PaymentStrategy::class, $this->strategy);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function makeRide(): Ride
    {
        // RV-34: shared builder. Values from the previous raw-SQL fixture: seats 4,
        // price 50000, e-pay, direct, active, distance 320.5, duration 240,
        // communication 0912345678, departure now+3h.
        return RideBuilder::for($this->driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0912345678',
            ])
            ->departureTime(now()->addHours(3))
            ->create();
    }

    private function makeBooking(int $seats = 1, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $this->ride->id,
            'seats' => $seats,
            'status' => $status,
            'communication_number' => '0912345678',
        ]);
    }
}
