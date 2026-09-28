<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class TestNotificationCommand extends Command
{
    protected $signature = 'notification:test {user_id?} {--type=welcome}';

    protected $description = 'Send a test notification to a user';

    public function handle(NotificationService $notificationService)
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

            $this->info("Test notification sent successfully! ID: {$notification->id}");

            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to send test notification: '.$e->getMessage());

            return 1;
        }
    }
}
