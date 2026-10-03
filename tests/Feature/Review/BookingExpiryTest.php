<?php

namespace Tests\Feature\Review;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Decision 2 (owner, 2026-10-02, Option B): "expire unconfirmed bookings, do NOT auto-confirm".
 *
 * The rejected alternative is the point: auto-confirming a request the driver never accepted would
 * seat a passenger in a car whose driver never agreed to carry them - and, for e-pay, CHARGE them.
 *
 * THREE PROPERTIES ARE PROVEN HERE, and the middle one is the one most likely to be got wrong:
 *   1. a request lapses once the ride has departed, and not before;
 *   2. NO MONEY MOVES. A PENDING booking is charged only at acceptBooking, so an expired one has
 *      amount_paid = 0, no SyCash movement and no wallet transaction. If someone later "helpfully"
 *      adds a refund to this path, these assertions fail - which is the point.
 *   3. a CONFIRMED booking is never touched by the cleanup job.
 */
class BookingExpiryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'status' => 1,
        ]);

        Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '093'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 100_000,
        ]);
        $this->passenger->update(['wallet_id' => Wallet::where('user_id', $this->passenger->id)->value('id')]);

        $this->seedSystemWallets(10_000_000);
    }

    private function makeBooking(string $status, bool $departed): Booking
    {
        // ORDER MATTERS: withAttributes() REPLACES the attribute set, so departureTime() must be
        // called AFTER it or the departure time is silently overwritten by the builder default.
        // (An earlier draft had it first, which made every ride "departed" and turned the
        // before-departure assertion into a no-op - the needle proved it.)
        $ride = RideBuilder::forUserId($this->passenger->id)
            ->withAttributes([
                'pickup_address' => 'Damascus',
                'destination_address' => 'Aleppo',
                'available_seats' => 4,
                'price_per_seat' => 10_000,
                'payment_method' => 'e-pay',
                'booking_type' => 'request',
                'status' => 'active',
                'communication_number' => '0911000000',
            ])
            ->departureTime($departed ? now()->subHour() : now()->addHours(3))
            ->create();

        return Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => $status,
            'communication_number' => '0912345678',
            'unit_price' => 10_000,
            'amount_paid' => 0,
            'payment_method' => 'e-pay',
        ]);
    }

    /** @test */
    public function an_unanswered_request_expires_once_the_ride_has_departed(): void
    {
        $booking = $this->makeBooking(BookingStatus::PENDING->value, departed: true);

        $this->artisan('bookings:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::EXPIRED->value,
        ]);
    }

    /** @test */
    public function a_request_is_not_expired_before_the_ride_departs(): void
    {
        $booking = $this->makeBooking(BookingStatus::PENDING->value, departed: false);

        $this->artisan('bookings:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::PENDING->value,
        ]);
    }

    /** @test */
    public function expiring_a_request_moves_no_money(): void
    {
        $booking = $this->makeBooking(BookingStatus::PENDING->value, departed: true);

        $walletBefore = (float) Wallet::where('user_id', $this->passenger->id)->value('balance');
        $sycashBefore = (float) Wallet::whereNull('user_id')->where('phone_number', config('admin.sycash.phone'))->value('balance');
        $txCountBefore = WalletTransaction::count();

        $this->artisan('bookings:expire-stale')->assertExitCode(0);

        $this->assertSame(
            $walletBefore,
            (float) Wallet::where('user_id', $this->passenger->id)->value('balance'),
            'an expired request was never charged, so the wallet must not move'
        );
        $this->assertSame(
            $sycashBefore,
            (float) Wallet::whereNull('user_id')->where('phone_number', config('admin.sycash.phone'))->value('balance'),
            'SyCash holds no escrow for an unaccepted request'
        );
        $this->assertSame($txCountBefore, WalletTransaction::count(),
            'expiry must not write any ledger row');
        $this->assertSame(0.0, (float) $booking->fresh()->amount_paid,
            'a pending booking carries no paid amount');
    }

    /** @test */
    public function a_confirmed_booking_is_never_expired_by_the_cleanup(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED->value, departed: true);

        $this->artisan('bookings:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::CONFIRMED->value,
        ]);
    }

    /** @test */
    public function the_command_is_idempotent(): void
    {
        $booking = $this->makeBooking(BookingStatus::PENDING->value, departed: true);

        $this->artisan('bookings:expire-stale')->assertExitCode(0);
        $this->artisan('bookings:expire-stale')->assertExitCode(0);

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::EXPIRED->value, $fresh->status);
        $this->assertSame(1, Booking::where('status', BookingStatus::EXPIRED->value)->count(),
            're-running must not expire anything twice');
    }

    /** @test */
    public function expired_is_a_terminal_status_that_cannot_be_cancelled_or_re_accepted(): void
    {
        // Decision 2 introduces a real state, so its semantics must be pinned: a lapsed request is
        // finished. Re-cancelling it would notify the passenger twice and try to return seats the
        // booking never held.
        $this->assertFalse(BookingStatus::EXPIRED->isActive());
        $this->assertFalse(BookingStatus::EXPIRED->canBeCancelled());
        $this->assertSame('Expired — driver did not respond', BookingStatus::EXPIRED->label());
    }
}
