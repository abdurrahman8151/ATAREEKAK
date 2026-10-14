<?php

namespace App\Services\Staff;

use App\Models\Profile;
use App\Models\UserRating;
use App\Services\Ride\UserRideStatsService;
use App\Services\Score\ScoreService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * L8 (modular-monolith slice 7): builds the staff view of a user's public profile.
 *
 * `StaffOperationsController::userProfile()` used to build this inside its cache closure: the score, the
 * rating stats, the ride history split by role, the comments, and the output shape. This service owns that
 * read logic. The controller keeps the HTTP concerns: the cache call (key and TTL unchanged, so
 * StaffAdminController's busting still works), the 404 and the envelope.
 *
 * The output shape is unchanged and is pinned by tests/Feature/Review/L8StaffUserProfileCharacterizationTest.php.
 */
final class StaffUserProfileService
{
    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly UserRideStatsService $rideStats,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the user has no profile.
     */
    public function build(int $userId): array
    {
        $profile = Profile::with([
            'user',
            'comments.commenter:id,first_name,last_name',
        ])->where('user_id', $userId)->firstOrFail();

        $user = $profile->user;

        // ── Score ──────────────────────────────────────────────────────────
        $userScore = $this->scoreService->getScore($user);

        // ── Rating stats ───────────────────────────────────────────────────
        $ratingStats = UserRating::where('rated_user_id', $userId)
            ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
            ->first();

        // ── Ride history: as driver — 1 query instead of 3 ────────────────
        // AF-7: the query lives in UserRideStatsService; this was duplicated verbatim in
        // ProfileController. The reshaping below stays here because `total` (rather than
        // ProfileController's `total_created`) is THIS endpoint's public shape.
        $driverStats = $this->rideStats->ridesAsDriver($userId);

        $asDriver = [
            'total' => $driverStats->sum(),
            'completed' => $driverStats->get('finished', 0),
            'cancelled' => $driverStats->get('cancelled', 0),
        ];

        // ── Ride history: as passenger — 1 query instead of 3 ─────────────
        $passengerStats = $this->rideStats->bookingsAsPassenger($userId);

        $asPassenger = [
            'total' => $passengerStats->sum(),
            'completed' => $passengerStats->get('completed', 0),
            'cancelled' => $passengerStats->get('cancelled', 0),
        ];

        // ── Comments received ──────────────────────────────────────────────
        $comments = $profile->comments->map(fn ($c) => [
            'id' => $c->id,
            'comment' => $c->comment,
            'commenter' => [
                'id' => $c->commenter?->id,
                'name' => trim(($c->commenter?->first_name ?? '').' '.($c->commenter?->last_name ?? '')),
            ],
            'created_at' => $c->created_at->toIso8601String(),
        ])->values()->all();

        return [
            'id' => $user->id,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'gender' => $user->gender,
            'address' => $user->address,
            'verification_status' => $user->verification_status,
            'is_verified_driver' => (bool) $user->is_verified_driver,
            'is_verified_passenger' => (bool) $user->is_verified_passenger,
            'account_status' => $user->isBannedNow() ? 'suspended' : 'active',
            'joined_at' => $user->created_at->toIso8601String(),
            'profile_photo' => $profile->profile_photo
                ? asset("storage/{$profile->profile_photo}")
                : null,
            'description' => $profile->description,
            'score' => [
                'score' => $userScore->score,
                'tier' => $userScore->tier,
                'cancel_rate' => round($userScore->cancel_rate, 2),
                'total_rides' => $userScore->total_rides,
                'total_cancellations' => $userScore->total_cancellations,
            ],
            'rating' => [
                'average' => $ratingStats->average ?? 0,
                'total_ratings' => (int) ($ratingStats->total ?? 0),
            ],
            'ride_history' => [
                'as_driver' => $asDriver,
                'as_passenger' => $asPassenger,
            ],
            'comments_received' => $comments,
        ];
    }
}
