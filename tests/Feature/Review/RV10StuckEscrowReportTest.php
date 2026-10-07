<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RV-10 / decision D2 first half: the READ-ONLY stuck-escrow reporter.
 *
 * The property under test is that correlation is by MONEY MOVED, not by the name of the settlement
 * type. Escrow leaves SyCash through payout, refund, no-show and cancellation paths, and the type
 * vocabulary has already drifted three times (see App\Enums\LedgerType). A reporter keyed on
 * "escrow_received with no escrow_release" would call every refunded and no-show-settled booking
 * stuck, so those cases get their own tests here.
 */
class RV10StuckEscrowReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build one booking whose SyCash wallet takes money in and (optionally) gives some back.
     *
     * @param  float  $in  amount credited to SyCash as escrow
     * @param  array<int, array{0: string, 1: float}>  $outLegs  [type, amount] pairs leaving SyCash
     */
    /**
     * Built directly rather than via Ride::factory().
     *
     * RideFactory writes `pickup_location` with DB::raw(...), which is a Query\Expression, and
     * Ride::setPickupLocationAttribute() type-hints `array` - so every Ride::factory()->create()
     * in the suite dies with a TypeError. That is a pre-existing factory defect (filed separately);
     * no test in the repo currently calls the factory, because RV11RideCountTest:350 documents the
     * same trap in a comment. Here the ride is constructed with the lat/lng arrays the mutator wants,
     * which is also the path real writers use via GeoPoint.
     */
    private function makeRide(?int $departureDaysAgo): Ride
    {
        // forceCreate, not create: pickup_location / destination_location are NOT NULL with no
        // default and are absent from Ride::$fillable, so they can only be supplied by a write that
        // bypasses the mass-assignment guard. The coordinate mutators still run, so this is the same
        // path a real writer takes through GeoPoint (it also fills pickup_lat / pickup_lng).
        $ride = Ride::forceCreate([
            'driver_id' => User::factory()->create()->id,
            'pickup_address' => 'Damascus - Midan',
            'destination_address' => 'Homs - Waal',
            'pickup_location' => ['lat' => 33.5138, 'lng' => 36.2765],
            'destination_location' => ['lat' => 34.7324, 'lng' => 36.7137],
            'departure_time' => $departureDaysAgo === null
                ? now()->addDay()
                : now()->subDays($departureDaysAgo),
            'available_seats' => 4,
            'price_per_seat' => 50_000,
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'status' => 'active',
            'distance' => 220.5,
            'duration' => 180.0,
            'communication_number' => '0912345678',
        ]);

        return $ride;
    }

    private function bookingWithEscrow(float $in, array $outLegs = [], ?int $departureDaysAgo = null): array
    {
        $passenger = User::factory()->create();

        $ride = $this->makeRide($departureDaysAgo);

        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'confirmed',
        ]);

        $syCash = Wallet::create([
            'user_id' => $passenger->id,
            'wallet_number' => (string) random_int(100000, 999999),
            'phone_number' => '+9637'.random_int(10000000, 99999999),
            'balance' => 0,
        ]);

        $txId = 'T'.random_int(100000, 999999);
        WalletTransaction::create([
            'wallet_id' => $syCash->id,
            'user_id' => null,
            'type' => 'escrow_received',
            'amount' => $in,
            'previous_balance' => 0,
            'new_balance' => $in,
            'transaction_id' => 'SYCASH_'.$txId,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        // Walk the balance forward one leg at a time, exactly as the real writers do.
        $balance = $in;
        foreach ($outLegs as $i => [$type, $amount]) {
            $before = $balance;
            $balance -= $amount;
            WalletTransaction::create([
                'wallet_id' => $syCash->id,
                'user_id' => null,
                'type' => $type,
                'amount' => $amount,
                'previous_balance' => $before,
                'new_balance' => $balance,
                'transaction_id' => 'SYCASH_'.$txId.'_'.$i,
                'status' => 'completed',
                'reference' => "booking:{$booking->id}",
            ]);
        }

        $syCash->forceFill(['balance' => $balance])->save();

        return [$booking, $syCash, $passenger];
    }

    /** @param array<string, string> $options */
    private function runReport(array $options = []): string
    {
        // Options go on the command string rather than as an ArrayInput map: Symfony's ArrayInput
        // reads an associative key as an ARGUMENT name, not an option, and rejects it.
        $command = 'escrow:stuck-report';
        foreach ($options as $name => $value) {
            $command .= ' --'.$name.'='.$value;
        }

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call($command);

        $this->assertSame(0, $exit, 'the reporter must always exit successfully');

        return Artisan::output();
    }

    public function test_it_reports_escrow_that_never_left_sy_cash(): void
    {
        $this->bookingWithEscrow(120.00, [], 5);

        $out = $this->runReport();

        $this->assertStringContainsString('Escrow still held in SyCash', $out);
        $this->assertStringContainsString('120.00', $out);
    }

    public function test_it_does_not_report_escrow_that_was_paid_out(): void
    {
        $this->bookingWithEscrow(120.00, [['escrow_release', 120.00]], 5);

        $out = $this->runReport();

        $this->assertStringContainsString('No escrow is currently held in SyCash', $out);
    }

    /**
     * The regression this design exists for: escrow left through a REFUND, not a release. A reporter
     * keyed on the escrow_release type name would report this as stuck forever.
     */
    public function test_it_does_not_report_escrow_settled_by_a_refund_with_no_release_leg(): void
    {
        $this->bookingWithEscrow(120.00, [['time_based_refund', 120.00]], 5);

        $out = $this->runReport();

        $this->assertStringContainsString('No escrow is currently held in SyCash', $out);
    }

    public function test_it_does_not_report_a_no_show_settlement(): void
    {
        $this->bookingWithEscrow(80.00, [['passenger_no_show_settlement', 80.00]], 5);

        $out = $this->runReport();

        $this->assertStringContainsString('No escrow is currently held in SyCash', $out);
    }

    /** Partially settled: only the difference is still held, and that difference is what is reported. */
    public function test_it_reports_only_the_difference_when_a_booking_is_partially_settled(): void
    {
        $this->bookingWithEscrow(100.00, [['escrow_release', 60.00]], 5);

        $out = $this->runReport();

        $this->assertStringContainsString('40.00', $out);
        $this->assertStringNotContainsString('100.00', $out);
    }

    /**
     * Every booking also writes a leg to the PASSENGER's own wallet under the same reference. That leg
     * must not be mistaken for held money.
     */
    public function test_it_ignores_the_passenger_wallet_leg_for_the_same_booking(): void
    {
        [, , $passenger] = $this->bookingWithEscrow(200.00, [['escrow_release', 200.00]], 5);

        $passengerWallet = Wallet::create([
            'user_id' => $passenger->id,
            'wallet_number' => (string) random_int(100000, 999999),
            'phone_number' => '+9637'.random_int(10000000, 99999999),
            'balance' => 0,
        ]);

        // Same reference, a different wallet, and money LEAVING - the passenger's debit.
        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $passenger->id,
            'type' => 'ride_booking_payment',
            'amount' => 200.00,
            'previous_balance' => 200.00,
            'new_balance' => 0.00,
            'transaction_id' => 'PW'.random_int(100000, 999999),
            'status' => 'completed',
            'reference' => 'booking:'.Booking::query()->latest('id')->value('id'),
        ]);

        $out = $this->runReport();

        $this->assertStringContainsString('No escrow is currently held in SyCash', $out);
    }

    /** The command is a report. It must not post, move, or alter a single row. */
    public function test_it_moves_no_money(): void
    {
        $this->bookingWithEscrow(75.00, [], 3);

        $ledgerBefore = DB::table('wallet_transactions')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
        $walletBefore = DB::table('wallets')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
        $countBefore = DB::table('wallet_transactions')->count();

        $this->runReport();

        $this->assertSame($countBefore, DB::table('wallet_transactions')->count(), 'row count changed');
        $this->assertSame($walletBefore, DB::table('wallets')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all(), 'a wallet row changed');
        $this->assertSame($ledgerBefore, DB::table('wallet_transactions')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all(), 'a ledger row changed');
    }

    /** D2 says W must come from this reporter's data, so the histogram is printed, not a window applied. */
    public function test_it_prints_an_age_histogram_for_the_window_decision(): void
    {
        $this->bookingWithEscrow(50.00, [], 10);   // 10 days old
        $this->bookingWithEscrow(30.00, [], 2);    // 2 days old

        $out = $this->runReport();

        $this->assertStringContainsString('Age histogram', $out);
        $this->assertStringContainsString('Bookings', $out);
        $this->assertStringContainsString('Amount held', $out);
        $this->assertStringContainsString('choose it from the histogram', $out);
    }

    /**
     * --min-age-hours narrows the summary and the detail view, but deliberately NOT the histogram.
     *
     * That asymmetry is the point: D2 says W must be chosen from the reporter's data, so filtering the
     * histogram with a guessed window would destroy the evidence the decision is made from.
     */
    public function test_min_age_hours_narrows_the_summary_but_not_the_histogram(): void
    {
        $this->bookingWithEscrow(50.00, [], 10);
        $this->bookingWithEscrow(30.00, [], 2);

        // 72h, not 48h: the 2-day booking is exactly 48 hours old and --min-age-hours is inclusive, so a
        // 48h threshold would keep BOTH and this assertion would prove nothing.
        $narrowed = $this->runReport(['min-age-hours' => '72']);

        $this->assertStringContainsString('1 booking(s), 50.00 total', $narrowed);
        $this->assertStringNotContainsString('2 booking(s)', $narrowed);

        // The histogram still shows both, so W can still be chosen from it.
        $this->assertStringContainsString('Age histogram', $narrowed);
        $this->assertStringContainsString('50.00', $narrowed);
        $this->assertStringContainsString('30.00', $narrowed);
    }

    /**
     * Pins WHY the command has no "cannot correlate" path.
     *
     * The whole design correlates escrow by summing (new_balance - previous_balance), so a NULL in
     * either column would silently sum as zero and hide held money. The schema forbids it, which is
     * what makes money-based correlation safe here. If this test ever fails, the reporter needs an
     * explicit undetermined path before it can still be trusted.
     */
    public function test_balance_columns_are_not_null_so_every_leg_can_be_correlated(): void
    {
        $this->bookingWithEscrow(60.00, [], 4);

        foreach (['previous_balance', 'new_balance'] as $column) {
            $meta = DB::selectOne(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['wallet_transactions', $column]
            );

            $this->assertSame('NO', $meta->IS_NULLABLE, $column.' must be NOT NULL');
        }

        // And the database really does refuse the row the design depends on being unable to hold.
        $this->expectException(QueryException::class);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => Wallet::query()->value('id'),
            'user_id' => null,
            'type' => 'escrow_received',
            'amount' => 10,
            'previous_balance' => null,
            'new_balance' => null,
            'transaction_id' => 'NULLBAL'.random_int(1000, 9999),
            'status' => 'completed',
            'reference' => 'booking:'.(Booking::query()->latest('id')->value('id') + 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
