<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Contracts\MessageBroadcaster;
use App\Modules\Communication\Events\MessageSent;

/**
 * Stage 2 C1: owns the one `broadcast(new MessageSent(...))` call that callers outside this module need.
 * Same event, same payload, same queue as when Console constructed it themselves.
 */
final class BroadcastingMessageBroadcaster implements MessageBroadcaster
{
    public function broadcastMessageSent(mixed $message): void
    {
        broadcast(new MessageSent($message));
    }
}
