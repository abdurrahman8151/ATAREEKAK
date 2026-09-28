<?php

namespace Database\Factories;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RideFactory extends Factory
{
    protected $model = Ride::class;

    public function definition(): array
    {
        return [
            'driver_id' => User::factory(),
            'pickup_address' => 'دمشق - ساحة المرجة',
            'destination_address' => 'حلب - العزيزية',
            'pickup_location' => \DB::raw("ST_GeomFromText('POINT(33.5138 36.2765)')"),
            'destination_location' => \DB::raw("ST_GeomFromText('POINT(36.2021 37.1343)')"),
            'departure_time' => now()->addHours(3),
            'available_seats' => 4,
            'price_per_seat' => 50_000,
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'status' => 'active',
            'distance' => 320.5,
            'duration' => 240.0,
            'communication_number' => '0912345678',
        ];
    }
}
