<?php

namespace App\Services\Profile;

use App\Models\Booking;
use App\Models\Profile;
use App\Models\ProfileComment;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Score\ScoreService;

/**
 * ProfileInteractionService — corrected for actual DB schema:
 *   profile_comments.user_id   = the commenter  (was commenter_id)
 *   user_ratings.rater_id      = the rater       (was user_id)
 */
class ProfileInteractionService
{
    public function __construct(
        private readonly ScoreService $scoreService,
    ) {}

    // =========================================================================
    // COMMENT
    // =========================================================================

    public function addComment(
        int    $commenterId,
        int    $profileUserId,
        string $comment,
        int    $rideId,
    ): array {
        $this->assertEligible($commenterId, $profileUserId, $rideId, 'comment on');

        $profile = Profile::where('user_id', $profileUserId)->firstOrFail();

        $alreadyCommented = ProfileComment::where('user_id', $commenterId)   // ← was commenter_id
        ->where('profile_id', $profile->id)
            ->where('ride_id', $rideId)
            ->exists();

        if ($alreadyCommented) {
            throw new \Exception('You have already left a comment for this ride.', 409);
        }

        $profileComment = ProfileComment::create([
            'user_id'    => $commenterId,   // ← was commenter_id
            'profile_id' => $profile->id,
            'comment'    => $comment,
            'ride_id'    => $rideId,
        ]);

        $profileComment->load('commenter:id,first_name,last_name');

        return [
            'id'         => $profileComment->id,
            'comment'    => $profileComment->comment,
            'ride_id'    => $profileComment->ride_id,
            'commenter'  => [
                'id'   => $profileComment->commenter->id,
                'name' => trim(
                    "{$profileComment->commenter->first_name} "
                    . "{$profileComment->commenter->last_name}"
                ),
            ],
            'created_at' => $profileComment->created_at->toIso8601String(),
        ];
    }

    public function getProfileComments(int $userId): array
    {
        $profile = Profile::where('user_id', $userId)->first();

        if (! $profile) {
            return [];
        }

        return ProfileComment::with('commenter:id,first_name,last_name')
            ->where('profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'comment'    => $c->comment,
                'ride_id'    => $c->ride_id,
                'commenter'  => [
                    'id'   => $c->commenter?->id,
                    'name' => trim(
                        ($c->commenter?->first_name ?? '')
                        . ' '
                        . ($c->commenter?->last_name ?? '')
                    ),
                ],
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    // =========================================================================
    // RATING
    // =========================================================================

    public function rateUser(
        int   $raterId,
        int   $ratedUserId,
        float $rating,
        int   $rideId,
    ): array {
        $this->assertEligible($raterId, $ratedUserId, $rideId, 'rate');

        $alreadyRated = UserRating::where('rater_id', $raterId)   // ← was user_id
        ->where('rated_user_id', $ratedUserId)
            ->where('ride_id', $rideId)
            ->exists();

        if ($alreadyRated) {
            throw new \Exception('You have already rated this ride.', 409);
        }

        $userRating = UserRating::create([
            'rater_id'      => $raterId,    // ← was user_id
            'rated_user_id' => $ratedUserId,
            'rating'        => $rating,
            'ride_id'       => $rideId,
        ]);

        $this->scoreService->recordRating(User::findOrFail($ratedUserId), $userRating);

        return $this->getRatingStats($ratedUserId);
    }

    public function getRatingStats(int $userId): array
    {
        $stats = UserRating::where('rated_user_id', $userId)
            ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
            ->first();

        return [
            'average'       => (float) ($stats->average ?? 0),
            'total_ratings' => (int)   ($stats->total   ?? 0),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function assertEligible(
        int    $actorId,
        int    $targetUserId,
        int    $rideId,
        string $action,
    ): void {
        // Case 1: Passenger rating/commenting on Driver
        $passengerOnDriver = Booking::query()
            ->where('user_id', $actorId)
            ->where('ride_id', $rideId)
            ->where('status', 'completed')
            ->whereHas('ride', fn ($q) => $q->where('driver_id', $targetUserId))
            ->exists();

        // Case 2: Driver rating/commenting on Passenger
        $driverOnPassenger = Booking::query()
            ->where('user_id', $targetUserId)
            ->where('ride_id', $rideId)
            ->where('status', 'completed')
            ->whereHas('ride', fn ($q) => $q->where('driver_id', $actorId))
            ->exists();

        if (! $passengerOnDriver && ! $driverOnPassenger) {
            throw new \Exception(
                "You can only {$action} a user after completing a ride together.",
                403
            );
        }
    }
}
