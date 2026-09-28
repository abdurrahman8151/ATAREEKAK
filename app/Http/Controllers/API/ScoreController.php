<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScoreTransactionResource;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\UserScore;
use App\Services\Score\ScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ScoreController extends Controller
{
    public function __construct(private readonly ScoreService $scoreService) {}

    // =========================================================================
    // EXISTING ENDPOINTS (unchanged)
    // =========================================================================

    /**
     * GET /api/score
     * Returns the authenticated user's current score, tier, and stats.
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = Cache::remember("score.user.{$userId}", 120, function () use ($request) {
            return $this->formatScore($this->scoreService->getScore($request->user()));
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * GET /api/score/history?limit=20
     * Returns recent score transaction history (lightweight, cached).
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min((int) $request->get('limit', 20), 50);

        $data = Cache::remember("score.history.{$userId}.{$limit}", 60, function () use ($request, $limit) {
            return $this->scoreService->getHistory($request->user(), $limit)->map(fn ($tx) => [
                'id' => $tx->id,
                'action' => $tx->action,
                'points' => $tx->formatted_points,
                'previous_score' => $tx->previous_score,
                'new_score' => $tx->new_score,
                'reason' => $tx->reason,
                'high_cancel_rate_applied' => $tx->high_cancel_rate_applied,
                'reference_type' => class_basename($tx->reference_type ?? ''),
                'reference_id' => $tx->reference_id,
                'created_at' => $tx->created_at->toIso8601String(),
            ])->all();
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // =========================================================================
    // NEW ENDPOINT
    // =========================================================================

    /**
     * GET /api/score/transactions?limit=20&page=1
     *
     * Paginated score transaction feed with full context for each event:
     *   • Signed points display  (+10, −5, −15 …)
     *   • Human-readable summary ("−5 pts — Driver cancelled seat")
     *   • Score before/after/delta snapshot
     *   • Linked Ride or Booking details (origin, destination, departure_time,
     *     status …) loaded in a single batch query — no N+1 problems
     *
     * Query params:
     *   limit  int  1–50 (default 20)
     *   page   int  (default 1)
     *
     * Response shape:
     * {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 42,
     *       "event": {
     *         "code":    "ride_completed",
     *         "label":   "Ride completed",
     *         "summary": "+10 pts — Ride completed"
     *       },
     *       "points":  { "value": 10, "display": "+10" },
     *       "score":   { "before": 80, "after": 90, "delta": 10 },
     *       "reason":  "Ride completed successfully — all parties rewarded",
     *       "high_cancel_rate_applied": false,
     *       "reference": {
     *         "type":           "Ride",
     *         "id":             7,
     *         "origin":         "Amman, Jordan",
     *         "destination":    "Zarqa, Jordan",
     *         "departure_time": "2026-08-17T09:00:00+03:00",
     *         "status":         "completed"
     *       },
     *       "occurred_at": "2026-08-17T09:35:00+00:00"
     *     }
     *   ],
     *   "meta": {
     *     "total": 84,
     *     "per_page": 20,
     *     "current_page": 1,
     *     "last_page": 5
     *   }
     * }
     */
    public function transactions(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 50);

        $paginator = ScoreTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($limit);

        // Batch-load Ride / Booking models — prevents N+1 without changing
        // the ScoreTransaction schema or adding a polymorphic relation.
        $this->hydrateReferences($paginator->items());

        return response()->json([
            'success' => true,
            'data' => ScoreTransactionResource::collection($paginator),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Reusable score formatter used by other controllers. */
    public static function formatScore(UserScore $userScore): array
    {
        return [
            'score' => $userScore->score,
            'tier' => $userScore->tier,
            'cancel_rate' => round($userScore->cancel_rate, 2),
            'total_rides' => $userScore->total_rides,
            'total_cancellations' => $userScore->total_cancellations,
            'can_create_rides' => $userScore->score >= 50,
            'can_book_rides' => $userScore->score >= 40,
        ];
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
