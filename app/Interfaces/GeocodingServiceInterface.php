<?php

namespace App\Interfaces;

interface GeocodingServiceInterface
{
    public function geocodeAddress(string $address): array;

    public function getRouteDetails(array $origin, array $destination): array;

    /**
     * Get multiple route alternatives between two points
     *
     * @param  array  $origin  ['lat' => float, 'lng' => float]
     * @param  array  $destination  ['lat' => float, 'lng' => float]
     */
    public function getRouteAlternatives(array $origin, array $destination, int $maxAlternatives = 3): array;
}
