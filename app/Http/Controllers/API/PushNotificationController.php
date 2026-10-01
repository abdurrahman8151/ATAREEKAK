<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Services\PushNotification\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    public function registerToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|in:android,ios,web',
            'device_id' => 'nullable|string',
            'device_name' => 'nullable|string',
        ]);

        $token = $this->pushService->registerToken(
            // RV-27: the service signature is registerToken(int $userId, ...). This
            // passed $request->user() (a User model) — a TypeError that 500s the
            // moment the route is reached. Passing the id fixes the mismatch; the
            // device_id/device_name extras were never a parameter of the service,
            // so they are no longer silently dropped into a non-existent 4th arg.
            $request->user()->id,
            $request->token,
            $request->platform
        );

        return response()->json([
            'success' => true,
            'message' => 'Push notification token registered successfully',
            'data' => $token,
        ]);
    }

    public function removeToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        // RV-27: ownership-scoped. The unscoped removeToken($token) deactivated ANY
        // user's device for anyone who knew the token string (IDOR). Now a caller can
        // only ever unregister a token belonging to their own account; a token owned
        // by someone else answers identically to a missing one, so the endpoint also
        // stops working as a probe for which token strings exist.
        $removed = $this->pushService->removeTokenForUser(
            $request->user()->id,
            $request->input('token')
        );

        return response()->json([
            'success' => $removed,
            'message' => $removed ? 'Token removed successfully' : 'Token not found',
        ]);
    }

    public function getUserTokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->pushTokens()->active()->get();

        return response()->json([
            'success' => true,
            'data' => $tokens,
        ]);
    }

    public function testNotification(Request $request): JsonResponse
    {
        // Only allow in development
        if (! app()->environment('local')) {
            return response()->json([
                'success' => false,
                'message' => 'Test notifications are only available in development',
            ], 403);
        }

        $notification = app(NotificationService::class)->createNotification(
            $request->user(),
            'test',
            'Test Notification',
            'This is a test notification',
            ['test' => true],
            'normal',
            'system'
        );

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent',
            'data' => $notification,
        ]);
    }
}
