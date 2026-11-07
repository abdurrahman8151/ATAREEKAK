<?php

namespace App\Modules\Communication\Contracts;

/**
 * Stage 2 C1: the real-time `MessageSent` event now belongs to Communication (it names a Communication
 * model), which means a Console controller can no longer construct it directly. This port is the door:
 * the caller hands over the message it already has and does not learn the event's class or payload.
 *
 * The parameter is deliberately untyped: naming the model type here would recreate the edge this port
 * removes, and the caller only ever passes what `ChatMessageHandlerInterface::sendMessage()` returned.
 */
interface MessageBroadcaster
{
    /** Broadcast a just-sent chat message to the conversation's channel subscribers. */
    public function broadcastMessageSent(mixed $message): void;
}
