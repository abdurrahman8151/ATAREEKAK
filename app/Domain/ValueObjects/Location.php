<?php

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Value Object for geographic coordinates
 * Eliminates coordinate validation duplication
 */
class Location
{
    private float $latitude;

    private float $longitude;

    private function __construct(float $latitude, float $longitude)
    {
        $this->validateLatitude($latitude);
        $this->validateLongitude($longitude);

        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    public static function fromCoordinates(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude);
    }

    public static function fromArray(array $data): self
    {
        if (! isset($data['lat']) || ! isset($data['lng'])) {
            throw new InvalidArgumentException('Location array must contain lat and lng keys');
        }

        return new self($data['lat'], $data['lng']);
    }

    public function latitude(): float
    {
        return $this->latitude;
    }

    public function longitude(): float
    {
        return $this->longitude;
    }

    /**
     * Calculate distance to another location in meters (Haversine formula)
     */
    public function distanceTo(Location $other): float
    {
        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($other->latitude);
        $lonTo = deg2rad($other->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function toArray(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ];
    }

    public function toPointString(): string
    {
        return "POINT({$this->longitude} {$this->latitude})";
    }

    public function equals(Location $other): bool
    {
        return abs($this->latitude - $other->latitude) < 0.0001
            && abs($this->longitude - $other->longitude) < 0.0001;
    }

    private function validateLatitude(float $latitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException(
                "Invalid latitude: {$latitude}. Must be between -90 and 90"
            );
        }
    }

    private function validateLongitude(float $longitude): void
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException(
                "Invalid longitude: {$longitude}. Must be between -180 and 180"
            );
        }
    }
}
