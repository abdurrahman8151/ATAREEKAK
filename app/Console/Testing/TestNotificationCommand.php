<?php

namespace App\Console\Testing;

use App\Console\Commands\GuardsAgainstProductionExecution;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use Illuminate\Console\Command;

class TestNotificationCommand extends Command
{
    use GuardsAgainstProductionExecution; // AF-4

    protected $signature = 'notification:test {user_id?} {--type=welcome}';

    /** AF-3: hidden from artisan list; still runnable by name (developer harness, refuses production). */
    protected $hidden = true;

    protected $description = 'Send a test notification to a user';

    public function handle(NotificationPort $notificationService)
    {
        $userId = $this->argument('user_id') ?? User::first()?->id;
        $type = $this->option('type');

        if (! $userId) {
            $this->error('No user found to send notification to');

            return 1;
        }

        $user = User::find($userId);

        if (! $user) {
            $this->error("User with ID {$userId} not found");

            return 1;
        }

        try {
            $notification = $this->sendTypedNotification($notificationService, $user, $userId, $type);

            $this->info("Test notification sent successfully! ID: {$notification->id}");

            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to send test notification: '.$e->getMessage());

            return 1;
        }
    }

    /**
     * Dispatch the notification variant picked by the --type option.
     */
    private function sendTypedNotification(NotificationPort $notificationService, User $user, int $userId, $type)
    {
        // ✅ FIXED: createWelcomeNotification() and createSystemNotification()
        // do not exist on NotificationService. Use createNotification() directly.
        switch ($type) {
            case 'welcome':
                $notification = $notificationService->createNotification(
                    $user,
                    'welcome',
                    'Welcome to SyRide!',
                    'Thank you for joining SyRide. Start exploring rides today!',
                    ['user_id' => $userId],
                    'normal',
                    'general'
                );
                break;

            case 'system':
                $notification = $notificationService->createNotification(
                    $user,
                    'system',
                    'Test System Notification',
                    'This is a test system notification sent from the command line.',
                    ['user_id' => $userId],
                    'high',
                    'system'
                );
                break;

            default:
                $notification = $notificationService->createNotification(
                    $user,
                    'test',
                    'Test Notification',
                    'This is a test notification!',
                    ['user_id' => $userId],
                    'normal',
                    'general'
                );
        }

        return $notification;
    }
}
