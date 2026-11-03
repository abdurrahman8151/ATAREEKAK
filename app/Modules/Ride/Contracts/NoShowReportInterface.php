<?php

namespace App\Modules\Ride\Contracts;

/**
 * Port over the no-show report sweep (stage 1.5, item 3). Implemented by
 * `App\Modules\Ride\Services\Noshowservice` and consumed by the `ResolveExpiredNoshowReports` artisan
 * command, which used to call the service directly.
 *
 * Only the scheduled sweep is on the port: `reportDriverNoShow` / `passengerConfirmCompletion` are
 * domain-API calls whose parking is recorded in audit sec 244, and exposing them here would be the
 * auth/domain decision that section parked rather than a mechanical port move.
 */
interface NoShowReportInterface
{
    /** Resolve the no-show reports whose window has passed and report how many were resolved. */
    public function resolveExpiredReports(): int;
}
