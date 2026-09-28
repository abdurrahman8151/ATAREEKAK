<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Services\Profile\ProfileInteractionService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\Score\ScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Profile Controller (REFACTORED)
 *
 * Delegates to:
 * - ProfileUpdateService: Profile updates
 * - ProfileInteractionService: Comments and ratings
 * - ProfileRepositoryInterface: Data retrieval
 *
 * ── Caching summary ───────────────────────────────────────────────────────────
 *
 *  CACHED    show()       profile.user.{userId}   3 min  (non-owners only)
 *  NOT CACHED show()      profile owner always gets live data
 *  BUST      update()     profile.user.{id} + admin driver/passenger caches
 *  BUST      comment()    profile.user.{targetId}
 *  BUST      rateUser()   profile.user.{targetId}
 *
 * ── Verification revocation on update() ──────────────────────────────────────
 *
 *  Identity fields (first_name, last_name, gender):
 *    Change → revoke BOTH passenger and driver verification.
 *    These fields are matched against the national ID during the admin review.
 *
 *  Vehicle fields (type_of_car, color_of_car, number_of_seats):
 *    Change → revoke DRIVER verification only.
 *    These are checked against the mechanic card. Passenger status is unaffected.
 *
 *  Document photos (face_id_pic, back_id_pic, license, mechanic_card_pic):
 *    Handled by DocumentController — not accepted here.
 *
 *  All other fields (description, address, radio, smoking, profile_photo, etc.):
 *    No verification impact.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepo,
        private readonly ProfileUpdateService $updateService,
        private readonly ProfileInteractionService $interactionService,
        private readonly PhotoRepositoryInterface $photoRepo,
        private readonly ScoreService $scoreService,
    ) {}

    // =========================================================================
    // SHOW
    // =========================================================================

    /**
     * GET /profile/{userId}
     *
     * CACHED — 3 minutes, non-owners only.
     */
    public function show(Request $request, int $userId)
    {
        try {
            $isOwner = $request->user()->id === $userId;

            if ($isOwner) {
                $data = Cache::remember("profile.user.owner.{$userId}", 60, function () use ($userId) {
                    $profile = $this->profileRepo->getProfileWithUser($userId);

                    return $this->formatProfileData($profile, $profile->user, true);
                });
            } else {
                $data = Cache::remember("profile.user.{$userId}", 180, function () use ($userId) {
                    $profile = $this->profileRepo->getProfileWithUser($userId);

                    return $this->formatProfileData($profile, $profile->user, false);
                });
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error("Profile fetch error: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    /**
     * POST /profile
     *
     * Mutation. May revoke verification depending on which fields change.
     * See class-level doc block for the full revocation matrix.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'address' => 'nullable|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
            'gender' => 'nullable|in:M,F',
            'type_of_car' => 'nullable|string|max:255',
            'color_of_car' => 'nullable|string|max:50',
            'number_of_seats' => 'nullable|integer|min:1|max:12',
            'radio' => 'nullable|boolean',
            'smoking' => 'nullable|boolean',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Cast booleans
        foreach (['radio', 'smoking'] as $key) {
            if ($request->has($key)) {
                $data[$key] = (bool) $request->input($key);
            }
        }

        if (isset($data['number_of_rides'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ride count cannot be updated manually.',
            ], 422);
        }

        try {
            // ── Load current profile for vehicle-field comparison ─────────────
            // We need the old values BEFORE the service writes new ones so we
            // can detect a genuine change (same value submitted → no revocation).
            $profile = $this->profileRepo->getProfileByUserId($user->id);

            // ── Determine what is actually changing ───────────────────────────

            // Identity fields: name and gender appear on the national ID card.
            // Any change means the document the admin reviewed no longer matches
            // the current account holder → revoke ALL verification.
            $identityChanging =
                (isset($data['first_name']) && $data['first_name'] !== $user->first_name)
                || (isset($data['last_name']) && $data['last_name'] !== $user->last_name)
                || (isset($data['gender']) && $data['gender'] !== $user->gender);

            // Vehicle fields: only relevant for driver verification.
            // The admin checked these against the mechanic card during approval.
            $vehicleChanging = $profile !== null && (
                (isset($data['type_of_car']) && $data['type_of_car'] !== $profile->type_of_car)
                || (isset($data['color_of_car']) && $data['color_of_car'] !== $profile->color_of_car)
                || (isset($data['number_of_seats']) && (int) $data['number_of_seats'] !== (int) $profile->number_of_seats)
            );

            // ── Decide revocation scope ───────────────────────────────────────
            $revokeAll = $identityChanging
                && ($user->is_verified_passenger || $user->is_verified_driver);

            // Driver-only revocation only fires when identity is NOT already
            // being revoked (avoids a redundant double-write to the user row).
            $revokeDriver = ! $revokeAll
                && $vehicleChanging
                && $user->is_verified_driver;

            // ── Apply changes atomically ──────────────────────────────────────
            $result = DB::transaction(function () use ($user, $data, $revokeAll, $revokeDriver) {

                if ($revokeAll) {
                    $user->update([
                        'is_verified_passenger' => false,
                        'is_verified_driver' => false,
                        'verification_status' => 'none',
                    ]);
                } elseif ($revokeDriver) {
                    $updates = ['is_verified_driver' => false];

                    // If the user has no remaining verification keep
                    // verification_status consistent.
                    if (! $user->is_verified_passenger) {
                        $updates['verification_status'] = 'none';
                    }

                    $user->update($updates);
                }

                return $this->updateService->updateProfile($user, $data);
            });

            // ── Cache busting ─────────────────────────────────────────────────
            // ── Cache busting ─────────────────────────────────────────────────
            Cache::forget("profile.user.{$user->id}");
            Cache::forget("profile.user.owner.{$user->id}");
            Cache::forget("admin.driver.profile.{$user->id}");
            Cache::forget("admin.driver.dashboard.{$user->id}");
            Cache::forget("admin.passenger.full-profile.{$user->id}");
            Cache::forget("admin.passenger.stats.{$user->id}");

            if ($revokeAll || $revokeDriver) {
                Cache::forget("verification.status.{$user->id}");
                Cache::forget('staff.pending-verifications');
            }

            // ── Response ──────────────────────────────────────────────────────
            $response = [
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $this->formatProfileData($result['profile'], $result['user']),
            ];

            if ($revokeAll) {
                $response['warning'] = 'Changing your name or gender has revoked your verification. '
                    .'Please re-submit your documents to become verified again.';
            } elseif ($revokeDriver) {
                $response['warning'] = 'Changing your vehicle information has revoked your driver verification. '
                    .'Please re-submit your vehicle documents.';
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error("Profile update error: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    // =========================================================================
    // COMMENT
    // =========================================================================

    /**
     * POST /profile/{userId}/comments
     */
    public function comment(Request $request, int $userId)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500',
            'ride_id' => 'required|integer|exists:rides,id',  // ← new required field
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
                $request->user()->id,
                $userId,
                $request->input('comment'),
                (int) $request->input('ride_id'),  // ← pass ride_id to service
            );

            Cache::forget("profile.user.{$userId}");
            Cache::forget("profile.user.owner.{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Comment added',
                'data' => $comment,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    // =========================================================================
    // RATE
    // =========================================================================

    /**
     * POST /profile/{userId}/rate
     */
    public function rateUser(Request $request, int $userId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
            'ride_id' => 'required|integer|exists:rides,id',  // ← new required field
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
                $request->user()->id,
                $userId,
                (float) $request->input('rating'),
                (int) $request->input('ride_id'),  // ← pass ride_id to service
            );

            Cache::forget("profile.user.{$userId}");
            Cache::forget("profile.user.owner.{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Rating submitted successfully',
                'data' => $ratingStats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    // =========================================================================
    // PRIVATE — FORMAT
    // =========================================================================

    private function formatProfileData($profile, $user, bool $isOwner = false): array
    {
        // ── Comments & rating ─────────────────────────────────────────────────
        $comments = $this->interactionService->getProfileComments($user->id);
        $ratingStats = $this->interactionService->getRatingStats($user->id);

        // ── Documents ─────────────────────────────────────────────────────────
        $docs = $this->photoRepo->getUserDocumentsByType(
            $user->id,
            ['face_id', 'back_id', 'license', 'mechanic_card']
        )->mapWithKeys(fn ($d) => ["{$d->type}_pic" => asset("storage/{$d->path}")])->toArray();

        // ── Score ─────────────────────────────────────────────────────────────
        $userScore = $this->scoreService->getScore($user);

        // ── Ride history: as driver — 1 query instead of 4 ───────────────────
        $driverStats = Ride::where('driver_id', $user->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $asDriver = [
            'total_created' => $driverStats->sum(),
            'completed' => $driverStats->get('finished', 0),
            'cancelled' => $driverStats->get('cancelled', 0),
            'no_show' => $driverStats->get('awaiting_confirmation', 0),
        ];

        // ── Ride history: as passenger — 1 query instead of 4 ────────────────
        $passengerStats = Booking::where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $asPassenger = [
            'total_booked' => $passengerStats->sum(),
            'completed' => $passengerStats->get('completed', 0),
            'cancelled' => $passengerStats->get('cancelled', 0),
            'no_show' => $passengerStats->get('no_show', 0),
        ];

        // ── Assemble ──────────────────────────────────────────────────────────
        return [
            'user_id' => $user->id,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'verification_status' => $user->verification_status,
            'address' => $profile->address,
            'gender' => $profile->gender,
            'profile_photo' => $profile->profile_photo
                ? asset("storage/{$profile->profile_photo}")
                : null,
            'description' => $profile->description,
            'type_of_car' => $profile->type_of_car,
            'color_of_car' => $profile->color_of_car,
            'number_of_seats' => $profile->number_of_seats,
            'car_pic' => $profile->car_pic
                ? asset("storage/{$profile->car_pic}")
                : null,
            'radio' => $profile->radio,
            'smoking' => $profile->smoking,
            'number_of_rides' => $profile->number_of_rides,
            'documents' => $docs,
            'score' => ScoreController::formatScore($userScore),
            'ride_history' => [
                'as_driver' => $asDriver,
                'as_passenger' => $asPassenger,
            ],
            'comments' => $comments,
            'rating' => $ratingStats,
        ];
    }
}
