<?php

namespace App\Modules\Communication\Contracts;

use App\Models\User;
use App\Modules\Communication\Models\UserNotification;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Cross-context port over the notification store (batch 233).
 *
 * RIDE, MONEY, IDENTITY and CONSOLE compose notifications; COMM owns the storage,
 * the broadcast and the push fan-out. Before this port each of those contexts named
 * `App\Modules\Communication\Services\NotificationService` directly, which is what the cross-context ratchet
 * counted. The surface below is the service's public API, unchanged - the port exists
 * to invert the direction of the dependency, not to reshape it.
 *
 * Implemented by `App\Modules\Communication\Services\NotificationService` and bound to that same singleton in
 * `App\Support\ContainerBindings::registerBoundaryPorts()`, so resolution, construction
 * order and instance identity are exactly what they were before the port existed.
 */
interface NotificationPort
{
    public function getUserNotifications(
        User $user,
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator;

    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $priority = 'normal',
        string $category = 'general'
    ): UserNotification;

    public function markAsRead(UserNotification $notification): void;

    public function markAllAsRead(User $user): void;

    public function deleteNotification(UserNotification $notification): void;

    public function findForUser(int $userId, int $notificationId): ?UserNotification;

    public function findManyForUser(int $userId, array $notificationIds);

    public function markAsUnread(UserNotification $notification): void;
}
