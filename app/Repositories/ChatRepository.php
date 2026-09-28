<?php

namespace App\Repositories;

use App\Interfaces\ChatRepositoryInterface;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatRepository implements ChatRepositoryInterface
{
    public function createConversation(
        array $participants,
        string $type = 'private',
        ?string $title = null,
        array $roles = [],
    ): Conversation {
        return DB::transaction(function () use ($participants, $type, $title, $roles) {
            $conversation = Conversation::create([
                'type' => $type,
                'title' => $title,
            ]);

            foreach ($participants as $userId) {
                $conversation->participants()->attach($userId, [
                    'role' => $roles[$userId] ?? 'member',
                    'joined_at' => now(),
                ]);
            }

            Log::info('Conversation created', [
                'conversation_id' => $conversation->id,
                'type' => $type,
            ]);

            return $conversation->load('participants');
        });
    }

    /**
     * Find a conversation with participants only.
     *
     * OPTIMIZED: Previously loaded ['participants', 'messages.sender'] —
     * meaning ALL messages + ALL their senders were fetched and stored in Redis
     * on every cache miss. A conversation with 100 messages was loading 100+
     * records just to check if someone is a participant.
     *
     * Now loads only participants — the only relationship needed for
     * isParticipant() checks. The cached object is tiny and fast to
     * serialize/deserialize from Redis.
     */
    public function findConversation(int $conversationId): ?Conversation
    {
        return Conversation::with('participants')->find($conversationId);
    }

    public function findPrivateConversation(User $user1, User $user2): ?Conversation
    {
        return Conversation::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user1->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user2->id))
            ->first();
    }

    public function findSupportConversation(User $user, User $agent): ?Conversation
    {
        return Conversation::where('type', 'support')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $agent->id))
            ->first();
    }

    public function getUserConversations(User $user): Collection
    {
        return Conversation::whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'participants.profile',
                'latestMessage.sender',
            ])
            ->latest('updated_at')
            ->get();
    }

    /**
     * Save a message and return it.
     *
     * OPTIMIZED: Previously called $message->load('sender', 'conversation')
     * after every save — 2 extra DB queries. ChatMessageHandler immediately
     * overwrites sender via setRelation() anyway, making those queries wasted.
     * Conversation is not needed in the formatted response at all.
     *
     * Returning the bare message lets the handler attach the already-in-memory
     * sender object (with profile) without touching the DB.
     */
    public function sendMessage(
        int $conversationId,
        int $senderId,
        string $content,
        string $type = 'text',
        ?array $metadata = null,
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'type' => $type,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        Conversation::where('id', $conversationId)->touch();

        Log::info('Message sent', ['message_id' => $message->id]);

        return $message;
    }

    public function getMessages(int $conversationId, int $limit = 50, int $offset = 0): Collection
    {
        return Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->reverse()
            ->values();
    }

    public function markMessageAsRead(int $messageId, int $userId): bool
    {
        $message = Message::find($messageId);
        if (! $message || $message->sender_id === $userId) {
            return false;
        }

        $message->markAsRead();

        return true;
    }

    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = Message::find($messageId);
        if (! $message || $message->sender_id !== $userId) {
            return false;
        }

        return $message->delete();
    }
}
