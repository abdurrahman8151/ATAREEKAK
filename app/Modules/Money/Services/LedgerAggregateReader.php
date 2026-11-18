<?php

namespace App\Modules\Money\Services;

use App\Modules\Money\Contracts\LedgerAggregates;
use App\Modules\Money\Models\WalletTransaction;
use Carbon\Carbon;

/**
 * Stage 2 C2: the admin report's four ledger totals, moved verbatim from the Console service.
 *
 * Same table, same wallet filter, same type lists, same optional `created_at` window, same `sum('amount')` -
 * so every figure the report prints is unchanged. The window is applied inside the query rather than by
 * mutating a builder the caller holds, which is the same SQL with one fewer moving part.
 *
 * The `WalletTransaction` import stays a kernel path until the model moves into this module later in the phase.
 */
final class LedgerAggregateReader implements LedgerAggregates
{
    /** Ledger type spellings, kept exactly as the live and historical writers produce them. */
    private const ESCROW_RECEIVED = ['escrow_received'];

    private const ESCROW_RELEASED = ['escrow_release', 'escrow_released'];

    private const REFUNDS = ['driver_cancellation_refunds', 'cancellation_processing', 'driver_no_show_refund'];

    private const PLATFORM_FEES = ['platform_fee'];

    public function escrowReceived(int $walletId, ?Carbon $start, ?Carbon $end): float
    {
        return $this->sum($walletId, self::ESCROW_RECEIVED, $start, $end);
    }

    public function escrowReleased(int $walletId, ?Carbon $start, ?Carbon $end): float
    {
        return $this->sum($walletId, self::ESCROW_RELEASED, $start, $end);
    }

    public function refundsPaid(int $walletId, ?Carbon $start, ?Carbon $end): float
    {
        return $this->sum($walletId, self::REFUNDS, $start, $end);
    }

    public function platformFees(int $walletId, ?Carbon $start, ?Carbon $end): float
    {
        return $this->sum($walletId, self::PLATFORM_FEES, $start, $end);
    }

    /**
     * @param  array<int, string>  $types
     */
    private function sum(int $walletId, array $types, ?Carbon $start, ?Carbon $end): float
    {
        $query = WalletTransaction::where('wallet_id', $walletId)->whereIn('type', $types);

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return (float) $query->sum('amount');
    }
}
