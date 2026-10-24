<?php

namespace App\Http\Controllers\API\Support;

use App\Services\Profile\ProfileInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The comment and rating actions, split out of ProfileController.
 *
 * The controller keeps its routes, method signatures and delegation; this class owns the
 * validation rules, the response bodies, the statuses and the cache busts. It takes the actor
 * id and the request payload array, never a Request, so it sits on the HTTP support side of
 * the boundary and the request budget elsewhere is untouched.
 */
final class ProfileInteractionHandler
{
    public function __construct(
        private readonly ProfileInteractionService $interactionService,
    ) {}

    /**
     * POST /profile/{userId}/comments
     */
    public function comment(int $actorId, int $userId, array $input): JsonResponse
    {
        $validator = Validator::make($input, [
            'comment' => 'required|string|max:500',
            'ride_id' => 'required|integer|exists:rides,id',  // required: comment only after a completed ride
        ], [
            'ride_id.required' => 'A ride ID is required. You can only comment after completing a ride.',
            'ride_id.exists' => 'The specified ride does not exist.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $comment = $this->interactionService->addComment(
                $actorId,
                $userId,
                $input['comment'],
                (int) $input['ride_id'],
            );

            Cache::forget("profile.user.{$userId}");
            Cache::forget("profile.user.owner.{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Comment added',
                'data' => $comment,
            ], 201);

        } catch (\Exception $e) {
            // RV-13: message to the log, not the client. Fixed 500, not $e->getCode() - an
            // exception code is not an HTTP status (a PDOException carries 23000, and
            // response()->json($body, 23000) is not a valid response).
            Log::error('Profile: add comment failed', [
                'user_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add the comment. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /profile/{userId}/rate
     */
    public function rateUser(int $actorId, int $userId, array $input): JsonResponse
    {
        $validator = Validator::make($input, [
            'rating' => 'required|numeric|min:1|max:5',
            'ride_id' => 'required|integer|exists:rides,id',  // required: rate only after a completed ride
        ], [
            'ride_id.required' => 'A ride ID is required. You can only rate after completing a ride.',
            'ride_id.exists' => 'The specified ride does not exist.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $ratingStats = $this->interactionService->rateUser(
                $actorId,
                $userId,
                (float) $input['rating'],
                (int) $input['ride_id'],
            );

            Cache::forget("profile.user.{$userId}");
            Cache::forget("profile.user.owner.{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Rating submitted successfully',
                'data' => $ratingStats,
            ]);

        } catch (\Exception $e) {
            // RV-13: message to the log, not the client. Fixed 500, not $e->getCode() - an
            // exception code is not an HTTP status (a PDOException carries 23000, and
            // response()->json($body, 23000) is not a valid response).
            Log::error('Profile: rating stats failed', [
                'user_id' => $actorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load rating statistics. Please try again.',
            ], 500);
        }
    }
}
