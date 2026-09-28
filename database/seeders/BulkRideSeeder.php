<?php

// database/seeders/BulkRideSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BulkRideSeeder extends Seeder
{
    // Tune these to hit the row count you want
    private const TARGET_RIDES = 500_000;

    private const TARGET_BOOKINGS = 1_000_000;

    private const BATCH_SIZE = 2_000;

    private array $cities = [
        ['lat' => 33.5138, 'lng' => 36.3128, 'name' => 'دمشق'],
        ['lat' => 36.2021, 'lng' => 37.1343, 'name' => 'حلب'],
        ['lat' => 34.7324, 'lng' => 36.7137, 'name' => 'حمص'],
        ['lat' => 35.1318, 'lng' => 36.7512, 'name' => 'حماة'],
        ['lat' => 35.5317, 'lng' => 35.7917, 'name' => 'اللاذقية'],
        ['lat' => 34.8934, 'lng' => 35.8872, 'name' => 'طرطوس'],
        ['lat' => 32.6223, 'lng' => 36.1001, 'name' => 'درعا'],
        ['lat' => 35.9306, 'lng' => 36.6340, 'name' => 'إدلب'],
    ];

    public function run(): void
    {
        // Pull existing driver and passenger IDs — seeded by SyrideSeeder
        $driverIds = DB::table('users')->where('is_verified_driver', true)->pluck('id')->toArray();
        $passengerIds = DB::table('users')->where('is_verified_passenger', true)->pluck('id')->toArray();

        if (empty($driverIds) || empty($passengerIds)) {
            $this->command->error('Run SyrideSeeder first — no drivers or passengers found.');

            return;
        }

        $this->command->info('Bulk inserting '.number_format(self::TARGET_RIDES).' rides…');
        $this->seedRides($driverIds);

        $this->command->info('Bulk inserting '.number_format(self::TARGET_BOOKINGS).' bookings…');
        $this->seedBookings($passengerIds);
    }

    private function seedRides(array $driverIds): void
    {
        $bar = $this->command->getOutput()->createProgressBar(self::TARGET_RIDES);
        $statuses = ['active', 'active', 'active', 'finished', 'finished', 'cancelled'];
        $payments = ['e-pay', 'e-pay', 'cash'];
        $batch = [];
        $inserted = 0;
        $now = now()->toDateTimeString();

        while ($inserted < self::TARGET_RIDES) {
            $origin = $this->cities[array_rand($this->cities)];
            do {
                $dest = $this->cities[array_rand($this->cities)];
            } while ($dest['name'] === $origin['name']);

            $status = $statuses[array_rand($statuses)];
            $departure = now()
                ->subDays(rand(0, 180))
                ->addHours(rand(0, 23))
                ->toDateTimeString();

            $batch[] = [
                'driver_id' => $driverIds[array_rand($driverIds)],
                'pickup_location' => DB::raw("ST_GeomFromText('POINT({$origin['lng']} {$origin['lat']})', 4326)"),
                'destination_location' => DB::raw("ST_GeomFromText('POINT({$dest['lng']} {$dest['lat']})', 4326)"),
                'pickup_lat' => $origin['lat'],
                'pickup_lng' => $origin['lng'],
                'destination_lat' => $dest['lat'],
                'destination_lng' => $dest['lng'],
                'pickup_address' => $origin['name'],
                'destination_address' => $dest['name'],
                'departure_time' => $departure,
                'available_seats' => rand(1, 4),
                'price_per_seat' => rand(3000, 25000),
                'vehicle_type' => 'تويوتا كورولا',
                'payment_method' => $payments[array_rand($payments)],
                'booking_type' => rand(0, 1) ? 'direct' : 'request',
                'communication_number' => '09'.rand(10000000, 99999999),
                'status' => $status,
                'distance' => rand(30000, 450000),
                'duration' => rand(1800, 18000),
                'route_geometry' => '{"type":"LineString","coordinates":[]}',
                'finished_at' => $status === 'finished' ? $departure : null,
                'cash_creation_fee' => null,
                'cash_fee_deferred' => false,
                'notes' => null,
                'driver_confirmed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                DB::table('rides')->insert($batch);
                $inserted += count($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }

        // flush remainder
        if ($batch) {
            DB::table('rides')->insert($batch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ '.number_format(self::TARGET_RIDES).' rides inserted');
    }

    private function seedBookings(array $passengerIds): void
    {
        // Only book against finished/active rides to avoid FK issues
        $rideIds = DB::table('rides')
            ->whereIn('status', ['active', 'finished'])
            ->pluck('id')
            ->toArray();

        if (empty($rideIds)) {
            $this->command->warn('No bookable rides found — skipping bookings.');

            return;
        }

        $bar = $this->command->getOutput()->createProgressBar(self::TARGET_BOOKINGS);
        $statuses = ['confirmed', 'confirmed', 'completed', 'pending', 'cancelled'];
        $batch = [];
        $inserted = 0;
        $now = now()->toDateTimeString();

        while ($inserted < self::TARGET_BOOKINGS) {
            $batch[] = [
                'user_id' => $passengerIds[array_rand($passengerIds)],
                'ride_id' => $rideIds[array_rand($rideIds)],
                'seats' => rand(1, 2),
                'status' => $statuses[array_rand($statuses)],
                'communication_number' => '09'.rand(10000000, 99999999),
                'passenger_confirmed_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                DB::table('bookings')->insert($batch);
                $inserted += count($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('bookings')->insert($batch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ '.number_format(self::TARGET_BOOKINGS).' bookings inserted');
    }
}
