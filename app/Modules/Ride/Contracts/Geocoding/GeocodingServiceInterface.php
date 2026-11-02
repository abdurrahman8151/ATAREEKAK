<?php

namespace App\Modules\Ride\Contracts\Geocoding;

/**
 * Port over forward and reverse geocoding (batch 227). Implemented by
 * `App\Modules\Ride\Services\GeocodingService`, the singleton `RideRepository` used to type-hint
 * directly.
 */
interface GeocodingServiceInterface
{
    /** @return array<string, mixed> */
    public function geocodeAddress(string $address): array;

    public function reverseGeocode(float $lat, float $lng): string;
}
