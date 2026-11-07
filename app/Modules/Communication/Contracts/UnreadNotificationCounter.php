<?php

namespace App\Modules\Communication\Contracts;

/**
 * Stage 2 C1: the kernel must not name Communication's models, but `User::unread_notifications_count`
 * is an API response field (`NotificationController` emits it as `unread_count`). This contract is how
 * the authenticated principal keeps that number without owning a relationship to a Communication model.
 *
 * Deliberately a single integer, not a query builder: a caller that received a builder would be back to
 * reaching into the module's tables.
 */
interface UnreadNotificationCounter
{
    /** How many of this user's notifications are still unread. */
    public function forUser(int $userId): int;
}
