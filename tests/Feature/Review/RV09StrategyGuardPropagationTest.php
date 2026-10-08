<?php

namespace Tests\Feature\Review;

use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\RefundResult;
use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\LedgerService;
use App\Services\Payment\WalletTransactionService;
use App\Support\PostingKey;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-09(a) - the payment strategies must not swallow a refusal.
 *
 * WHY THIS FILE IS THE POINT OF THE TASK.
 *
 * `EPayPaymentStrategy` used to wrap all three wallet calls in
 * `catch (\Exception) { return PaymentResult::failure($e->getMessage()); }`. That turned every
 * failure into a soft result object a caller could ignore. It was survivable before RV-02 L2,
 * because the only thing that could fail was the balance check and every caller wanted to abort.
 *
 * RV-02 L2 made the two most dangerous conditions in the system THROWN:
 *
 *   - a `posting_key` collision  - this exact movement has already been posted
 *   - an escrow guard            - `bookings.escrow_held` will not go negative
 *
 * Caught, either one becomes an ignorable `PaymentResult` on a booking row that has already been
 * written. That is the double-payment defect RV-02 L2 exists to prevent, re-entering through the
 * front door. `R2 sec 83` records why RV-20 (routing charge and refund through the factory) is
 * only safe now.
 *
 * These tests do NOT assert "an exception happened". They assert the SPECIFIC RV-02 L2 guards
 * arrive intact - by message and by class - so this cannot rot into "any throw counts", and so a
 * future change that re-wraps the strategy in a blanket catch fails here.
 */
class RV09StrategyGuardPropagationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private EPayPaymentStrategy $strategy;

    private WalletTransactionService $walletService;

    private User $passenger;

    private User $driver;

    private Wallet $passengerWallet;

    private Wallet $driverWallet;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $wallets = $this->seedSystemWallets(1_000_000);
        $this->syCash = $wallets['sycash'];

        $this->walletService = app(WalletTransactionService::class);
        $this->strategy = new EPayPaymentStrategy($this->walletService);

        $this->passenger = User::factory()->create();
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 500_000,
        ]);

        $this->driver = User::factory()->create();
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);

        $this->ride = $this->makeEpaidRide();
        // GuardsLazyLoading is armed on Ride: the strategy reads `$ride->driver`, so it must be
        // eager-loaded or every call here dies with a LazyLoadingViolationException before the
        // money path is reached at all.
        $this->ride->load('driver');
    }

    private Wallet $syCash;

    private function makeEpaidRide(): Ride
    {
        return RideBuilder::for($this->driver)
            ->paymentMethod('e-pay')
            ->price(100)
            ->seats(10)
            ->create();
    }

    private function chargedBooking(int $seats = 1, string $status = 'confirmed'): Booking
    {
        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $this->ride->id,
            'seats' => $seats,
            'status' => $status,
            'communication_number' => '0912345678',
        ]);

        $this->walletService->chargePassengerForBooking($booking, $this->ride, $this->passenger);

        return $booking->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 1. The posting-key guard must reach the caller
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_repeated_charge_reaches_the_caller_as_a_throw_not_a_result_object(): void
    {
        $booking = $this->chargedBooking(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already posted/i');

        // Before RV-09(a) this returned PaymentResult::success(false-ish) and the caller moved on.
        $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);
    }

    /**
     * @test
     */
    public function a_repeated_charge_propagates_for_the_exact_reason_r_v02_l2_names(): void
    {
        $booking = $this->chargedBooking(1);
        $key = PostingKey::build('booking', $booking->id, 'escrow-in');

        $syCashBefore = (float) $this->syCash->fresh()->balance;
        $rowsBefore = WalletTransaction::count();

        try {
            $this->strategy->processBookingPayment($booking, $this->ride, $this->passenger);
            $this->fail('the strategy must not swallow a posting-key collision');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($key, $e->getMessage(), 'the message must name the posting key');
        }

        // The refusal is a refusal: not one further row, not one further cent.
        $this->assertSame($rowsBefore, WalletTransaction::count(), 'the retry must add no transaction');
        $this->assertSame($syCashBefore, (float) $this->syCash->fresh()->balance, 'SyCash must be unchanged');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 2. The escrow guard must reach the caller
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function an_exhausted_escrow_reaches_the_caller_as_a_throw(): void
    {
        $booking = $this->chargedBooking(1);

        // Drain the booking's escrow by settling it, which is the only way to spend it.
        $this->walletService->releaseEscrowToDriver($booking, $this->ride, $this->driver);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/escrow/i');

        // A DIFFERENT movement - different posting key - so only the escrow guard can stop this.
        $this->strategy->processRideCompletionPayment($booking, $this->ride, $this->driver);
    }

    /**
     * @test
     */
    public function a_refusal_through_the_strategy_moves_no_money(): void
    {
        $booking = $this->chargedBooking(1);

        // Spend the booking's escrow FIRST, so the second attempt is a genuine refusal rather
        // than a legitimate second release. (An earlier draft of this test omitted this and the
        // balance moved 95.00 - correctly, because the call was allowed.)
        $this->walletService->releaseEscrowToDriver($booking, $this->ride, $this->driver);

        $driverBefore = (float) $this->driverWallet->fresh()->balance;
        $syCashBefore = (float) $this->syCash->fresh()->balance;
        $rowsBefore = WalletTransaction::count();

        try {
            $this->strategy->processRideCompletionPayment($booking, $this->ride, $this->driver);
            $this->fail('the strategy must not swallow the exhausted-escrow refusal');
        } catch (\RuntimeException) {
            // expected - the point is what did NOT happen
        }

        $this->assertSame($driverBefore, (float) $this->driverWallet->fresh()->balance);
        $this->assertSame($syCashBefore, (float) $this->syCash->fresh()->balance);
        $this->assertSame($rowsBefore, WalletTransaction::count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 3. The refund path, which is the one RV-20 is about to rewire
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_repeated_refund_reaches_the_caller_as_a_throw(): void
    {
        $booking = $this->chargedBooking(1, 'confirmed');

        $this->strategy->processRefund($this->ride, $this->set($booking), 'driver_cancellation');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already posted/i');

        $this->strategy->processRefund($this->ride, $this->set($booking), 'driver_cancellation');
    }

    /**
     * @test
     */
    public function a_successful_call_still_returns_a_success_result(): void
    {
        $booking = $this->chargedBooking(1);

        // RV-09(a) removes the swallow, NOT the result object. The happy path is unchanged, so
        // RV-20 can still route through the factory without changing any caller contract.
        $result = $this->strategy->processRefund($this->ride, $this->set($booking), 'driver_cancellation');

        $this->assertTrue($result->success);
        $this->assertInstanceOf(RefundResult::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 4. The retry argument: LedgerService must not return duplicates
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function the_ledger_service_returns_exactly_the_entries_that_actually_exist(): void
    {
        $legs = [
            ['wallet_id' => $this->driverWallet->id, 'amount' => 250.00, 'description' => 'RV-09(a) retry-safety probe'],
            ['wallet_id' => $this->syCash->id, 'amount' => -250.00, 'description' => 'RV-09(a) retry-safety probe'],
        ];

        $entries = app(LedgerService::class)->postTransfer($legs);

        $this->assertCount(count($legs), $entries, 'one returned entry per leg');

        // The property that matters is not the COUNT but that every returned entry is a REAL row.
        // `$written` is accumulated in the ENCLOSING scope while the transaction now retries on a
        // concurrency error, so without the in-closure reset a retried attempt would append to the
        // previous one and the caller would receive entries for rows that had been rolled back.
        // Asserting existence is what would catch that, and it holds whether or not a retry fired.
        foreach ($entries as $entry) {
            $this->assertTrue(
                $entry->exists && LedgerEntry::whereKey($entry->getKey())->exists(),
                'every returned entry must be a persisted row, never a phantom from a rolled-back attempt'
            );
        }

        $this->assertSame(2, LedgerEntry::where('description', 'RV-09(a) retry-safety probe')->count());
    }

    /**
     * RV-20: refunds are SET-level, so the old tests that passed a single Booking now pass a
     * one-element set. Kept as a named helper rather than `new EloquentCollection([$b])` at each
     * call site so the unit of cancellation is obvious at every use.
     *
     * @return EloquentCollection<int,Booking>
     */
    private function set(Booking ...$bookings): EloquentCollection
    {
        return new EloquentCollection($bookings);
    }
}
