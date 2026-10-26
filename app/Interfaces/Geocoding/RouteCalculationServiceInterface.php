<?php

namespace App\Interfaces\Geocoding;

/**
 * Port over route calculation (batch 227). Implemented by
 * `App\Services\Geocoding\RouteCalculationService`; only the method the repository calls is
 * declared, so the port stays a statement of what its consumer needs.
 */
interface RouteCalculationServiceInterface
{
    /** @return array<string, mixed> */
    public function getRouteDetails(array $origin, array $destination): array;
}
