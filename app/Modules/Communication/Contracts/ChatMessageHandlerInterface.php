<?php

namespace App\Modules\Communication\Contracts;

use App\Models\User;
use App\Modules\Communication\Models\Message;

/**
 * Port over the chat message pipeline (batch 233).
 *
 * CONSOLE's staff chat console reads, formats and sends messages through the same pipeline the
 * passenger chat uses; COMM owns that pipeline. Before this port `StaffChatController` type-hinted
 * `App\Modules\Communication\Services\ChatMessageHandler` directly.
 */
interface ChatMessageHandlerInterface
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Exception when validation fails or the conversation is not visible to the sender
     */
    public function sendMessage(int $conversationId, User $sender, array $data): Message;

    /** @return array<int, array<string, mixed>> */
    public function getFormattedMessages(int $conversationId, int $limit = 50, int $offset = 0): array;

    /** @return array<string, mixed> */
    public function formatMessage(Message $message): array;
}
