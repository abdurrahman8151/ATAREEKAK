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
            // AF-13: these were `DB::raw("ST_GeomFromText('POINT(...)')")`, which is a
            // Query\Expression. Ride::setPickupLocationAttribute() type-hints `array`, so every
            // Ride::factory()->create() died with a TypeError. Passing the named coordinates instead
            // routes the write through the mutator, which calls GeoPoint::fromLatLng() - the same
            // single source of truth every real writer uses - and additionally materialises
            // pickup_lat / pickup_lng, which a raw write skipped and had to be backfilled by migration.
            //
            // Geometry is unchanged: the raw literals were already POINT(latitude longitude), which is
            // the order GeoPoint::wkt() emits, so no axis-order decision is introduced here.
            'pickup_location' => ['lat' => 33.5138, 'lng' => 36.2765],
            'destination_location' => ['lat' => 36.2021, 'lng' => 37.1343],
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
