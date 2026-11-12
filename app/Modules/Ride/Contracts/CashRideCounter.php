<?php

namespace App\Modules\Ride\Contracts;

/**
 * How many cash rides a driver has created - the number Money's creation-fee tiers are based on.
 *
 * WHY THIS IS A PORT (Stage 2 C2). Money's cash-creation-fee service decides between "first two rides",
 * "third ride and later" and "deferred while in debt" by COUNTING the driver's cash rides. That count is a
 * statement over `rides`, which Ride owns, and it was the last thing in Money that named the model as anything
 * other than a parameter type. Money asks for the number; Ride runs the query.
 *
 * ONE query, the same one: `driver_id = ? AND payment_method = 'cash'`. The caller's tier logic, its thresholds
 * and its messages are untouched.
 */
interface CashRideCounter
{
    /**
     * Cash rides this driver has created, including one already saved for the current request.
     */
    public function countForDriver(int $driverId): int;
}
