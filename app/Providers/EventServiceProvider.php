<?php

namespace App\Providers;

use App\Events\UserVerified;
use App\Listeners\SendUserVerifiedNotification;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * AF-4 (app-future audit): the listener map was pruned from 4 entries to
     * the ONE event chain that is genuinely wired end-to-end.
     *
     * Removed with their (empty-body) listeners:
     *   RideBooked / RideCancelled  — both events are still BROADCAST (real-time
     *     clients rely on them), but the app notifies the parties inline via
     *     NotificationService::createNotification() (BookingService/RideService),
     *     so an empty queued listener here was dead scaffolding.
     *   MessageReceived             — never dispatched anywhere; the real chat
     *     path broadcasts MessageSent. Event + listener + notification + job
     *     deleted.
     *
     * OtpSent and ConversationCreated events were deleted: zero dispatchers,
     * constructors that could not even carry a payload.
     *
     * UserVerified STAYS and is now actually functional: it is dispatched at
     * StaffAdminController::approveVerification(), and its listener previously
     * had an empty handle() — so an APPROVED user got no in-app notification
     * while a REJECTED one did (reject notifies inline at :218). The listener
     * body is filled below to close that asymmetry.
     */
    protected $listen = [
        UserVerified::class => [
            SendUserVerifiedNotification::class,
        ],
    ];

    public function boot(): void
    {
        User::observe(UserObserver::class);
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
