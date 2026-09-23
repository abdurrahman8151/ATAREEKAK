<?php

namespace App\Interfaces;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ChatRepositoryInterface
{
    /**
     * @param array        $participants  flat array of user IDs
     * @param string       $type          'private' | 'support' | 'group'
     * @param string|null  $title         for group chats
     * @param array        $roles         [userId => role]  — omitted keys default to 'member'
     */
    public function createConversation(
        array   $participants,
        string  $type  = 'private',
        ?string $title = null,
        array   $roles = [],
    ): Conversation;

    public function findConversation(int $conversationId): ?Conversation;

    public function findPrivateConversation(User $user1, User $user2): ?Conversation;

    /** Find an existing support conversation between a customer and an agent. */
    public function findSupportConversation(User $user, User $agent): ?Conversation;

    /** Find the customer's existing support conversation, whichever agent is on it. */
    public function findSupportConversationForUser(User $user): ?Conversation;

    public function getUserConversations(User $user): Collection;

    /** All support conversations, regardless of which agent is assigned — the staff shared inbox. */
    public function getAllSupportConversations(): Collection;

    public function sendMessage(
        int     $conversationId,
        int     $senderId,
        string  $content,
        string  $type     = 'text',
        ?array  $metadata = null,
    ): Message;

    public function getMessages(int $conversationId, int $limit = 50, int $offset = 0): Collection;

    public function markMessageAsRead(int $messageId, int $userId): bool;

    public function deleteMessage(int $messageId, int $userId): bool;
}
