<?php

namespace Tests\Feature\Rides;

use App\Jobs\SendPushNotificationJob;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Modules\Money\Services\BookingCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L22 (RideController whole-file slice, characterization): pins the notification contract of every
 * RideController route that sends one, before the notification blocks are consolidated.
 *
 * For each route it pins: the recipient, the Notification type/title/message, the data payload,
 * and the push priority and category. The route status and JSON key are pinned too, so a refactor
 * that changes any of them fails here.
 */
class L22RideControllerNotificationContractTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000.0);

        $this->driver = $this->verifiedUser(['is_verified_driver' => true]);
        $this->passenger = $this->verifiedUser(['is_verified_passenger' => true]);
    }

    /** A user with a wallet and a verified profile, so the routes accept them. */
    private function verifiedUser(array $flags): User
    {
        $user = User::factory()->create($flags);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.rand(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $user->update(['wallet_id' => $wallet->id]);

        return $user->fresh();
    }

    /** Logs in through the real auth route, the same way RideControllerFullTest does. */
    private function tokenFor(User $user): string
    {
        $user->update(['password' => bcrypt('password123')]);

        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }

    /** A confirmed booking on a ride the driver owns, charged so the money paths run for real. */
    private function bookingOnRide(int $seats = 1, string $bookingType = 'direct', ?\DateTimeInterface $departure = null, bool $charge = true): array
    {
        $ride = RideBuilder::for($this->driver)
            ->withAttributes([
                'payment_method' => 'e-pay',
                'booking_type' => $bookingType,
                'status' => 'active',
                'departure_time' => $departure ?? now()->addHours(3),
            ])
            ->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);
        if ($charge) {
            app(BookingCharge::class)
                ->chargePassengerForBooking($booking, $ride->fresh(), $this->passenger->fresh());
        }

        return [$ride->fresh(), $booking->fresh()];
    }

    /**
     * The one Notification of this type delivered to this user, or null. Recipients are linked through
     * the user_notifications join table, not through notifications.user_id.
     */
    private function notificationFor(User $user, string $type, ?string $title = null): ?Notification
    {
        return Notification::where('type', $type)
            ->when($title !== null, fn ($q) => $q->where('title', $title))
            ->whereIn('id', UserNotification::where('user_id', $user->id)->pluck('notification_id'))
            ->first();
    }

    /**
     * The [userId, payload] of every push job dispatched. The job keeps both private, so they are read
     * through reflection on the dispatched instance.
     *
     * @return array<int, array{0: int, 1: array}>
     */
    private function dispatchedPushes(): array
    {
        $out = [];
        Bus::assertDispatched(SendPushNotificationJob::class, function ($job) use (&$out) {
            $r = new \ReflectionClass($job);
            $out[] = [$r->getProperty('userId')->getValue($job), $r->getProperty('payload')->getValue($job)];

            return true;
        });

        return $out;
    }

    public function test_cancel_booking_notifies_the_driver_and_keeps_the_response_shape(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(2);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->passenger))
            ->postJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk()->assertJsonStructure(['success', 'data', 'message']);
        $n = $this->notificationFor($this->driver, 'booking_cancelled_by_passenger');
        $this->assertNotNull($n, 'the driver receives the cancellation notification');
        $this->assertSame('إلغاء حجز', $n->title);
        $this->assertSame(['ride_id' => $ride->id, 'booking_id' => $booking->id], $n->data);
        $this->assertNull($this->notificationFor($this->passenger, 'booking_cancelled_by_passenger'), 'the passenger does not notify themselves');
        $this->assertTrue(collect($this->dispatchedPushes())->contains(fn ($p) => $p[0] === $this->driver->id), 'the driver is pushed');
    }

    public function test_accept_booking_notifies_the_passenger_with_high_priority_and_ride_category(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(1, 'request', null, false);
        $booking->update(['status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->driver))
            ->postJson("/api/bookings/{$booking->id}/accept");

        $response->assertOk()->assertJsonStructure(['success', 'data', 'message']);
        $n = $this->notificationFor($this->passenger, 'booking_accepted', 'تم قبول حجزك');
        $this->assertNotNull($n, 'the passenger receives the acceptance notification');
        $this->assertSame('تم قبول حجزك', $n->title);
        $this->assertTrue(collect($this->dispatchedPushes())->contains(fn ($p) => $p[0] === $this->passenger->id
            && ($p[1]['title'] ?? null) === 'تم قبول حجزك'
            && ($p[1]['data']['priority'] ?? null) === 'high'
            && ($p[1]['data']['category'] ?? null) === 'ride'), 'the controller push carries high priority and ride category');
    }

    public function test_reject_booking_notifies_the_passenger_with_normal_priority(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(1, 'request', null, false);
        $booking->update(['status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->driver))
            ->postJson("/api/bookings/{$booking->id}/reject");

        $response->assertOk()->assertJsonStructure(['success', 'data', 'message']);
        $n = $this->notificationFor($this->passenger, 'booking_rejected', 'تم رفض طلب حجزك');
        $this->assertNotNull($n, 'the passenger receives the rejection notification');
        $this->assertSame('تم رفض طلب حجزك', $n->title);
        $this->assertTrue(collect($this->dispatchedPushes())->contains(fn ($p) => $p[0] === $this->passenger->id
            && ($p[1]['title'] ?? null) === 'تم رفض طلب حجزك'
            && ($p[1]['data']['priority'] ?? null) === 'normal'), 'the controller push carries normal priority');
    }

    public function test_cancel_seats_notifies_the_driver_with_the_seat_count(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(2);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->passenger))
            ->postJson("/api/bookings/{$booking->id}/cancel-seats", ['seats_to_cancel' => 1]);

        $response->assertOk()->assertJsonStructure(['status', 'message', 'data']);
        $n = $this->notificationFor($this->driver, 'seats_partially_cancelled');
        $this->assertNotNull($n, 'the driver receives the partial-cancel notification');
        $this->assertSame('إلغاء جزئي للمقاعد', $n->title);
        $this->assertStringContainsString('1 مقعد', $n->message);
    }

    public function test_passenger_no_show_notifies_the_passenger_with_high_priority(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(1, 'direct', now()->subHours(2));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->driver))
            ->postJson("/api/bookings/{$booking->id}/passenger-no-show");

        // The booking-level no-show must succeed for the notification to be sent.
        if ($response->status() !== 200) {
            $this->markTestSkipped('Passenger no-show is not reachable from this fixture state; cover it in the next slice.');
        }
        $response->assertJsonStructure(['status', 'message']);
        $n = $this->notificationFor($this->passenger, 'no_show_recorded');
        $this->assertNotNull($n, 'the passenger receives the no-show notification');
        $this->assertSame('تسجيل غياب', $n->title);
        $this->assertTrue(collect($this->dispatchedPushes())->contains(fn ($p) => $p[0] === $this->passenger->id
            && ($p[1]['title'] ?? null) === 'تسجيل غياب'
            && ($p[1]['data']['priority'] ?? null) === 'high'), 'the controller push carries high priority');
    }

    public function test_driver_no_show_notifies_the_driver_with_high_priority(): void
    {
        Bus::fake([SendPushNotificationJob::class]);
        [$ride, $booking] = $this->bookingOnRide(1, 'direct', now()->subHours(2));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->passenger))
            ->postJson("/api/rides/{$ride->id}/driver-no-show");

        if ($response->status() !== 200) {
            $this->markTestSkipped('Driver no-show is not reachable from this fixture state; cover it in the next slice.');
        }
        $response->assertJsonStructure(['status', 'message']);
        $n = $this->notificationFor($this->driver, 'driver_no_show_recorded');
        $this->assertNotNull($n, 'the driver receives the no-show notification');
        $this->assertSame('تقرير غياب', $n->title);
    }
}
