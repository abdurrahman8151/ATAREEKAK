<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category', 'type', 'priority', 'is_read']);
        $perPage = $request->get('per_page', 15);

        $notifications = $this->notificationService->getUserNotifications(
            $request->user(),
            $filters,
            $perPage
        );

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $request->user()->unread_notifications_count,
        ]);
    }

    // FIX: was `UserNotification $notification` — {id} never bound to $notification
    //      so Laravel tried to resolve it from the container → 500
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = UserNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $this->notificationService->markAsRead($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification->fresh(),
        ]);
    }

    // FIX: same route-binding mismatch as markAsRead
    public function markAsUnread(Request $request, int $id): JsonResponse
    {
        $notification = UserNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->update(['read_at' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as unread',
            'data' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'unread_count' => 0,
        ]);
    }

    // FIX: same route-binding mismatch as markAsRead
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = UserNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $this->notificationService->deleteNotification($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unread_notifications_count,
        ]);
    }

    public function getCategories(): JsonResponse
    {
        $categories = [
            'general' => 'General',
            'ride' => 'Rides',
            'chat' => 'Messages',
            'profile' => 'Profile',
            'system' => 'System',
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:mark_read,mark_unread,delete',
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'exists:user_notifications,id',
        ]);

        $notifications = UserNotification::whereIn('id', $request->notification_ids)
            ->where('user_id', auth()->id())
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No notifications found',
            ], 404);
        }

        switch ($request->action) {
            case 'mark_read':
                $notifications->each(fn ($n) => $this->notificationService->markAsRead($n));
                $message = 'Notifications marked as read';
                break;

            case 'mark_unread':
                $notifications->each(fn ($n) => $n->update(['read_at' => null]));
                $message = 'Notifications marked as unread';
                break;

            case 'delete':
                $notifications->each(fn ($n) => $this->notificationService->deleteNotification($n));
                $message = 'Notifications deleted';
                break;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'unread_count' => $request->user()->unread_notifications_count,
        ]);
    }
}
