<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Services\Staff\ReviewModerationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * StaffReviewController
 *
 * UC-ADM-03: View and moderate user-to-user comments.
 *
 * All routes protected by the `staff` middleware (any role).
 *
 * Routes:
 *   GET    /api/staff/reviews          → index()
 *   DELETE /api/staff/reviews/{id}     → destroy()
 */
final class StaffReviewController extends Controller
{
    public function __construct(
        private readonly ReviewModerationService $reviewService,
    ) {}

    // ── GET /api/staff/reviews ──────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'sometimes|integer|exists:users,id',
            'search' => 'sometimes|string|max:255',
            'date' => 'sometimes|in:last_7_days,last_30_days',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $paginator = $this->reviewService->getComments(
            userId: $request->integer('user_id') ?: null,
            search: $request->get('search'),
            date: $request->get('date'),
            perPage: (int) $request->get('per_page', 15),
            page: (int) $request->get('page', 1),
        );

        return response()->json([
            'status' => 'success',
            'data' => $paginator->getCollection()
                ->map(fn ($c) => $this->reviewService->format($c))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    // ── DELETE /api/staff/reviews/{id} ──────────────────────────────────────
    public function destroy(int $commentId): JsonResponse
    {
        try {
            $this->reviewService->deleteComment($commentId);

            return response()->json([
                'status' => 'success',
                'message' => 'Comment deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Comment not found.',
            ], 404);
        }
    }
}
