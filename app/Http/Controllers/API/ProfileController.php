<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Support\ProfileInteractionHandler;
use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Services\Profile\ProfileInteractionService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\Ride\UserRideStatsService;
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
        private readonly UserRideStatsService $rideStats,
        private readonly ProfileInteractionHandler $interactionHandler,
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

        $validator = Validator::make($request->all(), $this->profileUpdateRules());

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $data = $this->castBooleanFields($request, $validator->validated());

        if (isset($data['number_of_rides'])) {
            return $this->rideCountRefused();
        }

        try {
            $profile = $this->profileRepo->getProfileByUserId($user->id);

            $scope = $this->determineRevocationScope($user, $profile, $data);

            // ── Apply changes atomically ──────────────────────────────────────
            $result = DB::transaction(function () use ($user, $data, $scope) {
                $this->applyRevocation($user, $scope['revoke_all'], $scope['revoke_driver']);

                return $this->updateService->updateProfile($user, $data);
            });

            $this->bustProfileCaches($user->id, $scope['revoke_all'], $scope['revoke_driver']);

            return response()->json(
                $this->profileUpdateResponse($result, $scope['revoke_all'], $scope['revoke_driver'])
            );

        } catch (\Exception $e) {
            // RV-13: the message goes to the LOG, not the client - a QueryException carries the SQL
            // and the table names.
            //
            // `$e->getCode() ?: 500` is ALSO removed as an HTTP status: an exception's code is not an
            // HTTP status. A PDOException carries 23000 (integrity constraint) or 42S02, and
            // `response()->json($body, 23000)` is not a valid response - so the old line could turn
            // a caught database error into a hard failure inside the error handler. 500 is correct:
            // at this point we genuinely do not know why it failed.
            Log::error('Profile: update failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update the profile. Please try again.',
            ], 500);
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
        return $this->interactionHandler->comment($request->user()->id, $userId, $request->all());
    }
    // =========================================================================
    // RATE
    // =========================================================================

    /**
     * POST /profile/{userId}/rate
     */
    public function rateUser(Request $request, int $userId)
    {
        return $this->interactionHandler->rateUser($request->user()->id, $userId, $request->all());
    }
    // =========================================================================
    // PRIVATE — FORMAT
    // =========================================================================

    /** The 422 validation envelope of POST /profile. */
    private function validationFailed(\Illuminate\Contracts\Validation\Validator $validator)
    {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    /** ride count is server-owned: it is derived from ride history, never submitted. */
    private function rideCountRefused()
    {
        return response()->json([
            'success' => false,
            'message' => 'Ride count cannot be updated manually.',
        ], 422);
    }

    /** The accepted fields of POST /profile (riders/vehicles/documents). */
    private function profileUpdateRules(): array
    {
        return [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'address' => 'nullable|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,إدلب,الحسكة,الرقة,دير الزور',
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
        ];
    }

    /**
     * The validated data with the booleans cast.
     *
     * `sometimes|boolean` accepts "1"/"0" as strings; the columns want real booleans, and the
     * cast only happens for keys the request actually carried.
     *
     * @return array<string, mixed>
     */
    private function castBooleanFields(Request $request, array $data): array
    {
        foreach (['radio', 'smoking'] as $key) {
            if ($request->has($key)) {
                $data[$key] = (bool) $request->input($key);
            }
        }

        return $data;
    }

    /**
     * Which verification(s) this update invalidates - see the class-level revocation matrix.
     *
     * Identity fields: name and gender appear on the national ID card. Any change means the
     * document the admin reviewed no longer matches the current account holder, so ALL
     * verification is revoked (the old values are read BEFORE the service writes new ones, so
     * the same value submitted again does NOT revoke anything).
     *
     * Vehicle fields: only relevant for driver verification, because the admin checked them
     * against the mechanic card during approval. Driver-only revocation only fires when
     * identity is NOT already being revoked (avoids a redundant double-write to the user row).
     *
     * @return array{revoke_all: bool, revoke_driver: bool}
     */
    private function determineRevocationScope($user, $profile, array $data): array
    {
        $identityChanging =
            (isset($data['first_name']) && $data['first_name'] !== $user->first_name)
            || (isset($data['last_name']) && $data['last_name'] !== $user->last_name)
            || (isset($data['gender']) && $data['gender'] !== $user->gender);

        $vehicleChanging = $profile !== null && (
            (isset($data['type_of_car']) && $data['type_of_car'] !== $profile->type_of_car)
            || (isset($data['color_of_car']) && $data['color_of_car'] !== $profile->color_of_car)
            || (isset($data['number_of_seats']) && (int) $data['number_of_seats'] !== (int) $profile->number_of_seats)
        );

        $revokeAll = $identityChanging
            && ($user->is_verified_passenger || $user->is_verified_driver);

        $revokeDriver = ! $revokeAll
            && $vehicleChanging
            && $user->is_verified_driver;

        return ['revoke_all' => $revokeAll, 'revoke_driver' => $revokeDriver];
    }

    /** Clear the verification flags the updated fields invalidated (idempotent). */
    private function applyRevocation($user, bool $revokeAll, bool $revokeDriver): void
    {
        if ($revokeAll) {
            $user->update([
                'is_verified_passenger' => false,
                'is_verified_driver' => false,
                'verification_status' => 'none',
            ]);

            return;
        }

        if (! $revokeDriver) {
            return;
        }

        $updates = ['is_verified_driver' => false];

        // If the user has no remaining verification keep verification_status consistent.
        if (! $user->is_verified_passenger) {
            $updates['verification_status'] = 'none';
        }

        $user->update($updates);
    }

    /** Bust every cached profile view, plus the verification caches after a revocation. */
    private function bustProfileCaches(int $userId, bool $revokeAll, bool $revokeDriver): void
    {
        Cache::forget("profile.user.{$userId}");
        Cache::forget("profile.user.owner.{$userId}");
        Cache::forget("admin.driver.profile.{$userId}");
        Cache::forget("admin.driver.dashboard.{$userId}");
        Cache::forget("admin.passenger.full-profile.{$userId}");
        Cache::forget("admin.passenger.stats.{$userId}");

        if ($revokeAll || $revokeDriver) {
            Cache::forget("verification.status.{$userId}");
            Cache::forget('staff.pending-verifications');
        }
    }

    /**
     * The update response, with the revocation warning when one applies.
     *
     * @param  array{profile: mixed, user: mixed}  $result
     * @return array<string, mixed>
     */
    private function profileUpdateResponse(array $result, bool $revokeAll, bool $revokeDriver): array
    {
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

        return $response;
    }

    /**
     * The owner's document URLs, and an empty list for anyone else.
     *
     * RV-01: face/back ID and licence scans are personal data. $isOwner was accepted and then
     * ignored, so GET /api/profile/{anyUserId} returned the document URLs of ANY user to ANY
     * authenticated caller. Documents are now emitted for the owner only; other callers get the
     * profile without them. Owner and non-owner payloads are cached under different keys
     * (profile.user.owner.{id} vs profile.user.{id}), so this gate cannot be defeated by the cache.
     */
    private function ownerDocuments($user, bool $isOwner): array
    {
        if (! $isOwner) {
            return [];
        }

        return $this->photoRepo->getUserDocumentsByType(
            $user->id,
            ['face_id', 'back_id', 'license', 'mechanic_card']
        )->mapWithKeys(fn ($d) => ["{$d->type}_pic" => asset("storage/{$d->path}")])->toArray();
    }

    /**
     * Ride history as driver - 1 query instead of 4.
     *
     * AF-7: the query lives in UserRideStatsService; this was duplicated verbatim in
     * StaffOperationsController. The reshaping below stays here because `total_created` /
     * `no_show` are THIS endpoint's public shape.
     */
    private function rideHistoryAsDriver($user): array
    {
        $driverStats = $this->rideStats->ridesAsDriver($user->id);

        return [
            'total_created' => $driverStats->sum(),
            'completed' => $driverStats->get('finished', 0),
            'cancelled' => $driverStats->get('cancelled', 0),
            'no_show' => $driverStats->get('awaiting_confirmation', 0),
        ];
    }

    /** Ride history as passenger - 1 query instead of 4 (same AF-7 reshaping rule). */
    private function rideHistoryAsPassenger($user): array
    {
        $passengerStats = $this->rideStats->bookingsAsPassenger($user->id);

        return [
            'total_booked' => $passengerStats->sum(),
            'completed' => $passengerStats->get('completed', 0),
            'cancelled' => $passengerStats->get('cancelled', 0),
            'no_show' => $passengerStats->get('no_show', 0),
        ];
    }

    private function formatProfileData($profile, $user, bool $isOwner = false): array
    {
        $comments = $this->interactionService->getProfileComments($user->id);
        $ratingStats = $this->interactionService->getRatingStats($user->id);

        $docs = $this->ownerDocuments($user, $isOwner);

        $userScore = $this->scoreService->getScore($user);

        $asDriver = $this->rideHistoryAsDriver($user);
        $asPassenger = $this->rideHistoryAsPassenger($user);

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
