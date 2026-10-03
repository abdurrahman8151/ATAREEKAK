<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\BanService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;      // â† added
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
 *   POST /api/admin/users/{userId}/ban    â†’ ban()
 *   POST /api/admin/users/{userId}/unban  â†’ unban()
 *   GET  /api/admin/users/{userId}/status â†’ userStatus()
 *
 * â”€â”€ Caching summary â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 *  NOT CACHED  ban()        mutation â€” also busts status + dashboard caches
 *  NOT CACHED  unban()      mutation â€” also busts status + dashboard caches
 *  CACHED      userStatus() admin.user.status.{userId}  5 min
 *                           (busted immediately by ban / unban)
 */
final class AdminBanController extends Controller
{
    // â”€â”€ POST /api/admin/users/{userId}/ban â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Ban a user.
     *
     * Mutation â€” not cached. After writing, busts:
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

        try {
            $user = User::findOrFail($userId);

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
            $user = app(BanService::class)->ban(
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
            try {
                $expiryNote = $request->input('type') === 'temporary'
                    ? ' Your ban expires at '.$request->input('expires_at').'.'
                    : '';

                app(NotificationService::class)->createNotification(
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

            return response()->json([
                'status' => 'success',
                'message' => 'User has been banned successfully.',
                'data' => $this->formatUserStatus($user->fresh()),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Ban failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // â”€â”€ POST /api/admin/users/{userId}/unban â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Lift a ban from a user.
     *
     * Mutation â€” not cached. Busts the same keys as ban().
     *
     * Body (optional):
     *   admin_notes  string  reason for lifting the ban
     */
    public function unban(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::findOrFail($userId);

            if ($user->status != -1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This user is not currently banned.',
                ], 422);
            }

            // Decision un13: same service, same write - see ban() above.
            app(BanService::class)->unban($user, $request->user()?->id);

            Log::info('User unbanned', [
                'user_id' => $userId,
                'unbanned_by' => $request->user()?->id,
                'notes' => $request->input('admin_notes'),
            ]);

            // Notify user
            try {
                app(NotificationService::class)->createNotification(
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

            return response()->json([
                'status' => 'success',
                'message' => 'User has been unbanned. They can now log in again.',
                'data' => $this->formatUserStatus($user->fresh()),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Unban failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // â”€â”€ GET /api/admin/users/{userId}/status â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * CACHED â€” 5 minutes per user.
     * The cache is invalidated immediately by ban() and unban(), so the worst
     * case staleness is limited to background processes changing status
     * (e.g. an expired temporary ban being lifted by a scheduled job).
     */
    public function userStatus(int $userId): JsonResponse
    {
        try {
            $data = Cache::remember("admin.user.status.{$userId}", 300, function () use ($userId) {
                return $this->formatUserStatus(User::findOrFail($userId));
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
    }

    // â”€â”€ Private â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function formatUserStatus(User $user): array
    {
        $accountStatus = match ((int) $user->status) {
            -1 => 'banned',
            0 => 'logged_out',
            1 => 'active',
            default => 'unknown',
        };

        $data = [
            'user_id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'account_status' => $accountStatus,
            'status_code' => (int) $user->status,
            'ban' => null,
        ];

        if ($user->status == -1) {
            $bannedBy = $user->banned_by
                ? User::select('id', 'first_name', 'last_name')->find($user->banned_by)
                : null;

            $isExpired = $user->ban_type === 'temporary'
                && $user->ban_expires_at !== null
                && now()->gt($user->ban_expires_at);

            $data['ban'] = [
                'reason' => $user->ban_reason,
                'type' => $user->ban_type,
                'banned_at' => $user->banned_at?->toIso8601String(),
                'expires_at' => $user->ban_expires_at?->toIso8601String(),
                'is_expired' => $isExpired,
                'banned_by' => $bannedBy ? [
                    'id' => $bannedBy->id,
                    'name' => trim("{$bannedBy->first_name} {$bannedBy->last_name}"),
                ] : null,
            ];
        }

        return $data;
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
