<?php

namespace Database\Seeders;

use App\Domain\ValueObjects\Location;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\PhoneNumber;
use App\DTOs\Ride\BookRideDTO;
use App\DTOs\Ride\CreateRideDTO;
use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Models\UserScore;
use App\Models\Wallet;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Atarikaktestseeder extends Seeder
{
    private array $cities = [
        'دمشق', 'درعا', 'القنيطرة', 'السويداء',
        'ريف دمشق', 'حمص', 'حماة', 'اللاذقية',
        'طرطوس', 'حلب', 'ادلب', 'الحسكة', 'الرقة', 'دير الزور',
    ];

    private array $locations = [
        ['lat' => 32.6189, 'lng' => 36.1021, 'address' => 'درعا, منطقة درعا, محافظة درعا, سوريا'],
        ['lat' => 33.5102, 'lng' => 36.2913, 'address' => 'دمشق, سوريا'],
        ['lat' => 34.7324, 'lng' => 36.7137, 'address' => 'حمص, سوريا'],
        ['lat' => 36.2021, 'lng' => 37.1343, 'address' => 'حلب, سوريا'],
        ['lat' => 34.8886, 'lng' => 35.8797, 'address' => 'اللاذقية, سوريا'],
        ['lat' => 32.9150, 'lng' => 36.0980, 'address' => 'السويداء, سوريا'],
        ['lat' => 35.1318, 'lng' => 36.7543, 'address' => 'حماة, سوريا'],
        ['lat' => 34.8941, 'lng' => 35.8864, 'address' => 'طرطوس, سوريا'],
    ];

    private array $vehicleTypes = ['sedan', 'suv', 'minivan', 'pickup'];

    private RideService    $rideService;
    private BookingService $bookingService;
    private string         $placeholderImage = '';

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function run(): void
    {
        config(['broadcasting.default' => 'log']);
        config(['mail.default'         => 'log']);

        \App\Models\User::flushEventListeners();

        $this->command->info('🚀 Atarikak Test Seeder starting...');

        // Verify system wallets exist (AdminUserSeeder + SystemWalletSeeder must run first)
        $syCash  = Wallet::where('phone_number', config('admin.sycash.phone'))->first();
        $primary = Wallet::where('phone_number', config('admin.system_admin.phone'))->first();

        if (!$syCash || !$primary) {
            $this->command->error('System wallets not found. Run AdminUserSeeder + SystemWalletSeeder first.');
            return;
        }

        $this->rideService    = app(RideService::class);
        $this->bookingService = app(BookingService::class);

        // Seeded profile/verification photo paths are meaningless without an
        // actual file behind them — every seeded photo URL 404s otherwise (BUG-7).
        $this->preparePlaceholderImage();

        // ── 1. Seed base users ───────────────────────────────────────────────
        $this->command->info('Creating users...');
        $drivers    = $this->createDrivers(10);
        $passengers = $this->createPassengers(20);
        $this->createUnverifiedUsers(5);

        // ── 2. Run every scenario through the real service layer ─────────────
        $this->command->info('Scenario A – Completed rides (e-pay, full confirm flow)...');
        $this->scenarioA_CompletedRides($drivers, $passengers);

        $this->command->info('Scenario B – Awaiting confirmation (driver confirmed, passenger has not)...');
        $this->scenarioB_AwaitingConfirmation($drivers, $passengers);

        $this->command->info('Scenario C – Scheduled rides (future, taking bookings)...');
        $this->scenarioC_ScheduledRides($drivers, $passengers);

        $this->command->info('Scenario D – Active on-road rides (departed, not finished)...');
        $this->scenarioD_ActiveRides($drivers, $passengers);

        $this->command->info('Scenario E – Driver cancels ride (full refund to passengers)...');
        $this->scenarioE_DriverCancelsRide($drivers, $passengers);

        $this->command->info('Scenario F – Passenger cancels (3 refund tiers)...');
        $this->scenarioF_PassengerCancels($drivers, $passengers);

        $this->command->info('Scenario G – REQUEST bookings (accept + reject + pending)...');
        $this->scenarioG_RequestBookings($drivers, $passengers);

        $this->command->info('Scenario H – Passenger no-show...');
        $this->scenarioH_PassengerNoShow($drivers, $passengers);

        $this->command->info('Scenario I – Driver no-show...');
        $this->scenarioI_DriverNoShow($drivers, $passengers);

        $this->command->info('Scenario J – CASH rides (completed + scheduled)...');
        $this->scenarioJ_CashRides($drivers, $passengers);

        $this->command->info('Scenario K – Partial seat cancellation...');
        $this->scenarioK_PartialCancel($drivers, $passengers);

        Carbon::setTestNow(null);

        $this->command->info('');
        $this->command->info('✅ Seeder complete!');
        $this->command->info('   Users:      ' . User::count());
        $this->command->info('   Rides:      ' . Ride::count());
        $this->command->info('   Bookings:   ' . Booking::count());
        $this->command->info('   Wallets:    ' . Wallet::count());
    }

    // =========================================================================
    // USER CREATION
    // =========================================================================

    private function createDrivers(int $count): \Illuminate\Support\Collection
    {
        $drivers   = collect();
        $adminUser = User::where('email', config('admin.system_admin.email'))->first();

        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'first_name'            => "Driver{$i}",
                'last_name'             => 'Test',
                'email'                 => "driver{$i}@test.com",
                'password'              => Hash::make('password123'),
                'gender'                => $i % 2 === 0 ? 'M' : 'F',
                'address'               => $this->cities[array_rand($this->cities)],
                'status'                => 1,
                'is_verified_driver'    => true,
                'is_verified_passenger' => true,
                'verification_status'   => 'approved',
                'email_verified_at'     => now(),
            ]);

            Profile::create([
                'user_id'         => $user->id,
                'full_name'       => "Driver{$i} Test",
                'address'         => $user->address,
                'gender'          => $user->gender,
                'type_of_car'     => $this->vehicleTypes[array_rand($this->vehicleTypes)],
                'color_of_car'    => ['white', 'black', 'silver', 'red'][array_rand(['white', 'black', 'silver', 'red'])],
                'number_of_seats' => rand(4, 7),
                'number_of_rides' => 0,
                'radio'           => (bool) rand(0, 1),
                'smoking'         => false,
                'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            ]);

            $wallet = Wallet::create([
                'user_id'      => $user->id,
                'phone_number' => '+9639' . str_pad($i, 8, '0', STR_PAD_LEFT),
                'balance'      => 2_000_000,
            ]);
            $user->wallet_id = $wallet->id;
            $user->save();

            UserScore::create([
                'user_id'             => $user->id,
                'score'               => 70,
                'total_rides'         => 0,
                'total_cancellations' => 0,
            ]);

            if ($adminUser) {
                UserRating::firstOrCreate(
                    ['rater_id' => $adminUser->id, 'rated_user_id' => $user->id],
                    ['rating'   => 3.0]
                );
            }

            foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $docType) {
                $this->createVerificationDoc($user->id, $docType);
            }

            $drivers->push($user);
        }

        $this->command->info("  ✅ {$count} drivers created with 3.0 base rating.");
        return $drivers;
    }

    private function createPassengers(int $count): \Illuminate\Support\Collection
    {
        $passengers = collect();
        $adminUser  = User::where('email', config('admin.system_admin.email'))->first();

        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'first_name'            => "Passenger{$i}",
                'last_name'             => 'Test',
                'email'                 => "passenger{$i}@test.com",
                'password'              => Hash::make('password123'),
                'gender'                => $i % 2 === 0 ? 'M' : 'F',
                'address'               => $this->cities[array_rand($this->cities)],
                'status'                => 1,
                'is_verified_driver'    => false,
                'is_verified_passenger' => true,
                'verification_status'   => 'approved',
                'email_verified_at'     => now(),
            ]);

            Profile::create([
                'user_id'         => $user->id,
                'full_name'       => "Passenger{$i} Test",
                'address'         => $user->address,
                'gender'          => $user->gender,
                'number_of_rides' => 0,
                'radio'           => false,
                'smoking'         => false,
                'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            ]);

            $wallet = Wallet::create([
                'user_id'      => $user->id,
                'phone_number' => '+9639' . str_pad(100 + $i, 8, '0', STR_PAD_LEFT),
                'balance'      => 5_000_000,
            ]);
            $user->wallet_id = $wallet->id;
            $user->save();

            UserScore::create([
                'user_id'             => $user->id,
                'score'               => 70,
                'total_rides'         => 0,
                'total_cancellations' => 0,
            ]);

            if ($adminUser) {
                UserRating::firstOrCreate(
                    ['rater_id' => $adminUser->id, 'rated_user_id' => $user->id],
                    ['rating'   => 3.0]
                );
            }

            foreach (['face_id', 'back_id'] as $docType) {
                $this->createVerificationDoc($user->id, $docType);
            }

            $passengers->push($user);
        }

        $this->command->info("  ✅ {$count} passengers created with 3.0 base rating.");
        return $passengers;
    }

    private function createUnverifiedUsers(int $count): void
    {
        $statuses  = ['pending', 'none', 'pending', 'none', 'rejected'];
        $adminUser = User::where('email', config('admin.system_admin.email'))->first();

        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'first_name'            => "Unverified{$i}",
                'last_name'             => 'User',
                'email'                 => "unverified{$i}@test.com",
                'password'              => Hash::make('password123'),
                'gender'                => 'M',
                'address'               => $this->cities[array_rand($this->cities)],
                'status'                => 1,
                'is_verified_driver'    => false,
                'is_verified_passenger' => false,
                'verification_status'   => $statuses[$i - 1],
                'email_verified_at'     => now(),
            ]);

            Profile::create([
                'user_id'         => $user->id,
                'full_name'       => "Unverified{$i} User",
                'address'         => $user->address,
                'gender'          => $user->gender,
                'number_of_rides' => 0,
                'radio'           => false,
                'smoking'         => false,
                'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            ]);

            UserScore::create([
                'user_id'             => $user->id,
                'score'               => 70,
                'total_rides'         => 0,
                'total_cancellations' => 0,
            ]);

            if ($adminUser) {
                UserRating::firstOrCreate(
                    ['rater_id' => $adminUser->id, 'rated_user_id' => $user->id],
                    ['rating'   => 3.0]
                );
            }
        }

        $this->command->info("  ✅ {$count} unverified users created with 3.0 base rating.");
    }

    // =========================================================================
    // SCENARIO A – COMPLETED RIDES (e-pay)
    // =========================================================================

    private function scenarioA_CompletedRides($drivers, $passengers): void
    {
        for ($i = 0; $i < 8; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $passenger = $passengers[$i % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subDays(10 + $i));
                $departure = Carbon::now()->addHours(2);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                Carbon::setTestNow($departure->copy()->addHours(1));

                $this->rideService->finishRide($ride->id, $driver);
                $this->rideService->driverConfirmCompletion($ride->id, $driver);

                $booking->refresh();
                $this->bookingService->passengerConfirmCompletion($booking->id, $passenger);

                $this->command->line("  ✓ Completed ride #{$ride->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario A [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO B – AWAITING CONFIRMATION
    // =========================================================================

    private function scenarioB_AwaitingConfirmation($drivers, $passengers): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                $driver    = $drivers[($i + 3) % $drivers->count()];
                $passenger = $passengers[($i + 5) % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subHours(6));
                $departure = Carbon::now()->addHours(1);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                Carbon::setTestNow($departure->copy()->addHours(1));

                $this->rideService->finishRide($ride->id, $driver);
                $this->rideService->driverConfirmCompletion($ride->id, $driver);

                // Passenger does NOT confirm — ride stays awaiting_confirmation
                $this->command->line("  ✓ Ride #{$ride->id} awaiting passenger confirmation");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario B [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO C – SCHEDULED RIDES
    // =========================================================================

    private function scenarioC_ScheduledRides($drivers, $passengers): void
    {
        for ($i = 0; $i < 15; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $method    = $i % 3 === 0 ? 'cash' : 'e-pay';
                $departure = Carbon::now()->addHours(rand(2, 168));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, $method, 'direct'),
                    $driver
                );

                if ($i % 2 === 0) {
                    $passenger = $passengers[$i % $passengers->count()];
                    $this->bookingService->bookRide(
                        $this->makeBookDTO($passenger, $ride->id, 1),
                        $passenger
                    );
                }

                $this->command->line("  ✓ Scheduled ride #{$ride->id} ({$method})");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario C [{$i}]: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // SCENARIO D – ACTIVE ON-ROAD RIDES
    // =========================================================================

    private function scenarioD_ActiveRides($drivers, $passengers): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $driver    = $drivers[($i + 5) % $drivers->count()];
                $passenger = $passengers[($i + 10) % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subHours(3));
                $departure = Carbon::now()->addMinutes(30);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                $this->command->line("  ✓ Active ride #{$ride->id} (departed, not finished)");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario D [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO E – DRIVER CANCELS RIDE
    // =========================================================================

    private function scenarioE_DriverCancelsRide($drivers, $passengers): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $passenger = $passengers[($i + 3) % $passengers->count()];
                $departure = Carbon::now()->addHours(rand(3, 48));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                $this->rideService->cancelRide($ride->id, $driver);

                $this->command->line("  ✓ Driver cancelled ride #{$ride->id} (passenger refunded)");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario E [{$i}]: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // SCENARIO F – PASSENGER CANCELS (3 REFUND TIERS)
    // =========================================================================

    private function scenarioF_PassengerCancels($drivers, $passengers): void
    {
        $tiers = [
            ['label' => 'early (0-30%)',   'elapsed_hours' => 1,  'total_hours' => 48],
            ['label' => 'mid   (30-50%)',  'elapsed_hours' => 17, 'total_hours' => 48],
            ['label' => 'late  (50-100%)', 'elapsed_hours' => 40, 'total_hours' => 48],
        ];

        foreach ($tiers as $idx => $tier) {
            try {
                $driver    = $drivers[($idx + 7) % $drivers->count()];
                $passenger = $passengers[($idx + 7) % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subHours($tier['total_hours']));
                $departure = Carbon::now()->addHours($tier['total_hours']);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                Carbon::setTestNow(Carbon::now()->addHours($tier['elapsed_hours']));

                $this->bookingService->cancelBooking($booking->id, $passenger);

                $this->command->line("  ✓ Passenger cancelled ({$tier['label']}) booking #{$booking->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario F [{$idx}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO G – REQUEST BOOKINGS
    // =========================================================================

    private function scenarioG_RequestBookings($drivers, $passengers): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $passenger = $passengers[($i + 15) % $passengers->count()];
                $departure = Carbon::now()->addHours(rand(6, 72));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'request'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                $this->bookingService->acceptBooking($booking->id, $driver);

                $this->command->line("  ✓ Request booking #{$booking->id} accepted");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario G (accept) [{$i}]: " . $e->getMessage());
            }
        }

        for ($i = 0; $i < 2; $i++) {
            try {
                $driver    = $drivers[($i + 3) % $drivers->count()];
                $passenger = $passengers[($i + 18) % $passengers->count()];
                $departure = Carbon::now()->addHours(rand(6, 72));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'cash', 'request'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                $this->bookingService->rejectBooking($booking->id, $driver);

                $this->command->line("  ✓ Request booking #{$booking->id} rejected");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario G (reject) [{$i}]: " . $e->getMessage());
            }
        }

        for ($i = 0; $i < 3; $i++) {
            try {
                $driver    = $drivers[($i + 5) % $drivers->count()];
                $passenger = $passengers[$i % $passengers->count()];
                $departure = Carbon::now()->addHours(rand(6, 72));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'request'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                $this->command->line("  ✓ Request booking #{$booking->id} pending (no driver action)");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario G (pending) [{$i}]: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // SCENARIO H – PASSENGER NO-SHOW
    // =========================================================================

    private function scenarioH_PassengerNoShow($drivers, $passengers): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $passenger = $passengers[($i + 12) % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subHours(5));
                $departure = Carbon::now()->addHours(1);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                Carbon::setTestNow($departure->copy()->addMinutes(30));

                $this->bookingService->reportPassengerNoShow($booking->id, $driver);

                $this->command->line("  ✓ Passenger no-show on booking #{$booking->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario H [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO I – DRIVER NO-SHOW
    // =========================================================================

    private function scenarioI_DriverNoShow($drivers, $passengers): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $driver    = $drivers[($i + 6) % $drivers->count()];
                $passenger = $passengers[($i + 8) % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subHours(5));
                $departure = Carbon::now()->addHours(1);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct'),
                    $driver
                );

                $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 1),
                    $passenger
                );

                Carbon::setTestNow($departure->copy()->addMinutes(30));

                $this->rideService->reportDriverNoShow($ride->id, $passenger);

                $this->command->line("  ✓ Driver no-show on ride #{$ride->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario I [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }
    }

    // =========================================================================
    // SCENARIO J – CASH RIDES
    // =========================================================================

    private function scenarioJ_CashRides($drivers, $passengers): void
    {
        for ($i = 0; $i < 6; $i++) {
            try {
                $driver    = $drivers[$i % $drivers->count()];
                $passenger = $passengers[$i % $passengers->count()];

                Carbon::setTestNow(Carbon::now()->subDays(5 + $i));
                $departure = Carbon::now()->addHours(2);

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'cash', 'direct'),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, rand(1, 2)),
                    $passenger
                );

                Carbon::setTestNow($departure->copy()->addHours(1));

                $this->rideService->finishRide($ride->id, $driver);
                $this->rideService->driverConfirmCompletion($ride->id, $driver);
                $booking->refresh();
                $this->bookingService->passengerConfirmCompletion($booking->id, $passenger);

                $this->command->line("  ✓ Completed cash ride #{$ride->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario J (completed) [{$i}]: " . $e->getMessage());
            } finally {
                Carbon::setTestNow(null);
            }
        }

        for ($i = 0; $i < 6; $i++) {
            try {
                $driver    = $drivers[($i + 4) % $drivers->count()];
                $departure = Carbon::now()->addHours(rand(2, 72));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'cash', 'direct'),
                    $driver
                );

                $this->command->line("  ✓ Scheduled cash ride #{$ride->id}");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario J (scheduled) [{$i}]: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // SCENARIO K – PARTIAL SEAT CANCELLATION
    // =========================================================================

    private function scenarioK_PartialCancel($drivers, $passengers): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                $driver    = $drivers[($i + 6) % $drivers->count()];
                $passenger = $passengers[($i + 16) % $passengers->count()];
                $departure = Carbon::now()->addHours(rand(12, 48));

                $ride = $this->rideService->createRide(
                    $this->makeRideDTO($driver, $departure, 'e-pay', 'direct', 6),
                    $driver
                );

                $booking = $this->bookingService->bookRide(
                    $this->makeBookDTO($passenger, $ride->id, 3),
                    $passenger
                );

                $this->bookingService->cancelPartialSeats($booking->id, 1, $passenger);

                $this->command->line("  ✓ Partial cancel on booking #{$booking->id} (3→2 seats)");

            } catch (\Throwable $e) {
                $this->command->warn("  ✗ Scenario K [{$i}]: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // DTO FACTORIES
    // =========================================================================

    private function makeRideDTO(
        User   $driver,
        Carbon $departure,
        string $paymentMethod,
        string $bookingType,
        int    $availableSeats = 0
    ): CreateRideDTO {
        $loc   = $this->randomRoute();
        $seats = $availableSeats > 0 ? $availableSeats : rand(3, 6);

        return new CreateRideDTO(
            driverId:            $driver->id,
            pickupLocation:      Location::fromCoordinates($loc['from']['lat'], $loc['from']['lng']),
            destinationLocation: Location::fromCoordinates($loc['to']['lat'], $loc['to']['lng']),
            pickupAddress:       $loc['from']['address'],
            destinationAddress:  $loc['to']['address'],
            departureTime:       $departure,
            availableSeats:      $seats,
            pricePerSeat:        Money::from(rand(5, 50) * 1000),
            vehicleType:         $this->vehicleTypes[array_rand($this->vehicleTypes)],
            paymentMethod:       PaymentMethod::from($paymentMethod),
            bookingType:         BookingType::from($bookingType),
            communicationNumber: PhoneNumber::from('0983337214'),
            notes:               'Seeded test ride',
            routeGeometry:       ['type' => 'LineString', 'coordinates' => []],
            chosenRouteIndex:    0,
            distance:            rand(10000, 500000),
            duration:            rand(600, 18000),
        );
    }

    private function makeBookDTO(User $passenger, int $rideId, int $seats): BookRideDTO
    {
        return new BookRideDTO(
            passengerId:         $passenger->id,
            rideId:              $rideId,
            seats:               $seats,
            communicationNumber: PhoneNumber::from('0983337214'),
            idempotencyKey:      (string) Str::uuid(),
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function randomRoute(): array
    {
        $from = $this->locations[array_rand($this->locations)];
        do {
            $to = $this->locations[array_rand($this->locations)];
        } while ($to === $from);

        return ['from' => $from, 'to' => $to];
    }

    /**
     * A tiny real JPEG (same placeholder used by Syrideseeder) so every seeded
     * `profile_photo` / verification document path resolves to an actual file
     * instead of a 404 (BUG-7).
     */
    private function preparePlaceholderImage(): void
    {
        $this->placeholderImage = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDB'
            . 'kSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAAR'
            . 'CAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAA'
            . 'AAAAAAAAAAAAAP/EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUEQEAAAAAAAAAAAAA'
            . 'AAAAAAAA/9oADAMBAAIRAxEAPwCwABmX/9k='
        );

        // Shared by every seeded user — write it once.
        Storage::disk('public')->put(
            'profiles/profile_photo/default-profile-photo.jpg',
            $this->placeholderImage
        );
    }

    /**
     * Writes the placeholder image behind a seeded verification document path
     * and inserts the matching Photo row — pairing BUG-7's fix with every
     * `Photo::create()` call site so no seeded document path is left dangling.
     */
    private function createVerificationDoc(int $userId, string $docType): void
    {
        $path = "verifications/{$docType}/seeded_{$userId}.jpg";

        Storage::disk('public')->put($path, $this->placeholderImage);

        \App\Models\Photo::create([
            'user_id' => $userId,
            'type'    => $docType,
            'path'    => $path,
        ]);
    }
}
