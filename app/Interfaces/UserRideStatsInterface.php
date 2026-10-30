<?php

namespace App\Interfaces;

use Illuminate\Support\Collection;

/**
 * Port over the ride-history rollups (batch 233).
 *
 * IDENTITY and CONSOLE render "rides as driver / bookings as passenger" counters; RIDE owns the
 * queries (AF-7 consolidated the once-duplicated rollup there). Before this port `ProfileController`
 * and `StaffUserProfileService` type-hinted `App\Services\Ride\UserRideStatsService` directly.
 */
interface UserRideStatsInterface
{
    public function ridesAsDriver(int $userId): Collection;

    public function bookingsAsPassenger(int $userId): Collection;
}
