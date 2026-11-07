<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Contracts\UnreadNotificationCounter;
use App\Modules\Communication\Models\Notification;

/**
 * Stage 2 C1: counts through Communication's own model, which is why the query lives here rather than on
 * `App\Models\User`.
 *
 * The SQL reproduces exactly what `User::unread_notifications_count` ran before the move - the
 * `belongsToMany(Notification::class, 'user_notifications')` join with `whereNull('read_at')` - so the
 * `unread_count` the API emits is unchanged. Reproduced as an explicit join rather than as
 * `wherePivot()` because the pivot table, not the model, carries `read_at`.
 */
final class UnreadNotificationCountReader implements UnreadNotificationCounter
{
    public function forUser(int $userId): int
    {
        return Notification::query()
            ->join('user_notifications', 'user_notifications.notification_id', '=', 'notifications.id')
            ->where('user_notifications.user_id', $userId)
            ->whereNull('user_notifications.read_at')
            ->count();
    }
}
