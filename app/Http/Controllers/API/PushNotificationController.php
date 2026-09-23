<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Services\PushNotification\PushNotificationService;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PushNotificationController extends Controller
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    /**
     * Accepts either `device_type` (the column name) or `platform` as the
     * device field, so both mobile client payload shapes work.
     */
    public function registerToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'       => 'required|string|max:191',
            'device_type' => 'required_without:platform|in:android,ios,web',
            'platform'    => 'required_without:device_type|in:android,ios,web',
        ]);

        $token = $this->pushService->registerToken(
            $request->user()->id,
            $validated['token'],
            $validated['device_type'] ?? $validated['platform']
        );

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register push notification token'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Push notification token registered successfully',
            'data' => $token
        ]);
    }

    public function removeToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        // Scoped to the caller so one user cannot deactivate another's token.
        $removed = $this->pushService->removeToken($request->token, $request->user()->id);

        return response()->json([
            'success' => $removed,
            'message' => $removed ? 'Token removed successfully' : 'Token not found'
        ]);
    }

    public function getUserTokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->pushTokens()->active()->get();

        return response()->json([
            'success' => true,
            'data' => $tokens
        ]);
    }

    public function testNotification(Request $request): JsonResponse
    {
        // Only allow in development
        if (!app()->environment('local')) {
            return response()->json([
                'success' => false,
                'message' => 'Test notifications are only available in development'
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
            'data' => $notification
        ]);
    }
}
