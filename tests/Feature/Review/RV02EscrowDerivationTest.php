<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use App\Support\PostingKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-02 L2 (owner decision D1 = A) — the escrow is the source of truth, and a settlement
 * cannot be applied twice.
 *
 * THE DEFECT BEHIND EVERY TEST HERE
 *
 * `wallet_transactions.transaction_id` was never an idempotency key. It is minted from `time()`
 * plus randomness, so re-running a settlement produced a brand-new, entirely ordinary-looking
 * transaction and paid again, and nothing in the schema could tell a replay from the original.
 * Meanwhile `bookings.escrow_held` existed since RV-40 and NOTHING read or wrote it: not one
 * settlement path consulted it. So the database could not answer "has this already been paid?"
 * at all.
 *
 * THE FIX HAS TWO HALVES, AND BOTH ARE PINNED HERE
 *
 *   1. `escrow_held` is written by the charge and conditionally decremented by every settlement
 *      (`WHERE escrow_held >= :amount`, abort unless exactly one row changed).
 *   2. `wallet_transactions.posting_key` is deterministic per movement and UNIQUE, so a replay
 *      collides at INSERT and the transaction unwinds having moved nothing.
 *
 * The owner's invariant was written as `SyCash balance == SUM(bookings.escrow_held)`. THAT LITERAL
 * FORM IS FALSE FOR THIS SCHEMA and pinning it would pin a lie: `bookings:backfill-money-snapshot`
 * sets `escrow_held = 0` for cash bookings because cash never holds SyCash, while SyCash's
 * balance is unaffected by them. The true invariant, and the one asserted below, is the e-pay
 * form — `SyCash == SUM(escrow_held WHERE payment_method = 'e-pay')`. `R2 sec 77` records the
 * correction; BACKLOG still carries the literal wording.
 *
 * Requires MySQL: the guards use `lockForUpdate`, a conditional `UPDATE ... WHERE`, and the
 * migration's unique index.
 */
class RV02EscrowDerivationTest extends TestCase
{
    use RefreshDatabase;

    private Wallet $syCash;

    private Wallet $primary;

    private User $passenger;

    private User $driver;

    private Wallet $passengerWallet;

    private Wallet $driverWallet;

    private WalletTransactionService $service;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: lockForUpdate + conditional UPDATE + unique index.');
        }

        parent::setUp();

        $this->service = app(WalletTransactionService::class);

        // `kind = system` + `user_id NULL`: lockWalletByPhone refuses anything else (RV-21), and
        // this suite exercises the real guard, so the fixtures must satisfy the real boundary.
        $this->syCash = Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.sycash.phone'),
            'wallet_number' => 'SYS-SYCASH',
            'balance' => 0,
            'kind' => 'system',
        ]);
        $this->primary = Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.system_admin.phone'),
            'wallet_number' => 'SYS-PRIMARY',
            'balance' => 0,
            'kind' => 'system',
        ]);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '091'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '092'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 1. escrow_held is written and conditionally decremented
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function charging_a_booking_records_what_it_holds_in_escrow(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        $this->assertSame('100000.00', (string) $booking->escrow_held);
        $this->assertSame('100000.00', (string) $this->syCash->fresh()->balance);
    }

    /** @test */
    public function settling_a_booking_decrements_its_escrow_exactly_once(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        $this->service->releaseEscrowToDriver($booking, $booking->ride, $this->driver);

        $this->assertSame(
            '0.00',
            (string) $booking->fresh()->escrow_held,
            'a settled booking must hold nothing left in escrow'
        );
    }

    /** @test */
    public function escrow_held_cannot_go_negative(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        // A SECOND booking of the same size keeps SyCash funded after the first settlement, so
        // the pre-existing `assertSufficientBalance` wallet guard cannot be what stops the
        // replay below. Without this the test would pass on the OLD SyCash balance check and
        // prove nothing about escrow_held at all: the new guard has to be shown catching the
        // case the old one cannot see.
        $this->chargedBooking(50_000, 2);

        // Spend the escrow legitimately, via the per-passenger release.
        $this->service->releaseEscrowToDriver($booking, $booking->ride, $this->driver);
        $this->assertSame('0.00', (string) $booking->fresh()->escrow_held);

        // Now try to spend it again through a DIFFERENT movement: the ride-level completion
        // release. It carries a different posting key, so the replay guard cannot see it — this
        // isolates the escrow guard, which is what has to stop it. The two guards failing
        // differently is the point: the key catches "the same movement ran twice", the escrow
        // catches "this booking's escrow is already spent" even when the movement differs.
        try {
            $this->service->releaseEarningsToDriver(
                $booking->ride,
                new Collection([$booking])
            );
            $this->fail('A second settlement of the same booking must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already been spent', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) DB::table('bookings')->whereRaw('escrow_held < 0')->count(),
            'no booking may ever hold negative escrow'
        );
        $this->assertSame(
            '0.00',
            (string) $booking->fresh()->escrow_held,
            'the refused settlement must leave the balance exactly as it was'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 2. A replayed settlement moves no money
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function replaying_a_settlement_moves_no_money(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        // SyCash must still be able to cover a replay AFTER the first settlement, or the
        // pre-existing balance guard would reject it and this test would prove nothing about the
        // new one. Same size as the booking under test, so the refund exactly.
        $this->chargedBooking(50_000, 2);

        $syCashAfterCharge = (float) $this->syCash->fresh()->balance;
        $driverAfterCharge = (float) $this->driverWallet->fresh()->balance;

        $this->service->releaseEscrowToDriver($booking, $booking->ride, $this->driver);

        $syCashAfterSettle = (float) $this->syCash->fresh()->balance;
        $driverAfterSettle = (float) $this->driverWallet->fresh()->balance;

        $transactionsAfterSettle = WalletTransaction::count();

        $this->assertSame(-100000.0, round($syCashAfterSettle - $syCashAfterCharge, 2));
        $this->assertSame(95000.0, round($driverAfterSettle - $driverAfterCharge, 2));

        // ── The replay: the same call again, exactly as a retried request would arrive ──
        try {
            $this->service->releaseEscrowToDriver($booking, $booking->ride, $this->driver);
            $this->fail('A replayed settlement must be refused, not paid again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('RV-02 L2', $e->getMessage());
        }

        $this->assertSame(
            $syCashAfterSettle,
            (float) $this->syCash->fresh()->balance,
            'a replay must not move escrow'
        );
        $this->assertSame(
            $driverAfterSettle,
            (float) $this->driverWallet->fresh()->balance,
            'a replay must not pay the driver twice'
        );
        $this->assertSame(
            $transactionsAfterSettle,
            WalletTransaction::count(),
            'a replay must not write a second set of transactions'
        );
    }

    /** @test */
    public function the_posting_key_is_deterministic_and_unique(): void
    {
        $this->assertTrue(
            Schema::hasColumn('wallet_transactions', 'posting_key'),
            'precondition: the column exists'
        );

        $booking = $this->chargedBooking(50_000, 1);

        $keys = WalletTransaction::whereNotNull('posting_key')->pluck('posting_key')->all();

        $this->assertContains(
            "booking:{$booking->id}:escrow-in",
            $keys,
            'the escrow credit carries a deterministic key derived from the booking'
        );
        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            'every posting_key in the table is unique'
        );

        // Determinism is the mechanism, so it is asserted directly rather than inferred: the
        // same booking + same action must recompute the same key on a later, separate run.
        $this->assertSame(
            PostingKey::build('booking:'.$booking->id, 'escrow-in'),
            "booking:{$booking->id}:escrow-in"
        );
    }

    /**
     * @test
     */
    public function a_second_charge_of_the_same_booking_is_refused(): void
    {
        $booking = $this->chargedBooking(50_000, 1);
        $syCashAfterCharge = (float) $this->syCash->fresh()->balance;

        $this->expectException(RuntimeException::class);

        try {
            $this->service->chargePassengerForBooking($booking, $booking->ride, $this->passenger);
        } finally {
            $this->assertSame(
                $syCashAfterCharge,
                (float) $this->syCash->fresh()->balance,
                'a re-charge must not put a second payment into escrow'
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 3. The invariant - e-pay form
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function sycash_equals_the_sum_of_escrow_held_for_epay_bookings(): void
    {
        $this->chargedBooking(50_000, 2);
        $this->chargedBooking(20_000, 3);

        $this->assertEscrowInvariantHolds('after two charges, nothing settled');

        // Settle one booking in full. The invariant must STILL hold - escrow and SyCash move
        // together, which is the whole claim.
        $settled = Booking::with('ride')->orderBy('id')->first();
        $this->service->releaseEscrowToDriver($settled, $settled->ride, $this->driver);

        $this->assertEscrowInvariantHolds('after one booking is settled');
    }

    /** @test */
    public function the_invariant_also_holds_across_a_cancellation(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        $policy = ['refund_percentage' => 70, 'time_elapsed_percentage' => 40, 'policy_tier' => '30-50%'];
        $this->service->processTimeBasedCancellation($booking, $booking->ride, 1, $policy);

        $this->assertEscrowInvariantHolds('after a partial-seat cancellation');
        $this->assertSame(
            '50000.00',
            (string) $booking->fresh()->escrow_held,
            'the cancelled seat\'s escrow is gone; the seat that stays booked keeps its own'
        );
    }

    /**
     * @test
     */
    public function a_cash_booking_holds_no_escrow_and_is_not_counted_by_the_invariant(): void
    {
        // An e-pay booking that IS in escrow, and a cash booking that never was. The cash row
        // is a genuine uncharged booking, exactly as `bookings:backfill-money-snapshot` records
        // one: escrow_held 0, payment_method cash, no `escrow_received` row anywhere.
        $this->chargedBooking(50_000, 2);

        $cashRide = RideBuilder::for($this->driver)
            ->price(50_000)
            ->withAttributes(['payment_method' => 'cash', 'status' => 'active'])
            ->create();

        $cash = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $cashRide->id,
            'seats' => 2,
            'status' => 'confirmed',
            'communication_number' => '0912345678',
            'payment_method' => 'cash',
            'escrow_held' => 0,
        ]);

        $this->assertSame('0.00', (string) $cash->fresh()->escrow_held);
        $this->assertEscrowInvariantHolds('with a cash booking present');

        // The e-pay filter is what makes this a statement about ESCROW rather than about the
        // wallet: a cash row contributes 0 and, because it also never moved SyCash, the two
        // happen to agree here. That agreement is a property of this fixture, not the invariant
        // — so the assertion above is the e-pay form deliberately, and BACKLOG's literal
        // all-bookings wording is left uncorrected only in that file.
        $this->assertSame(
            0.0,
            (float) DB::table('bookings')->where('payment_method', 'cash')->sum('escrow_held'),
            'a cash booking never holds SyCash escrow'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 4. Partial seat cancel must not collide with the full settlement key
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function successive_partial_cancels_each_get_their_own_posting_key(): void
    {
        $booking = $this->chargedBooking(50_000, 2);

        $policy = ['refund_percentage' => 70, 'time_elapsed_percentage' => 40, 'policy_tier' => '30-50%'];

        // Cancel one seat, then the other. Two real, distinct movements on one booking - the
        // key must distinguish them or the second cancel would be silently refused.
        $this->service->processTimeBasedCancellation($booking, $booking->ride, 1, $policy);
        $booking->update(['seats' => 1]);

        $this->service->processTimeBasedCancellation($booking->fresh(['ride']), $booking->ride, 1, $policy);

        $this->assertSame(
            '0.00',
            (string) $booking->fresh()->escrow_held,
            'both seats were refunded, so nothing is left in escrow'
        );

        $cancelKeys = WalletTransaction::where('type', 'cancellation_processing')
            ->where('reference', "booking:{$booking->id}")
            ->pluck('posting_key')
            ->all();

        $this->assertCount(2, $cancelKeys, 'both cancellations were recorded');
        $this->assertSame(
            count($cancelKeys),
            count(array_unique($cancelKeys)),
            'a partial cancel uses a key distinct from the next one'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 5. The owner constraint: the money itself is untouched
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function the_95_5_split_is_exactly_what_it_was(): void
    {
        $booking = $this->chargedBooking(50_000, 1);

        $driverBefore = (float) $this->driverWallet->fresh()->balance;
        $primaryBefore = (float) $this->primary->fresh()->balance;

        $this->service->releaseEscrowToDriver($booking, $booking->ride, $this->driver);

        $this->assertSame(47500.0, round((float) $this->driverWallet->fresh()->balance - $driverBefore, 2));
        $this->assertSame(2500.0, round((float) $this->primary->fresh()->balance - $primaryBefore, 2));
    }

    /**
     * @test
     */
    public function every_refund_tier_still_pays_the_percentage_it_paid_before(): void
    {
        // The owner's hard constraint was that ALL refund tiers stay byte-identical. The four
        // ELAPSED tiers are what `calculateRefundPolicy` produces (0-30 / 30-50 / 50-70 /
        // 70-100) and they are what `processTimeBasedCancellation` acts on, so they are the
        // ones asserted here - against the AMOUNTS rather than the code, so changing a
        // percentage fails even if the arithmetic is rewritten cleverly. The fifth outcome
        // (departure already passed -> 0%) is policy resolution rather than settlement, and is
        // pinned by `Tests\Unit\Services\WalletTransactionServiceRefundPolicyTest`.
        //
        // With the 95/5 split asserted above, that covers every way this service divides money,
        // all of it unchanged.
        $tiers = [
            ['refund_percentage' => 100, 'passenger' => 100000.0, 'driver' => 0.0],
            ['refund_percentage' => 70, 'passenger' => 70000.0, 'driver' => 30000.0],
            ['refund_percentage' => 50, 'passenger' => 50000.0, 'driver' => 50000.0],
            ['refund_percentage' => 0, 'passenger' => 0.0, 'driver' => 100000.0],
        ];

        foreach ($tiers as $tier) {
            $booking = $this->chargedBooking(50_000, 2);
            $policy = [
                'refund_percentage' => $tier['refund_percentage'],
                'time_elapsed_percentage' => 10,
                'policy_tier' => 'tier-'.$tier['refund_percentage'],
            ];

            $passengerBefore = (float) $this->passengerWallet->fresh()->balance;
            $driverBefore = (float) $this->driverWallet->fresh()->balance;

            $this->service->processTimeBasedCancellation($booking, $booking->ride, 2, $policy);

            $this->assertSame(
                $tier['passenger'],
                round((float) $this->passengerWallet->fresh()->balance - $passengerBefore, 2),
                "passenger share at the {$tier['refund_percentage']}% tier"
            );
            $this->assertSame(
                $tier['driver'],
                round((float) $this->driverWallet->fresh()->balance - $driverBefore, 2),
                "driver share at the {$tier['refund_percentage']}% tier"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 6. Migration behaviour — verified out of band, deliberately NOT a test here
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // Migration A's fail-loud duplicate branch cannot be asserted from this file. Reaching it
    // requires dropping the unique index first, and `ALTER TABLE` on MySQL performs an IMPLICIT
    // COMMIT, which destroys RefreshDatabase's per-test transaction and commits everything this
    // test wrote - leaking it into every test that runs after it. That was not theoretical: the
    // suite DID carry this test, and it turned four unrelated wallet-phone uniqueness failures
    // green-to-red in files that have nothing to do with escrow. Shipping it would have reopened
    // RV-37 (test hermeticity), which this repo closed deliberately.
    //
    // The branch was verified instead, out of band against the scratch database:
    //   - index dropped, two rows sharing posting_key 'dup:probe:key' inserted, migration re-run:
    //     it THREW and named the offending key ("duplicate non-null values ... dup:probe:key");
    //   - index present: MySQL refused both the duplicate INSERT and a duplicate
    //     `ADD UNIQUE INDEX` on its own - "Duplicate entry 'dup:probe:key' for key
    //     'wallet_transactions.wallet_transactions_posting_key_unique'".
    // Both directions are recorded in `R2 sec 81`.

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * The e-pay form of the owner's invariant.
     *
     * NOT `SyCash == SUM(escrow_held)` over every booking: cash bookings hold 0 there while
     * SyCash is unaffected by them, so that literal form is false for this schema. See the
     * class docblock and `R2 sec 77`.
     */
    private function assertEscrowInvariantHolds(string $when): void
    {
        $expected = (float) DB::table('bookings')
            ->where('payment_method', 'e-pay')
            ->sum('escrow_held');

        $actual = (float) $this->syCash->fresh()->balance;

        $this->assertSame(
            round($expected, 2),
            round($actual, 2),
            "SyCash must equal the e-pay escrow held ({$when})"
        );
    }

    private function chargedBooking(float $pricePerSeat, int $seats): Booking
    {
        $ride = RideBuilder::for($this->driver)
            ->price($pricePerSeat)
            ->withAttributes([
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'departure_time' => now()->addHours(3),
            ])
            ->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0912345678',
        ]);

        $this->service->chargePassengerForBooking($booking, $ride->fresh(), $this->passenger->fresh());

        return $booking->fresh(['ride']);
    }
}
