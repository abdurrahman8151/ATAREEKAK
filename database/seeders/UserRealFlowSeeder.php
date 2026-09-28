<?php

namespace Database\Seeders;

use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\PhoneNumber;
use App\DTOs\Ride\BookRideDTO;
use App\Models\Booking;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserScore;
use App\Models\Wallet;
use App\Repositories\RideRepository;
use App\Services\Admin\AdminWalletService;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * UserRealFlowSeeder
 *
 * Simulates the full production flow for user #36.
 *
 * KEY RULE — departure_time must be:
 *   PAST   → rides that will be finished (finishRide requires departure passed)
 *   FUTURE → rides that will be cancelled (validateCanCancelRide rejects past/near rides)
 *
 * AS DRIVER (cash — no driver wallet needed):
 *   3 × finished              → as_driver.completed  = 3
 *   2 × cancelled             → as_driver.cancelled  = 2
 *   1 × awaiting_confirmation → as_driver.no_show    = 1
 *
 * AS PASSENGER (e-pay — real wallet flow):
 *   3 × completed booking     → as_passenger.completed = 3
 *   2 × cancelled booking     → as_passenger.cancelled = 2
 *   1 × no_show  booking      → as_passenger.no_show   = 1
 *
 * Run:
 *   php artisan db:seed --class=UserRealFlowSeeder
 */
class UserRealFlowSeeder extends Seeder
{
    use ResolvesSeedCredentials;

    private const TARGET_USER_ID  = 36;
    private const PASSENGER_PHONE = '0991110036';
    private const DUMMY_PHONE     = '0991110099';
    private const PRICE_PER_SEAT  = 2500;          // SYP
    private const WALLET_TOP_UP   = 100000;        // enough for all e-pay bookings

    // Valid Syrian coordinates
    private const PICKUP = ['lat' => 33.5102, 'lng' => 36.2765];
    private const DEST   = ['lat' => 32.6244, 'lng' => 36.1021];

    private RideRepository     $rideRepo;
    private RideService        $rideService;
    private BookingService     $bookingService;
    private AdminWalletService $walletService;

    public function run(): void
    {
        $this->rideRepo       = app(RideRepository::class);
        $this->rideService    = app(RideService::class);
        $this->bookingService = app(BookingService::class);
        $this->walletService  = app(AdminWalletService::class);

        $target = $this->prepareUser(self::TARGET_USER_ID, self::PASSENGER_PHONE);
        $this->info("User #{$target->id} ({$target->first_name}) ready.");

        $dummy = $this->prepareDummyDriver();
        $this->info("Dummy driver #{$dummy->id} ready.");

        $this->seedDriverRides($target);
        $this->seedPassengerBookings($target, $dummy);

        $this->info('');
        $this->info('Done. Call GET /profile/' . self::TARGET_USER_ID . ' and verify ride_history.');
    }

    // =========================================================================
    // DRIVER SIDE
    // =========================================================================

    private function seedDriverRides(User $driver): void
    {
        // ── 3 × finished ─────────────────────────────────────────────────────
        // Departure in the PAST so finishRide() is allowed
        for ($i = 0; $i < 3; $i++) {
            $ride = $this->createCashRide($driver, departurePast: true);
            $this->rideService->finishRide($ride->id, $driver);
            $this->info("  [driver] finished ride #{$ride->id}");
        }

        // ── 2 × cancelled ────────────────────────────────────────────────────
        // Departure in the FUTURE (≥ 2 h) so validateCanCancelRide() passes
        for ($i = 0; $i < 2; $i++) {
            $ride = $this->createCashRide($driver, departurePast: false);
            $this->rideService->cancelRide($ride->id, $driver);
            $this->info("  [driver] cancelled ride #{$ride->id}");
        }

        // ── 1 × awaiting_confirmation ─────────────────────────────────────────
        // Departure past → driver can finish, but ghost passenger never confirms
        $ride  = $this->createCashRide($driver, departurePast: true);
        $ghost = $this->ghostPassenger();
        $this->forceConfirmedBooking($ghost->id, $ride->id);

        $this->rideService->finishRide($ride->id, $driver);
        // Driver confirms their side
        $this->rideService->driverConfirmCompletion($ride->id, $driver);
        // Passenger deliberately does NOT confirm → stays awaiting_confirmation
        $this->info("  [driver] awaiting_confirmation ride #{$ride->id}");
    }

    // =========================================================================
    // PASSENGER SIDE
    // =========================================================================

    private function seedPassengerBookings(User $passenger, User $dummyDriver): void
    {
        // ── 3 × completed ─────────────────────────────────────────────────────
        // Departure past so finishRide is valid; full confirm loop
        for ($i = 0; $i < 3; $i++) {
            $ride    = $this->createEpayRide($dummyDriver, departurePast: true);
            $booking = $this->bookEpay($passenger, $ride);

            $this->rideService->finishRide($ride->id, $dummyDriver);
            $this->rideService->driverConfirmCompletion($ride->id, $dummyDriver);
            $this->bookingService->passengerConfirmCompletion($booking->id, $passenger);

            $this->info("  [passenger] completed booking #{$booking->id}");
        }

        // ── 2 × cancelled ─────────────────────────────────────────────────────
        // Departure FUTURE so the refund policy has time elapsed = 0% → full refund
        for ($i = 0; $i < 2; $i++) {
            $ride    = $this->createEpayRide($dummyDriver, departurePast: false);
            $booking = $this->bookEpay($passenger, $ride);

            $this->bookingService->cancelBooking($booking->id, $passenger);
            $this->info("  [passenger] cancelled booking #{$booking->id}");
        }

        // ── 1 × no_show ───────────────────────────────────────────────────────
        // Departure past so driver can report no-show after departure time
        $ride    = $this->createEpayRide($dummyDriver, departurePast: true);
        $booking = $this->bookEpay($passenger, $ride);

        $this->bookingService->reportPassengerNoShow($booking->id, $dummyDriver);
        $this->info("  [passenger] no_show booking #{$booking->id}");
    }

    // =========================================================================
    // RIDE FACTORIES
    // =========================================================================

    /**
     * @param bool $departurePast  true → past (finished/no-show), false → future (cancel)
     */
    private function createCashRide(User $driver, bool $departurePast): Ride
    {
        $departure = $departurePast
            ? Carbon::now()->subHours(rand(3, 72))   // well in the past
            : Carbon::now()->addHours(rand(3, 48));  // well in the future

        return $this->rideRepo->createRideWithGeometry([
            'driver_id'            => $driver->id,
            'pickup_location'      => self::PICKUP,
            'destination_location' => self::DEST,
            'pickup_address'       => 'دمشق - ساحة الأمويين',
            'destination_address'  => 'درعا - المحطة المركزية',
            'departure_time'       => $departure->toDateTimeString(),
            'available_seats'      => 3,
            'price_per_seat'       => self::PRICE_PER_SEAT,
            'vehicle_type'         => 'سيدان',
            'payment_method'       => 'cash',
            'booking_type'         => 'direct',
            'communication_number' => '0991234567',
            'distance'             => 120000,
            'duration'             => 5400,
            'route_geometry'       => $this->lineString(),
            'chosen_route_index'   => 0,
            'notes'                => null,
        ]);
    }

    private function createEpayRide(User $driver, bool $departurePast): Ride
    {
        $departure = $departurePast
            ? Carbon::now()->subHours(rand(3, 72))
            : Carbon::now()->addHours(rand(3, 48));

        return $this->rideRepo->createRideWithGeometry([
            'driver_id'            => $driver->id,
            'pickup_location'      => self::PICKUP,
            'destination_location' => self::DEST,
            'pickup_address'       => 'دمشق - ساحة الأمويين',
            'destination_address'  => 'درعا - المحطة المركزية',
            'departure_time'       => $departure->toDateTimeString(),
            'available_seats'      => 3,
            'price_per_seat'       => self::PRICE_PER_SEAT,
            'vehicle_type'         => 'سيدان',
            'payment_method'       => 'e-pay',
            'booking_type'         => 'direct',
            'communication_number' => '0991234568',
            'distance'             => 120000,
            'duration'             => 5400,
            'route_geometry'       => $this->lineString(),
            'chosen_route_index'   => 0,
            'notes'                => null,
        ]);
    }

    private function lineString(): array
    {
        return [
            'type'        => 'LineString',
            'coordinates' => [
                [self::PICKUP['lng'], self::PICKUP['lat']],
                [self::DEST['lng'],   self::DEST['lat']],
            ],
        ];
    }

    // =========================================================================
    // BOOKING HELPERS
    // =========================================================================

    private function bookEpay(User $passenger, Ride $ride): Booking
    {
        $dto = new BookRideDTO(
            passengerId:         $passenger->id,
            rideId:              $ride->id,
            seats:               1,
            communicationNumber: PhoneNumber::from(self::PASSENGER_PHONE),
            idempotencyKey:      (string) Str::uuid(),
        );

        return $this->bookingService->bookRide($dto, $passenger);
    }

    /**
     * Force a confirmed booking without going through the payment flow.
     * Only used for the awaiting_confirmation scenario (ghost passenger).
     */
    private function forceConfirmedBooking(int $userId, int $rideId): Booking
    {
        $booking = Booking::create([
            'user_id'              => $userId,
            'ride_id'              => $rideId,
            'seats'                => 1,
            'status'               => 'confirmed',
            'communication_number' => '0990000000',
        ]);

        Ride::where('id', $rideId)->decrement('available_seats', 1);

        return $booking;
    }

    // =========================================================================
    // USER / WALLET SETUP
    // =========================================================================

    private function prepareUser(int $userId, string $phone): User
    {
        $user = User::findOrFail($userId);

        $user->update([
            'is_verified_driver'    => true,
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
        ]);

        UserScore::firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0]
        );

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['phone_number' => $phone, 'balance' => 0]
        );

        $user->wallet_id = $wallet->id;
        $user->save();

        // Top up via the real AdminWalletService (transaction recorded in DB)
        $adminConfig = array_merge(config('admin.system_admin'), ['type' => 'system_admin']);
        $this->walletService->chargeWallet(
            $phone,
            Money::from(self::WALLET_TOP_UP),
            $adminConfig
        );

        $this->info("  Wallet #{$wallet->id} topped up with " . self::WALLET_TOP_UP . " SYP.");

        return $user->fresh();
    }

    private function prepareDummyDriver(): User
    {
        $driver = User::firstOrCreate(
            ['email' => 'seed.driver.real@syride.test'],
            [
                'first_name'            => 'Test',
                'last_name'             => 'Driver',
                'password'              => Hash::make($this->seedPassword('SEED_USER_PASSWORD')),
                'gender'                => 'M',
                'address'               => 'دمشق',
                'status'                => 1,
                'is_verified_driver'    => true,
                'is_verified_passenger' => true,
                'verification_status'   => 'approved',
            ]
        );

        Profile::firstOrCreate(
            ['user_id' => $driver->id],
            ['full_name' => 'Test Driver', 'number_of_rides' => 0, 'radio' => false, 'smoking' => false]
        );

        UserScore::firstOrCreate(
            ['user_id' => $driver->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0]
        );

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $driver->id],
            ['phone_number' => self::DUMMY_PHONE, 'balance' => 0]
        );

        $driver->wallet_id = $wallet->id;
        $driver->save();

        return $driver->fresh();
    }

    private function ghostPassenger(): User
    {
        $ghost = User::firstOrCreate(
            ['email' => 'seed.ghost.passenger@syride.test'],
            [
                'first_name'            => 'Ghost',
                'last_name'             => 'Passenger',
                'password'              => Hash::make($this->seedPassword('SEED_USER_PASSWORD')),
                'gender'                => 'M',
                'address'               => 'دمشق',
                'status'                => 1,
                'is_verified_passenger' => true,
                'verification_status'   => 'approved',
            ]
        );

        Profile::firstOrCreate(
            ['user_id' => $ghost->id],
            ['full_name' => 'Ghost Passenger', 'number_of_rides' => 0, 'radio' => false, 'smoking' => false]
        );

        UserScore::firstOrCreate(
            ['user_id' => $ghost->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0]
        );

        return $ghost;
    }

    // =========================================================================

    private function info(string $msg): void
    {
        $this->command?->info($msg);
    }
}
