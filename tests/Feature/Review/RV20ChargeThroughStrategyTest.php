<?php

namespace Tests\Feature\Review;

use App\Domain\ValueObjects\PhoneNumber;
use App\DTOs\Ride\BookRideDTO;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use App\Services\Ride\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-20 - the CHARGE half routes through PaymentStrategyFactory.
 *
 * SCOPE, AND WHY IT IS HALF.
 *
 * RV-20 was three movements: book (charge), completion (release), refund. Completion was already
 * wired (`BookingService::processRideCompletionPayment`). This file pins the CHARGE half, at
 * `bookRide` and `acceptBooking`.
 *
 * The REFUND half is deliberately NOT done, and the reason is a shape mismatch that no amount of
 * rewiring can paper over. Both real refund paths are SET-LEVEL:
 *
 *   refundPassengersForDriverCancellation(Ride, Collection $bookings)
 *   refundPassengersForStaffCancellation(Ride, Collection $bookings)
 *
 * and each does one aggregate SyCash sufficiency check for the whole set, derives its posting key
 * from the SET (`PostingKey::buildForSet`), debits escrow for the set, and writes ONE SyCash
 * transaction for the combined total. But `PaymentStrategy::processRefund(Booking, Ride, User)`
 * takes a SINGLE booking. Calling it once per booking would:
 *
 *   - weaken the sufficiency check to per-booking, so a set that cannot be refunded in full could
 *     still have its individual legs refunded;
 *   - change the idempotency scope, because a set-derived posting key becomes N per-booking keys;
 *   - multiply the SyCash ledger rows, one per booking instead of one per cancellation.
 *
 * That is a money change, not a wiring change. It is recorded for the owner as a design decision.
 *
 * WHY THE ROUTING IS PROVEN RATHER THAN ASSUMED.
 *
 * Both charge paths are per-booking and `processBookingPayment(Booking, Ride, User)` matches them
 * exactly, so for E-PAY the money outcome is identical before and after - which means a money
 * assertion alone cannot tell the two apart. The observable that CAN is the CASH path: previously a
 * cash booking called the wallet service conditionally and did nothing at all; now it reaches
 * `CashPaymentStrategy::processBookingPayment`, which logs. `a_cash_booking_reaches_the_cash_
 * strategy` asserts that log line, so reverting this change fails the suite.
 */
class RV20ChargeThroughStrategyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private WalletTransactionService $walletService;

    private BookingService $bookingService;

    private User $passenger;

    private User $driver;

    private Wallet $passengerWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $wallets = $this->seedSystemWallets(1_000_000);
        $this->syCash = $wallets['sycash'];

        $this->walletService = app(WalletTransactionService::class);
        $this->bookingService = app(BookingService::class);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = $this->walletFor($this->passenger, 500_000);

        $this->driver = User::factory()->create(['is_verified_passenger' => true]);
    }

    private Wallet $syCash;

    private function walletFor(User $user, float $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => $balance,
        ]);
    }

    private function ride(string $paymentMethod = 'e-pay', string $bookingType = 'direct'): Ride
    {
        return RideBuilder::for($this->driver)
            ->paymentMethod($paymentMethod)
            ->price(100)
            ->seats(10)
            ->withAttributes(['booking_type' => $bookingType])
            ->create();
    }

    private function book(Ride $ride, int $seats = 2, string $key = 'idem-1'): Booking
    {
        $dto = new BookRideDTO(
            passengerId: $this->passenger->id,
            rideId: $ride->id,
            seats: $seats,
            communicationNumber: PhoneNumber::from('0912345678'),
            idempotencyKey: $key,
        );

        return $this->bookingService->bookRide($dto, $this->passenger);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 1. THE ROUTING PROOF - this is what makes the rest mean anything
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_cash_booking_reaches_the_cash_strategy(): void
    {
        Log::spy();

        $this->book($this->ride('cash'), 2, 'cash-1');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, 'Cash booking recorded'))
            ->once();
    }

    /**
     * @test
     */
    public function an_unrecognised_payment_method_is_rejected_loudly_instead_of_silently_not_charging(): void
    {
        // The DB column was widened from ENUM to VARCHAR, so the database no longer guarantees the
        // value. Previously an out-of-range method simply skipped the charge - a booking confirmed
        // with no money taken. Now the factory refuses it.
        $ride = $this->ride('e-pay');
        $ride->update(['payment_method' => 'barter']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No payment strategy found/i');

        $this->book($ride, 2, 'barter-1');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 2. The money must be IDENTICAL to the pre-RV-20 behaviour
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function an_epay_direct_booking_still_holds_the_fare_in_escrow(): void
    {
        $before = (float) $this->passengerWallet->fresh()->balance;

        $booking = $this->book($this->ride('e-pay'), 2, 'epay-1');

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(
            $before - 200.0,
            round((float) $this->passengerWallet->fresh()->balance, 2),
            '2 seats x 100 must leave the passenger wallet'
        );
        $this->assertSame(200.0, round((float) $booking->fresh()->escrow_held, 2));
        $this->assertGreaterThan(
            0,
            WalletTransaction::where('wallet_id', $this->passengerWallet->id)
                ->where('type', 'ride_booking_payment')->count(),
            'the passenger side of the charge must have been written'
        );
    }

    /**
     * @test
     */
    public function a_cash_booking_moves_no_money_at_all(): void
    {
        $before = (float) $this->passengerWallet->fresh()->balance;
        $txBefore = WalletTransaction::count();

        $this->book($this->ride('cash'), 2, 'cash-2');

        $this->assertSame($before, (float) $this->passengerWallet->fresh()->balance);
        $this->assertSame($txBefore, WalletTransaction::count());
        $this->assertSame(
            0.0,
            round((float) Booking::latest('id')->first()->escrow_held, 2),
            'cash holds nothing in escrow'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 3. The REQUEST deferral, which both charge sites share
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_request_ride_defers_the_charge_until_the_driver_accepts(): void
    {
        $before = (float) $this->passengerWallet->fresh()->balance;

        $booking = $this->book($this->ride('e-pay', 'request'), 2, 'req-1');

        $this->assertSame('pending', $booking->status);
        $this->assertSame(
            $before,
            (float) $this->passengerWallet->fresh()->balance,
            'a PENDING request must not have charged'
        );
    }

    /**
     * @test
     */
    public function accepting_a_request_booking_charges_through_the_strategy(): void
    {
        $before = (float) $this->passengerWallet->fresh()->balance;
        $booking = $this->book($this->ride('e-pay', 'request'), 3, 'req-2');

        $accepted = $this->bookingService->acceptBooking($booking->id, $this->driver);

        $this->assertSame('confirmed', $accepted->status);
        $this->assertSame(
            $before - 300.0,
            round((float) $this->passengerWallet->fresh()->balance, 2),
            '3 seats x 100 must be taken when the driver accepts'
        );
        $this->assertSame(300.0, round((float) $accepted->fresh()->escrow_held, 2));
    }

    /**
     * @test
     */
    public function accepting_a_cash_request_booking_moves_no_money(): void
    {
        $booking = $this->book($this->ride('cash', 'request'), 3, 'req-3');
        $before = (float) $this->passengerWallet->fresh()->balance;
        $txBefore = WalletTransaction::count();

        $accepted = $this->bookingService->acceptBooking($booking->id, $this->driver);

        $this->assertSame('confirmed', $accepted->status);
        $this->assertSame($before, (float) $this->passengerWallet->fresh()->balance);
        $this->assertSame($txBefore, WalletTransaction::count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 4. Routing must NOT have reintroduced a swallow (this is RV-09(a)'s whole point)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_refusal_through_the_service_aborts_the_whole_booking(): void
    {
        // Drained wallet: the charge cannot be funded.
        $this->passengerWallet->update(['balance' => 0]);
        $bookingsBefore = Booking::count();

        try {
            $this->book($this->ride('e-pay'), 2, 'broke-1');
            $this->fail('an unfundable e-pay booking must not succeed');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/insufficient balance/i', $e->getMessage());
        }

        $this->assertSame(
            $bookingsBefore,
            Booking::count(),
            'the outer transaction must have rolled the booking row back too'
        );
    }
}
