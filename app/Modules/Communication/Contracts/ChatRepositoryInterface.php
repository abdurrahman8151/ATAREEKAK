<?php

namespace App\Modules\Communication\Contracts;

use App\Models\User;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\Message;
use Illuminate\Database\Eloquent\Collection;

interface ChatRepositoryInterface
{
    /**
     * @param  array  $participants  flat array of user IDs
     * @param  string  $type  'private' | 'support' | 'group'
     * @param  string|null  $title  for group chats
     * @param  array  $roles  [userId => role]  — omitted keys default to 'member'
     */
    public function createConversation(
        array $participants,
        string $type = 'private',
        ?string $title = null,
        array $roles = [],
    ): Conversation;

    public function findConversation(int $conversationId): ?Conversation;

    /**
     * Find the existing private conversation between two users.
     *
     * Stage 2 C3: takes the two IDs, not two `User` rows - the caller only ever needed the ids, and fetching
     * the other user's row to read `->id` was another user's data read for nothing.
     */
    public function findPrivateConversation(int $user1Id, int $user2Id): ?Conversation;

    /** Find an existing support conversation between a customer and an agent. */
    public function findSupportConversation(User $user, User $agent): ?Conversation;

    public function getUserConversations(User $user): Collection;

    public function sendMessage(
        int $conversationId,
        int $senderId,
        string $content,
        string $type = 'text',
        ?array $metadata = null,
    ): Message;

    public function getMessages(int $conversationId, int $limit = 50, int $offset = 0): Collection;

    public function markMessageAsRead(int $messageId, int $userId): bool;

    public function deleteMessage(int $messageId, int $userId): bool;
}
