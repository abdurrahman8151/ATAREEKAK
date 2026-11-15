<?php

namespace App\Modules\Communication\Repositories;

use App\Models\User;
use App\Modules\Communication\Contracts\ChatRepositoryInterface;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\Message;
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
        // Stage 2 C2: `participants.profile` is GONE. `profiles` is Identity's, and the only reader of a
        // participant's photo - the staff messages screen's conversation block - now resolves it from
        // Identity's batched port. This method's callers only need `isParticipant()`.
        return Conversation::with('participants', 'latestMessage.sender')->find($conversationId);
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
                // Stage 2 C2: the participants' photos come from Identity's port in ONE batched read by
                // the two list controllers, not from a `participants.profile` eager load here.
                'participants',
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
            // Stage 0.6 item 1: `sender` is batched for the page.
            // Stage 2 C2: `sender.profile` is GONE - the photo now arrives from Identity's
            // ProfilePhotoDirectory as ONE batched query in ChatMessageHandler::getFormattedMessages(),
            // which is the same query the eager load cost. `sender` must stay eager-loaded: `Message`
            // arms the lazy-loading guard, so a lazy `->sender` is a hard error, and that is exactly the
            // 500 the previous attempt produced.
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
