<?php

namespace App\Modules\Ride\Http\Controllers;

use App\DTOs\Score\ScoreFormatter;
use App\Http\Controllers\Controller;
use App\Modules\Ride\Http\Resources\ScoreTransactionResource;
use App\Modules\Ride\Services\ScoreHistoryService;
use App\Modules\Ride\Services\ScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ScoreController extends Controller
{
    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly ScoreHistoryService $scoreHistoryService,
    ) {}

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

        $paginator = $this->scoreHistoryService->paginateForUser($request->user()->id, $limit);

        // The page items arrive already hydrated by ScoreHistoryService; only kept here as a
        // reminder that the resource reads referenceModel. Batch-load Ride / Booking models — prevents N+1 without changing
        // the ScoreTransaction schema or adding a polymorphic relation.
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
    public static function formatScore($userScore): array
    {
        return ScoreFormatter::format($userScore);
    }
}
