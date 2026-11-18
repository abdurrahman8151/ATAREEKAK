<?php

namespace App\Modules\Money\Services;

use App\Modules\Money\Contracts\LedgerAggregates;
use App\Modules\Money\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Stage 2 C2: the escrow totals the backfill command derives its snapshot from - the SAME reduction the
     * command ran itself, now inside the module that owns the rows (and the `escrow_received` vocabulary).
     *
     * @return array<int, float> booking id => escrow received
     */
    public function escrowTotalsByBooking(): array
    {
        return WalletTransaction::query()
            ->where('type', 'escrow_received')
            ->whereNotNull('reference')
            ->get()
            ->reduce(function (array $carry, WalletTransaction $transaction) {
                if (preg_match('/^booking:(\d+)$/', (string) $transaction->reference, $matches)) {
                    // Sum in case a booking was charged across multiple rows (partial re-charge).
                    $carry[(int) $matches[1]] = ($carry[(int) $matches[1]] ?? 0.0) + (float) $transaction->amount;
                }

                return $carry;
            }, []);
    }

    /**
     * Stage 2 C2: the stuck-escrow population, moved from the Console command that used to query this table.
     *
     * @return array<int, array{reference: string, wallet_id: int, first_seen: string, net: float}>
     */
    public function heldEscrowReferences(): array
    {
        return DB::table('wallet_transactions')
            ->select('reference', 'wallet_id')
            ->selectRaw('MIN(created_at) as first_seen')
            ->selectRaw('SUM(new_balance - previous_balance) as net')
            ->selectRaw("SUM(CASE WHEN type = 'escrow_received' THEN 1 ELSE 0 END) as escrow_in_legs")
            ->where('reference', 'like', 'booking:%')
            ->groupBy('reference', 'wallet_id')
            ->havingRaw('escrow_in_legs > 0')
            ->havingRaw('net > 0')
            ->orderBy('net', 'desc')
            ->get()
            ->map(fn ($row) => [
                'reference' => (string) $row->reference,
                'wallet_id' => (int) $row->wallet_id,
                'first_seen' => (string) $row->first_seen,
                'net' => (float) $row->net,
            ])
            ->all();
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
