<?php

namespace App\Modules\Money\Contracts;

/**
 * Stage 2 C2: a driver's settled earnings, read by the admin dashboard without Money's model.
 *
 * `AdminDriverDashboardService::dashboardMoney()` summed `wallet_transactions` itself. The rule it encodes -
 * that RV-39's two spellings (`ride_earnings` and `ride_earning`) are BOTH summed, and that a pending row is
 * not money that moved - is a statement about Money's data, so it belongs in Money, next to the column and the
 * enum. Console asks for a number and gets one.
 *
 * Returns the settled total only. The dashboard's other half (an estimated gross projected off current ride
 * prices) is a Ride/Booking question and stays where it is.
 */
interface DriverEarningsReader
{
    /** Sum of completed `ride_earnings` / `ride_earning` rows for the driver. */
    public function completedEarningsForDriver(int $driverId): float;
}
