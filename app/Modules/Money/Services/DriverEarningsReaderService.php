<?php

namespace App\Modules\Money\Services;

use App\Enums\LedgerType;
use App\Modules\Money\Contracts\DriverEarningsReader;
use App\Modules\Money\Models\WalletTransaction;

/**
 * Stage 2 C2: the driver-earnings sum, moved verbatim from the Console dashboard.
 *
 * Same query, same two type spellings, same `status = completed` filter, same `sum('amount')` - so the number
 * the dashboard prints is unchanged. The `WalletTransaction` import stays a kernel path until the model moves
 * into this module later in the phase; when it does, only this line changes.
 */
final class DriverEarningsReaderService implements DriverEarningsReader
{
    public function completedEarningsForDriver(int $driverId): float
    {
        return (float) WalletTransaction::where('user_id', $driverId)
            ->whereIn('type', [LedgerType::RIDE_EARNINGS->value, LedgerType::RIDE_EARNING->value])
            ->where('status', 'completed')
            ->sum('amount');
    }
}
