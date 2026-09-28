<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    // constructor removed entirely — no dependencies left

    public function getUserNotifications(
        User $user,
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = UserNotification::with('notification')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['category'])) {
            $query->whereHas('notification', fn ($q) => $q->where('type', $filters['category'])
            );
        }

        if (isset($filters['is_read'])) {
            $filters['is_read']
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $priority = 'normal',
        string $category = 'general'
    ): UserNotification {

        $notification = Notification::create([
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => $data,
            'sent_at' => now(),
        ]);

        $userNotification = UserNotification::create([
            'user_id' => $user->id,
            'notification_id' => $notification->id,
        ]);

        SendPushNotificationJob::dispatch($user->id, [
            'title' => $title,
            'body' => $message,
            'data' => array_merge($data, [
                'notification_id' => $notification->id,
                'type' => $type,
                'category' => $category,
                'priority' => $priority,
            ]),
        ]);

        try {
            broadcast(new NotificationSent($user, $notification));
        } catch (\Throwable $e) {
            Log::warning('broadcast NotificationSent after persist failed (non-fatal): '.$e->getMessage());
        }

        return $userNotification->load('notification');
    }

    public function markAsRead(UserNotification $notification): void
    {
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllAsRead(User $user): void
    {
        UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function deleteNotification(UserNotification $notification): void
    {
        $notification->delete();
    }
}
