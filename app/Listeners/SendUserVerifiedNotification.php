<?php

namespace App\Listeners;

use App\Events\UserVerified;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires an in-app notification when staff APPROVE a verification.
 *
 * AF-4 (app-future audit): the class existed with an empty handle() while
 * StaffAdminController::rejectVerification() notified the user inline — so
 * approval left the user in the dark while rejection told them. This closes
 * that asymmetry using the app's real notification path (createNotification
 * writes the row AND queues push), the same one every other flow uses.
 *
 * The event ALSO broadcasts (UserVerified implements ShouldBroadcast), so a
 * connected real-time client sees it instantly; this listener covers the
 * offline case — the in-app notification inbox.
 *
 * Queued: notification fan-out must not block the staff request.
 */
class SendUserVerifiedNotification implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(UserVerified $event): void
    {
        $isDriver = $event->verificationType === 'driver';

        try {
            $this->notifications->createNotification(
                $event->user,
                'verification_approved',
                $isDriver ? 'تم توثيق حسابك كسائق' : 'تم توثيق حسابك',
                $isDriver
                    ? 'تمت الموافقة على وثائقك، يمكنك الآن إنشاء الرحلات.'
                    : 'تمت الموافقة على وثائقك، يمكنك الآن حجز الرحلات.',
                ['user_id' => $event->user->id, 'verification_type' => $event->verificationType],
                'high',
                'system'
            );
        } catch (Throwable $e) {
            // Non-fatal: verification succeeded; only the courtesy notice failed.
            Log::warning('verification-approved notification failed: '.$e->getMessage());
        }
    }
}
