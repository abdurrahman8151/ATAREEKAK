<?php

namespace App\Services\Score;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The paginated score-transaction history and the batch hydration its resource needs.
 *
 * Moved out of ScoreController (batch 227) so the controller holds no Eloquent reference. The
 * query, the ordering, the page size and the hydration strategy are unchanged: the same one
 * query per reference type, attached with setRelation('referenceModel') so
 * ScoreTransactionResource never re-reads the database.
 */
final class ScoreHistoryService
{
    public function paginateForUser(int $userId, int $limit): LengthAwarePaginator
    {
        $paginator = ScoreTransaction::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($limit);

        // Batch-load Ride / Booking models — prevents N+1 without changing
        // the ScoreTransaction schema or adding a polymorphic relation.
        $this->hydrateReferences($paginator->items());

        return $paginator;
    }

    /**
     * Batch-hydrate the reference model on each ScoreTransaction to prevent N+1.
     *
     * Groups transactions by reference_type, issues ONE query per type,
     * then attaches the result via setRelation('referenceModel', ...) so the
     * ScoreTransactionResource can read it without hitting the DB again.
     *
     * Booking references also eager-load their parent Ride in the same query.
     *
     * @param  ScoreTransaction[]  $transactions
     */
    private function hydrateReferences(array $transactions): void
    {
        // Group page items by their reference class name
        $groups = collect($transactions)->groupBy('reference_type');

        foreach ($groups as $className => $txGroup) {
            if (! $className) {
                continue; // transactions with no reference (edge case)
            }

            $ids = $txGroup->pluck('reference_id')->filter()->unique()->values();
            $type = class_basename($className);

            // One query per reference type with any required eager loads
            $models = match ($type) {
                'Ride' => Ride::whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),

                'Booking' => Booking::with('ride')
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),

                default => collect(),
            };

            // Attach the resolved model so ScoreTransactionResource can read it
            foreach ($txGroup as $tx) {
                $tx->setRelation('referenceModel', $models->get($tx->reference_id));
            }
        }
    }
}
