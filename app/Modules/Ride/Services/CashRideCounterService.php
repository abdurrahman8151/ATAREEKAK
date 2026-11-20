<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Contracts\CashRideCounter;
use App\Modules\Ride\Models\Ride;

/**
 * The cash-ride count behind {@see CashRideCounter} - the statement Money used to run itself, unchanged.
 */
final class CashRideCounterService implements CashRideCounter
{
    public function countForDriver(int $driverId): int
    {
        return Ride::where('driver_id', $driverId)
            ->where('payment_method', 'cash')
            ->count();
    }
}
