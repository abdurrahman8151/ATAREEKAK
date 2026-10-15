<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\BookingCharge;
use App\Services\Payment\CancellationRefund;
use App\Services\Payment\LedgerService;
use App\Services\Payment\WalletAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L20 (modular-monolith slice 18, MONEY): ledger-row characterization of
 * WalletTransactionService::refundPassengersForStaffCancellation (refactored: the per-passenger
 * body is now refundPassengerForStaffCancellation).
 *
 * Pins, for a staff cancellation of several passengers:
 *   - exactly one SyCash debit (staff_cancellation_refunds) for the whole set;
 *   - exactly one staff_cancellation_refund row per passenger, for that passenger's amount_paid;
 *   - the escrow leg total equals the refund total (money in equals money out);
 *   - a booking with no amount_paid snapshot is never refunded and is counted for review.
 */
class L20StaffCancellationLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private CancellationRefund $service;

    private User $driver;

    private Wallet $syCashWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CancellationRefund(new WalletAccess, new LedgerService);

        $this->seedSystemWallets(10_000_000.0);
        $this->syCashWallet = Wallet::where('phone_number', config('admin.sycash.phone'))->first();

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '091'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $driverWallet->id]);
    }

    /** A passenger with a funded wallet and one escrow-charged confirmed booking. */
    private function passengerBooking(Ride $ride, int $seats): Booking
    {
        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        $wallet = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => '092'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $passenger->update(['wallet_id' => $wallet->id]);

        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(BookingCharge::class)->chargePassengerForBooking($booking, $ride, $passenger);

        return $booking->fresh();
    }

    /**
     * The service is typed to Eloquent's Collection (what BookingService passes in), not the Support one.
     *
     * @param  array<int, Booking>  $bookings
     */
    private function eloquentSet(array $bookings): \Illuminate\Database\Eloquent\Collection
    {
        return new \Illuminate\Database\Eloquent\Collection($bookings);
    }

    /**
     * Ledger rows of one type for this ride's cancellation. The SyCash debit is written under the RIDE
     * reference; each passenger's refund is written under its BOOKING reference. Both belong to the set,
     * so this matches on either: the booking references for this ride's bookings, or the ride reference.
     */
    private function rows(Ride $ride, string $type): Collection
    {
        $bookingRefs = Booking::where('ride_id', $ride->id)->get()->map(fn (Booking $b) => "booking:{$b->id}")->all();

        return WalletTransaction::where('type', $type)
            ->where(function ($q) use ($ride, $bookingRefs) {
                $q->where('reference', "ride:{$ride->id}")->orWhereIn('reference', $bookingRefs);
            })
            ->get();
    }

    public function test_a_staff_cancellation_debits_sycash_once_for_the_whole_set(): void
    {
        $ride = RideBuilder::for($this->driver)->paymentMethod('e-pay')->create();
        $bookings = $this->eloquentSet([$this->passengerBooking($ride, 1), $this->passengerBooking($ride, 2)]);
        $total = $bookings->sum(fn (Booking $b) => (float) $b->amount_paid);

        $this->service->refundPassengersForStaffCancellation($ride, $bookings);

        $debits = $this->rows($ride, 'staff_cancellation_refunds');
        $this->assertCount(1, $debits, 'one SyCash debit row for the whole cancelled set');
        $this->assertEqualsWithDelta(-$total, (float) $debits->first()->amount, 0.01);
    }

    public function test_each_passenger_gets_exactly_one_refund_row_for_their_own_amount_paid(): void
    {
        $ride = RideBuilder::for($this->driver)->paymentMethod('e-pay')->create();
        $first = $this->passengerBooking($ride, 1);
        $second = $this->passengerBooking($ride, 2);

        $this->service->refundPassengersForStaffCancellation($ride, $this->eloquentSet([$first, $second]));

        $refunds = $this->rows($ride, 'staff_cancellation_refund');
        $this->assertCount(2, $refunds, 'one refund row per passenger');

        $byBooking = $refunds->keyBy(fn ($r) => $r->reference);
        $this->assertEqualsWithDelta((float) $first->amount_paid, (float) $byBooking["booking:{$first->id}"]->amount, 0.01);
        $this->assertEqualsWithDelta((float) $second->amount_paid, (float) $byBooking["booking:{$second->id}"]->amount, 0.01);
    }

    public function test_the_refunds_out_of_sycash_equal_the_refunds_into_passengers(): void
    {
        $ride = RideBuilder::for($this->driver)->paymentMethod('e-pay')->create();
        $bookings = $this->eloquentSet([$this->passengerBooking($ride, 1), $this->passengerBooking($ride, 2)]);

        $result = $this->service->refundPassengersForStaffCancellation($ride, $bookings);

        $out = abs((float) $this->rows($ride, 'staff_cancellation_refunds')->sum('amount'));
        $in = (float) $this->rows($ride, 'staff_cancellation_refund')->sum('amount');
        $this->assertEqualsWithDelta($out, $in, 0.01, 'escrow out must equal passenger refunds in');
        $this->assertEqualsWithDelta($result['refunded'], $in, 0.01, 'the returned total matches the ledger');
        $this->assertSame(2, $result['bookings']);
    }

    public function test_a_booking_without_an_amount_paid_snapshot_is_not_refunded_and_is_flagged_for_review(): void
    {
        $ride = RideBuilder::for($this->driver)->paymentMethod('e-pay')->create();
        $refundable = $this->passengerBooking($ride, 1);
        $unsnapshotted = $this->passengerBooking($ride, 1);
        $unsnapshotted->update(['amount_paid' => 0]);

        $result = $this->service->refundPassengersForStaffCancellation($ride, $this->eloquentSet([$refundable, $unsnapshotted->fresh()]));

        $refunds = $this->rows($ride, 'staff_cancellation_refund');
        $this->assertCount(1, $refunds, 'only the snapshotted booking is refunded');
        $this->assertSame("booking:{$refundable->id}", $refunds->first()->reference);
        $this->assertSame(1, $result['needs_review'], 'the unsnapshotted confirmed booking is counted for review');
    }
}
