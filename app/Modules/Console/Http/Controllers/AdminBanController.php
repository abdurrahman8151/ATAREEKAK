<?php

namespace App\Modules\Console\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Console\Services\BanService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;      // ← added
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminBanController
 *
 * UC-ADM-12: Ban / Unban User
 *
 * Status codes:
 *   -1 = banned
 *    0 = logged out
 *    1 = active
 *
 * Routes (all behind staff:admin / staff:system_admin middleware):
 *   POST /api/admin/users/{userId}/ban    → ban()
 *   POST /api/admin/users/{userId}/unban  → unban()
 *   GET  /api/admin/users/{userId}/status → userStatus()
 *
 * ── Caching summary ─────────────────────────────────────────────────────────
 *
 *  NOT CACHED  ban()        mutation — also busts status + dashboard caches
 *  NOT CACHED  unban()      mutation — also busts status + dashboard caches
 *  CACHED      userStatus() admin.user.status.{userId}  5 min
 *                           (busted immediately by ban / unban)
 */
final class AdminBanController extends Controller
{
    public function __construct(
        private readonly BanService $banService,
        private readonly NotificationPort $notificationService,
    ) {}

    // ── POST /api/admin/users/{userId}/ban ────────────────────────────────────

    /**
     * Ban a user.
     *
     * Mutation — not cached. After writing, busts:
     *   - The specific user's status cache
     *   - Dashboard and driver stat aggregates (active counts change)
     *
     * Body:
     *   reason      string   required, min 10
     *   type        string   required: permanent | temporary
     *   expires_at  string   required when type=temporary (e.g. "2025-06-01 00:00:00")
     */
    public function ban(int $userId, Request $request): JsonResponse
    {
        if ($error = $this->banRequestError($request)) {
            return $error;
        }

        try {
            $user = $this->banService->findUserOrFail($userId);

            // Prevent banning admin accounts

            if ($user->status == -1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This user is already banned.',
                ], 422);
            }

            // Decision un13: the ban write itself (status + ban fields + token revocation + cache busting)
            // now lives in BanService. This controller validated the input and notifies; it does not
            // decide what a ban IS. Previously the same write existed in three places.
            $user = $this->banService->ban(
                $user,
                $request->input('reason'),
                $request->input('type'),
                $request->input('expires_at'),
                $request->user()?->id,
            );

            Log::info('User banned', [
                'user_id' => $user->id,
                'banned_by' => $request->user()?->id,
                'type' => $request->input('type'),
                'expires_at' => $request->input('expires_at'),
            ]);

            // Notify user
            $this->notifyBannedUser($user, $request);

            return response()->json([
                'status' => 'success',
                'message' => 'User has been banned successfully.',
                'data' => $this->banService->formatUserStatus($user->fresh()),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Ban failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // ── POST /api/admin/users/{userId}/unban ──────────────────────────────────

    /**
     * Lift a ban from a user.
     *
     * Mutation — not cached. Busts the same keys as ban().
     *
     * Body (optional):
     *   admin_notes  string  reason for lifting the ban
     */
    public function unban(int $userId, Request $request): JsonResponse
    {
        if ($error = $this->unbanRequestError($request)) {
            return $error;
        }

        try {
            $user = $this->banService->findUserOrFail($userId);

            if ($user->status != -1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This user is not currently banned.',
                ], 422);
            }

            // Decision un13: same service, same write - see ban() above.
            $this->banService->unban($user, $request->user()?->id);

            Log::info('User unbanned', [
                'user_id' => $userId,
                'unbanned_by' => $request->user()?->id,
                'notes' => $request->input('admin_notes'),
            ]);

            // Notify user
            $this->notifyUnbannedUser($user, $request);

            return response()->json([
                'status' => 'success',
                'message' => 'User has been unbanned. They can now log in again.',
                'data' => $this->banService->formatUserStatus($user->fresh()),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Unban failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // ── GET /api/admin/users/{userId}/status ──────────────────────────────────

    /**
     * CACHED — 5 minutes per user.
     * The cache is invalidated immediately by ban() and unban(), so the worst
     * case staleness is limited to background processes changing status
     * (e.g. an expired temporary ban being lifted by a scheduled job).
     */
    public function userStatus(int $userId): JsonResponse
    {
        try {
            $data = Cache::remember("admin.user.status.{$userId}", 300, function () use ($userId) {
                return $this->banService->formatUserStatus($this->banService->findUserOrFail($userId));
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
    }

    /**
     * Validate the ban payload. Returns the 422 response on failure, null when valid.
     */
    private function banRequestError(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:1000',
            'type' => 'required|in:permanent,temporary',
            'expires_at' => 'required_if:type,temporary|nullable|date|after:now',
        ], [
            'reason.required' => 'A ban reason is required.',
            'reason.min' => 'Ban reason must be at least 10 characters.',
            'type.required' => 'Ban type is required (permanent or temporary).',
            'type.in' => 'Ban type must be permanent or temporary.',
            'expires_at.required_if' => 'An expiry date is required for temporary bans.',
            'expires_at.after' => 'Expiry date must be in the future.',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        return null;
    }

    /**
     * Validate the unban payload. Returns the 422 response on failure, null when valid.
     */
    private function unbanRequestError(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        return null;
    }

    private function notifyBannedUser($user, Request $request): void
    {
        try {
            $expiryNote = $request->input('type') === 'temporary'
                ? ' Your ban expires at '.$request->input('expires_at').'.'
                : '';

            $this->notificationService->createNotification(
                $user,
                'account_banned',
                'Account Banned',
                'Your account has been banned. Reason: '.$request->input('reason').$expiryNote,
                ['ban_type' => $request->input('type')],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            // T3-13: non-fatal by intent, but it must be visible.
            Log::warning('ban notification failed (non-fatal): '.$e->getMessage());
        }
    }

    private function notifyUnbannedUser($user, Request $request): void
    {
        try {
            $this->notificationService->createNotification(
                $user,
                'account_unbanned',
                'Account Restored',
                'Your account ban has been lifted. You can now log in again.'
                .($request->input('admin_notes')
                    ? ' Note: '.$request->input('admin_notes')
                    : ''),
                [],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            // T3-13: non-fatal by intent, but it must be visible.
            Log::warning('unban notification failed (non-fatal): '.$e->getMessage());
        }
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
