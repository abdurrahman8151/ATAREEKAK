<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L19 (modular-monolith slice 17, MONEY): ledger-row characterization of
 * WalletTransactionService::processTimeBasedCancellation (refactored: recordPassengerRefund,
 * recordDriverCompensation).
 *
 * WalletTransactionServiceTest pins the balances per tier. This file pins the ledger rows each tier
 * writes, so a refactor that drops or renames a row fails here even when the balances still match:
 *   - SyCash debit (cancellation_processing) on every cancellation;
 *   - passenger: time_based_refund when the refund is positive, cancellation_no_refund (0.00) when it is zero;
 *   - driver: cancellation_fee_earnings only when the driver is owed something.
 */
class L19TimeBasedCancellationLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private WalletTransactionService $service;

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

    private Wallet $syCashWallet;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WalletTransactionService;

        $this->seedSystemWallets(10_000_000.0);
        $this->syCashWallet = Wallet::where('phone_number', config('admin.sycash.phone'))->first();

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

        $this->ride = RideBuilder::for($this->driver)->paymentMethod('e-pay')->create();
    }

    /** A confirmed, escrow-funded booking for the passenger (same fixture as WalletTransactionServiceTest). */
    private function chargedBooking(int $seats): Booking
    {
        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $this->ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $this->ride, $this->passenger);

        return $booking;
    }

    /** Ledger rows of one type for this booking, as a list. */
    private function rows(Booking $booking, string $type): Collection
    {
        return WalletTransaction::where('reference', "booking:{$booking->id}")->where('type', $type)->get();
    }

    public function test_a_partial_refund_writes_a_passenger_refund_row_and_a_driver_compensation_row(): void
    {
        $booking = $this->chargedBooking(1);
        $totalPaid = (float) $this->ride->price_per_seat;
        $refund = $totalPaid * 0.70;
        $driverShare = $totalPaid - $refund;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, [
            'refund_percentage' => 70, 'time_elapsed_percentage' => 40, 'policy_tier' => '30-50%',
        ]);

        $refundRow = $this->rows($booking, 'time_based_refund');
        $driverRow = $this->rows($booking, 'cancellation_fee_earnings');
        $this->assertCount(1, $refundRow, 'exactly one passenger refund row');
        $this->assertCount(1, $driverRow, 'exactly one driver compensation row');
        $this->assertEqualsWithDelta($refund, (float) $refundRow->first()->amount, 0.01);
        $this->assertEqualsWithDelta($driverShare, (float) $driverRow->first()->amount, 0.01);
        $this->assertCount(0, $this->rows($booking, 'cancellation_no_refund'), 'no zero-refund audit row when a refund is paid');
    }

    public function test_a_zero_refund_writes_a_no_refund_audit_row_and_no_passenger_refund_row(): void
    {
        $booking = $this->chargedBooking(1);

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, [
            'refund_percentage' => 0, 'time_elapsed_percentage' => 80, 'policy_tier' => '70-100%',
        ]);

        $this->assertCount(1, $this->rows($booking, 'cancellation_no_refund'), 'one zero-refund audit row for the passenger');
        $this->assertCount(0, $this->rows($booking, 'time_based_refund'), 'no refund row when the refund is zero');
        $this->assertCount(1, $this->rows($booking, 'cancellation_fee_earnings'), 'the driver takes the whole fare');
    }

    public function test_a_full_refund_writes_a_passenger_refund_row_and_no_driver_row(): void
    {
        $booking = $this->chargedBooking(1);
        $totalPaid = (float) $this->ride->price_per_seat;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, [
            'refund_percentage' => 100, 'time_elapsed_percentage' => 10, 'policy_tier' => 'Full refund',
        ]);

        $refundRow = $this->rows($booking, 'time_based_refund');
        $this->assertCount(1, $refundRow, 'one passenger refund row');
        $this->assertEqualsWithDelta($totalPaid, (float) $refundRow->first()->amount, 0.01, 'the passenger is refunded the whole fare');
        $this->assertCount(0, $this->rows($booking, 'cancellation_fee_earnings'), 'the driver owed nothing: no compensation row');
    }

    public function test_every_cancellation_debits_sycash_escrow_once(): void
    {
        $booking = $this->chargedBooking(1);
        $totalPaid = (float) $this->ride->price_per_seat;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, [
            'refund_percentage' => 50, 'time_elapsed_percentage' => 60, 'policy_tier' => '50-70%',
        ]);

        $debits = $this->rows($booking, 'cancellation_processing');
        $this->assertCount(1, $debits, 'one SyCash escrow debit row');
        $this->assertEqualsWithDelta(-$totalPaid, (float) $debits->first()->amount, 0.01);
    }
}
