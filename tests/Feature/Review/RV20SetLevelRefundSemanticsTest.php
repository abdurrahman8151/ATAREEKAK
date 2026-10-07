<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-20 - the SET-LEVEL refund semantics are pinned, so the pending interface decision is safe.
 *
 * WHY THIS FILE EXISTS.
 *
 * `R2 sec 86` took the charge half of RV-20 and stopped at the refund half, because
 * `PaymentStrategy::processRefund(Booking, Ride, User)` is per-booking while both real refund paths
 * are set-level. Whether to split that method or re-shape it is an OWNER DESIGN DECISION and has not
 * been made.
 *
 * A decision that is not yet made is a good reason to PIN THE CURRENT BEHAVIOUR, not a reason to
 * leave it implicit. Before this file, the only guard test
 * (`WalletTransactionServiceTest::test_refund_passengers_throws_when_sycash_insufficient_balance`)
 * used a SINGLE booking. So nothing in the suite would notice if someone rewired the refund through
 * `processRefund` one booking at a time - which is precisely the change that must not be made by
 * accident.
 *
 * What per-booking wiring would break, and what each test below holds in place:
 *
 *   1. the AGGREGATE sufficiency check   -> test_a_set_whose_total_exceeds_sycash_is_refused_
 *                                           before_any_money_moves
 *   2. SET-scoped idempotency            -> test_a_second_refund_of_the_same_set_is_refused
 *   3. ONE SyCash row for the whole set  -> test_the_set_writes_one_sycash_row_and_one_row_per_
 *                                           passenger
 *
 * These are assertions about code that is NOT being changed. They exist so that the eventual
 * interface change is made against a pinned baseline, and so that a naive rewiring fails loudly
 * instead of quietly refunding half a cancellation.
 */
class RV20SetLevelRefundSemanticsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private WalletTransactionService $walletService;

    private Wallet $syCash;

    private User $driver;

    private Ride $ride;

    /** @var array<int, User> */
    private array $passengers = [];

    /** @var array<int, Wallet> */
    private array $passengerWallets = [];

    protected function setUp(): void
    {
        parent::setUp();

        $wallets = $this->seedSystemWallets(1_000_000);
        $this->syCash = $wallets['sycash'];
        $this->walletService = app(WalletTransactionService::class);

        $this->driver = User::factory()->create();

        $this->ride = RideBuilder::for($this->driver)
            ->paymentMethod('e-pay')
            ->price(100)
            ->seats(10)
            ->create();
        $this->ride->load('driver');
    }

    private function chargedBooking(int $seats = 1): Booking
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 500_000,
        ]);

        $booking = Booking::create([
            'user_id' => $user->id,
            'ride_id' => $this->ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0912345678',
        ]);

        $this->walletService->chargePassengerForBooking($booking, $this->ride, $user);

        $this->passengers[] = $user;
        $this->passengerWallets[] = $wallet;

        return $booking->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 1. THE AGGREGATE SUFFICIENCY GUARD
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_set_whose_total_exceeds_sycash_is_refused_before_any_money_moves(): void
    {
        $b1 = $this->chargedBooking(1);   // 100
        $b2 = $this->chargedBooking(1);   // 100  -> the set needs 200

        // SyCash can fund ONE of the two, but not the pair. This is the case a per-booking loop
        // gets wrong: booking 1 would be refunded, then booking 2 would fail, leaving a HALF-REFUNDED
        // cancellation. The set-level check refuses the whole thing up front instead.
        $this->syCash->update(['balance' => 150]);

        $syCashBefore = (float) $this->syCash->fresh()->balance;
        $w1Before = (float) $this->passengerWallets[0]->fresh()->balance;
        $w2Before = (float) $this->passengerWallets[1]->fresh()->balance;
        $rowsBefore = WalletTransaction::count();

        try {
            $this->walletService->refundPassengersForDriverCancellation(
                $this->ride,
                new Collection([$b1, $b2])
            );
            $this->fail('a set costing 200 must not be refunded from a SyCash holding 150');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/insufficient sycash balance/i', $e->getMessage());
        }

        $this->assertSame($syCashBefore, (float) $this->syCash->fresh()->balance, 'SyCash must not have moved');
        $this->assertSame($w1Before, (float) $this->passengerWallets[0]->fresh()->balance, 'passenger 1 must not have been credited');
        $this->assertSame($w2Before, (float) $this->passengerWallets[1]->fresh()->balance, 'passenger 2 must not have been credited');
        $this->assertSame($rowsBefore, WalletTransaction::count(), 'no ledger row may be written for a refused set');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 2. SET-SCOPED IDEMPOTENCY
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_second_refund_of_the_same_set_is_refused(): void
    {
        $b1 = $this->chargedBooking(1);
        $b2 = $this->chargedBooking(1);
        $set = new Collection([$b1, $b2]);

        $this->walletService->refundPassengersForDriverCancellation($this->ride, $set);

        $syCashAfterFirst = (float) $this->syCash->fresh()->balance;
        $w1AfterFirst = (float) $this->passengerWallets[0]->fresh()->balance;
        $w2AfterFirst = (float) $this->passengerWallets[1]->fresh()->balance;
        $rowsAfterFirst = WalletTransaction::count();

        // The posting key is derived from the SET, so replaying the same cancellation - as a retry or
        // as a double click - is refused before any balance moves. Refunded once, not twice.
        // Asserted with try/catch rather than expectException so the "nothing moved" checks below
        // actually execute; after expectException they would be unreachable dead code.
        try {
            $this->walletService->refundPassengersForDriverCancellation($this->ride, $set);
            $this->fail('replaying the same cancellation set must be refused');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/already posted/i', $e->getMessage());
        }

        $this->assertSame($syCashAfterFirst, (float) $this->syCash->fresh()->balance, 'SyCash must not have moved twice');
        $this->assertSame($w1AfterFirst, (float) $this->passengerWallets[0]->fresh()->balance, 'passenger 1 must not be credited twice');
        $this->assertSame($w2AfterFirst, (float) $this->passengerWallets[1]->fresh()->balance, 'passenger 2 must not be credited twice');
        $this->assertSame($rowsAfterFirst, WalletTransaction::count(), 'no second set of ledger rows');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 3. ONE SyCash ROW FOR THE WHOLE SET
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function the_set_writes_one_sycash_row_and_one_row_per_passenger(): void
    {
        $b1 = $this->chargedBooking(2);
        $b2 = $this->chargedBooking(3);
        $rowsBefore = WalletTransaction::count();

        $this->walletService->refundPassengersForDriverCancellation(
            $this->ride,
            new Collection([$b1, $b2])
        );

        $syCashRows = WalletTransaction::where('type', 'driver_cancellation_refunds')->count();
        $passengerRows = WalletTransaction::where('type', 'driver_cancellation_refund')->count();

        $this->assertSame(1, $syCashRows, 'exactly ONE SyCash debit for the whole set, not one per booking');
        $this->assertSame(2, $passengerRows, 'one credit per passenger');
        $this->assertSame(3, WalletTransaction::count() - $rowsBefore);

        // And the combined debit is the set total, not one row's worth.
        $this->assertSame(
            500.0,
            abs((float) WalletTransaction::where('type', 'driver_cancellation_refunds')
                ->latest('id')->first()->amount),
            '2x100 + 3x100 = 500 in a single row'
        );
    }
}
