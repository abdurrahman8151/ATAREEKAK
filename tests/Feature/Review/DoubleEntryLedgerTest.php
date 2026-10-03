<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\LedgerService;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Decision un3 (owner, 2026-10-02): double-entry.
 *
 * The invariant that makes a ledger trustworthy is ONE rule: **the signed legs of a transfer sum to
 * zero.** The single-sided `wallet_transactions` table cannot express it, so a half-written movement
 * is invisible; these tests make it enforceable.
 *
 * THE REFERENCE PATH is `chargePassengerForBooking` - passenger's wallet to SyCash escrow, the most
 * important money movement in the product. It is converted; the rest convert the same way, one at a
 * time, each verified to leave balances UNCHANGED. That is why this file asserts both halves: the
 * ledger is balanced AND the behaviour is exactly what it was before.
 */
class DoubleEntryLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    private Wallet $passengerWallet;

    private WalletTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletTransactionService::class);

        $this->driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);
        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true, 'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $this->seedSystemWallets(10_000_000.0);

        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '093'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 50_000.0,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    private function makeBooking(int $seats = 2, float $pricePerSeat = 10_000.0): Booking
    {
        $ride = RideBuilder::forUserId($this->driver->id)
            ->withAttributes([
                'pickup_address' => 'Damascus', 'destination_address' => 'Aleppo',
                'available_seats' => 4, 'price_per_seat' => $pricePerSeat,
                'payment_method' => 'e-pay', 'booking_type' => 'direct',
                'status' => 'full', 'communication_number' => '0911000000',
            ])
            ->departureTime(now()->addHours(3))
            ->create();

        return Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id,
            'seats' => $seats, 'status' => 'confirmed',
            'communication_number' => '0912345678',
            'unit_price' => $pricePerSeat, 'amount_paid' => 0, 'payment_method' => 'e-pay',
        ]);
    }

    /** @test */
    public function charging_a_passenger_writes_balanced_ledger_legs(): void
    {
        $booking = $this->makeBooking(2, 10_000.0);
        $charge = $booking->seats * 10_000.0;

        $this->service->chargePassengerForBooking($booking, $booking->ride, $this->passenger);

        $legs = LedgerEntry::orderBy('id')->get();
        $this->assertCount(2, $legs, 'a two-party transfer must record exactly two legs');

        $this->assertSame(0.0, round((float) $legs->sum('amount'), 2),
            'THE INVARIANT: the signed legs of a transfer must sum to zero');

        // The debit is the passenger, the credit is SyCash - the two halves of the SAME event.
        $debit = $legs->firstWhere('wallet_id', $this->passengerWallet->id);
        $credit = $legs->firstWhere('wallet_id', $this->syCashWallet()->id);

        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame(-$charge, (float) $debit->amount);
        $this->assertSame($charge, (float) $credit->amount);
    }

    /**
     * The ledger is a WITNESS, not a second mover of money: every balance and every
     * single-sided row must be exactly what it was before this feature existed.
     */
    /** @test */
    public function the_ledger_does_not_change_any_balance_or_existing_row(): void
    {
        $booking = $this->makeBooking(2, 10_000.0);
        $charge = 20_000.0;

        $passengerBefore = (float) $this->passengerWallet->balance;
        $syCashBefore = (float) $this->syCashWallet()->balance;

        $this->service->chargePassengerForBooking($booking, $booking->ride, $this->passenger);

        $this->assertSame($passengerBefore - $charge, (float) $this->passengerWallet->fresh()->balance);
        $this->assertSame($syCashBefore + $charge, (float) $this->syCashWallet()->fresh()->balance);

        // Exactly the two rows the old code wrote - no more.
        $this->assertSame(2, WalletTransaction::count());
        $this->assertSame($charge, (float) $booking->fresh()->amount_paid);
    }

    /** @test */
    public function an_unbalanced_transfer_is_refused_rather_than_recorded(): void
    {
        $ledger = app(LedgerService::class);

        try {
            $ledger->postTransfer([
                ['wallet_id' => $this->passengerWallet->id, 'amount' => -100.0],
                ['wallet_id' => $this->syCashWallet()->id, 'amount' => 99.0],
            ]);
            $this->fail('An unbalanced transfer must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unbalanced', $e->getMessage());
        }

        $this->assertSame(0, LedgerEntry::count(),
            'a refused transfer must leave NO ledger rows behind');
    }

    /** @test */
    public function a_single_leg_is_refused(): void
    {
        $ledger = app(LedgerService::class);

        $this->expectException(RuntimeException::class);

        // One leg is a single-sided record - exactly what double-entry replaces.
        $ledger->postTransfer([['wallet_id' => $this->passengerWallet->id, 'amount' => -100.0]]);
    }

    /** @test */
    public function a_three_way_split_still_balances(): void
    {
        // The real flows are not always two-party (the 95/5 platform split), so the ledger is
        // N-sided. This is why legs are rows rather than a from/to pair of columns.
        $primary = Wallet::where('phone_number', config('admin.system_admin.phone'))->firstOrFail();

        app(LedgerService::class)->postTransfer([
            ['wallet_id' => $this->passengerWallet->id, 'amount' => -100.0],
            ['wallet_id' => $this->syCashWallet()->id, 'amount' => 95.0],
            ['wallet_id' => $primary->id, 'amount' => 5.0],
        ]);

        $this->assertSame(0.0, round((float) LedgerEntry::sum('amount'), 2));
        $this->assertSame(3, LedgerEntry::count());
    }

    private function syCashWallet(): Wallet
    {
        return Wallet::where('phone_number', config('admin.sycash.phone'))->firstOrFail();
    }

    /**
     * The 95/5 settlement split - the transfer that most needed a ledger, because it is THREE
     * parties moving as ONE event: SyCash gives, the driver receives 95%, the platform 5%.
     *
     * This is also where arithmetic drift would hide: if the shares ever stopped summing to
     * `$total`, a single-sided ledger would record a perfectly plausible-looking payout while
     * creating or destroying money. `postTransfer` refuses instead.
     *
     * @test
     */
    public function the_95_5_settlement_writes_three_legs_that_balance(): void
    {
        $ride = RideBuilder::forUserId($this->driver->id)
            ->withAttributes([
                'pickup_address' => 'Damascus', 'destination_address' => 'Aleppo',
                'available_seats' => 4, 'price_per_seat' => 10_000,
                'payment_method' => 'e-pay', 'booking_type' => 'direct',
                'status' => 'active', 'communication_number' => '0911000000',
            ])
            ->departureTime(now()->subHour())
            ->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id,
            'seats' => 2, 'status' => 'confirmed',
            'communication_number' => '0912345678',
            'unit_price' => 10_000, 'amount_paid' => 20_000, 'payment_method' => 'e-pay',
        ]);

        // Fund escrow as the charge would have.
        $this->service->chargePassengerForBooking($booking, $ride, $this->passenger);
        LedgerEntry::query()->delete();   // isolate: measure the settlement's own legs

        // The driver needs a wallet to receive into; the factory does not create one.
        $driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '095'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $driverWallet->id]);

        $this->service->releaseEarningsToDriver($ride, Booking::whereIn('id', [$booking->id])->get());

        $legs = LedgerEntry::orderBy('id')->get();
        $this->assertCount(3, $legs, 'the 95/5 split is one transfer with three legs');
        $this->assertSame(0.0, round((float) $legs->sum('amount'), 2),
            'THE INVARIANT: escrow out must equal driver + platform shares exactly');

        $driverLeg = $legs->firstWhere('wallet_id', $driverWallet->id);
        $platformLeg = $legs->firstWhere('wallet_id', Wallet::where('phone_number', config('admin.system_admin.phone'))->value('id'));
        $escrowLeg = $legs->firstWhere('wallet_id', $this->syCashWallet()->id);

        $this->assertNotNull($driverLeg);
        $this->assertNotNull($platformLeg);
        $this->assertNotNull($escrowLeg);

        $this->assertSame(-20_000.0, (float) $escrowLeg->amount, 'the whole escrow leaves SyCash');
        $this->assertSame(19_000.0, (float) $driverLeg->amount, '95% of 20,000');
        $this->assertSame(1_000.0, (float) $platformLeg->amount, '5% of 20,000');
    }
}
