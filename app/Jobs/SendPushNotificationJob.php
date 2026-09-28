<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PushNotification\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private readonly int $userId,
        private readonly array $payload
    ) {
        $this->onQueue('notifications');   // ← add this
    }

    public function tags(): array          // ← add this method
    {
        return [
            'push-notification',
            'user:'.$this->userId,
        ];
    }

    public function handle(PushNotificationService $pushService): void
    {
        if ($user = User::find($this->userId)) {
            $pushService->sendToUser($user, $this->payload);
        }
    }
}
