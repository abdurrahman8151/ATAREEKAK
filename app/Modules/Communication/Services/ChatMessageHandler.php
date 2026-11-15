<?php

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Communication\Contracts\ChatMessageHandlerInterface;
use App\Modules\Communication\Contracts\ChatRepositoryInterface;
use App\Modules\Communication\Contracts\ProfilePhotoDirectory;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\Message;
use App\Services\File\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Chat Message Handler
 *
 * EXTRACTED FROM: ChatController::sendMessage() (100+ lines)
 *
 * Single Responsibility: Process and send chat messages
 *
 * Handles:
 * - Message validation
 * - Type-specific processing
 * - File uploads (images)
 * - Message creation
 *
 * OPTIMIZATIONS APPLIED:
 * - Conversation cached in Redis (saves ~50ms per message)
 * - sender eager loaded in one query; the sender's photo is resolved in ONE batched port query
 *   for a whole page, or read off the already-loaded profile relation on the single-message path
 * - getUserProfilePhoto no longer falls back to the Profile MODEL: the fallback is Identity's
 *   ProfilePhotoDirectory (stage 2 C2), one query either way
 */
final class ChatMessageHandler implements ChatMessageHandlerInterface
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageTypeFactory $messageTypeFactory,
        private readonly FileUploadService $fileUploadService,
        private readonly ProfilePhotoDirectory $profilePhotos,
    ) {}

    /**
     * Process and send a message
     *
     * @throws \Exception if validation fails or conversation not found
     */
    public function sendMessage(
        int $conversationId,
        User $sender,
        array $data
    ): Message {
        // OPTIMIZED: Cache conversation — structure never changes once created.
        // Saves ~50ms per message by avoiding a repeated DB lookup.
        $conversation = Cache::remember(
            "conversation.{$conversationId}",
            300, // 5 minutes
            fn () => $this->chatRepository->findConversation($conversationId)
        );

        if (! $conversation || ! $conversation->isParticipant($sender)) {
            throw new \Exception('Conversation not found or access denied');
        }

        // Get message type
        $messageType = $data['type'] ?? 'text';

        // Validate message type
        if (! in_array($messageType, $this->messageTypeFactory->getAvailableTypes())) {
            throw new \Exception('Invalid message type');
        }

        // Get handler for message type
        $handler = $this->messageTypeFactory->create($messageType);

        if (! $handler->validate($data)) {
            throw new \Exception('Invalid message data');
        }

        // Process message based on type
        if ($messageType === 'image' && isset($data['image'])) {
            $message = $this->sendImageMessage($conversation, $sender, $data);
        } else {
            // Process other message types
            $processed = $handler->process($data);

            $message = $this->chatRepository->sendMessage(
                $conversationId,
                $sender->id,
                $processed['content'],
                $messageType,
                $processed['metadata']
            );
        }

        // OPTIMIZED: Eager load sender + profile in ONE query right after save.
        // Without this, formatMessage() lazy-loads sender and profile separately
        // — 2 extra DB queries per message (~100ms wasted).
        // Kept UNCHANGED in stage 2 C2: the broadcast payload carries this relation, and the single-message
        // path reads the loaded profile with zero extra queries.
        $message->setRelation('sender', $sender->loadMissing('profile'));

        return $message;
    }

    /**
     * Send image message
     */
    private function sendImageMessage(
        Conversation $conversation,
        User $sender,
        array $data
    ): Message {
        if (! isset($data['image']) || ! ($data['image'] instanceof UploadedFile)) {
            throw new \Exception('Invalid image file');
        }

        // Get receiver ID
        $receiverId = $conversation->participants()
            ->where('user_id', '!=', $sender->id)
            ->value('user_id');

        // Upload image using FileUploadService
        $uploadResult = $this->fileUploadService->uploadChatImage(
            $data['image'],
            $sender->id,
            $receiverId
        );

        // Create message with image path
        return $this->chatRepository->sendMessage(
            $conversation->id,
            $sender->id,
            $uploadResult['path'],
            'image',
            array_merge($uploadResult['metadata'], [
                'caption' => $data['caption'] ?? '',
            ])
        );
    }

    /**
     * Get messages with formatted data
     *
     * Stage 2 C2: the senders' photos are resolved with ONE Identity query for the whole page (the
     * `sender.profile` eager load this replaces was also exactly one query), and the resolved map is handed
     * to `formatMessage` so no row can fall back to a per-message lookup.
     */
    public function getFormattedMessages(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        $messages = $this->chatRepository->getMessages($conversationId, $limit, $offset);

        $photoPaths = $this->profilePhotos->photoPathsFor(
            $messages->pluck('sender_id')->unique()->values()->all()
        );

        return $messages->map(function ($message) use ($photoPaths) {
            return $this->formatMessage($message, $photoPaths);
        })->toArray();
    }

    /**
     * Format message for API response
     *
     * @param  array<int, string|null>|null  $photoPaths  the batch resolved by `getFormattedMessages`, keyed
     *                                                    by user id. Null (the single-message path) means
     *                                                    "resolve this one sender now".
     */
    public function formatMessage(Message $message, ?array $photoPaths = null): array
    {
        $storedPhoto = $photoPaths !== null
            ? ($photoPaths[$message->sender_id] ?? null)
            : $this->resolvePhotoPath($message->sender);

        return [
            'id' => $message->id,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->first_name.' '.$message->sender->last_name,
                'profile_photo' => $this->photoUrl($storedPhoto),
            ],
            'type' => $message->type,
            'content' => $message->type === 'image'
                ? $this->fileUploadService->url($message->content)
                : $message->content,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at->toIso8601String(),
            'is_edited' => $message->is_edited,
        ];
    }

    /**
     * The STORED path for one sender's photo.
     *
     * The fast path is the already-loaded `profile` relation (the send path loads it once, and the
     * authenticated user arrives from the auth cache with it attached), so it costs no query. Otherwise the
     * fallback is Identity's port - one query, the same one the removed `Profile::where(...)->first()`
     * fallback cost.
     */
    private function resolvePhotoPath(User $user): ?string
    {
        if ($user->relationLoaded('profile')) {
            return $user->profile?->profile_photo;
        }

        return $this->profilePhotos->photoPathsFor([$user->id])[$user->id] ?? null;
    }

    /**
     * The public URL for a stored path, or null when there is nothing to link to.
     *
     * Unchanged from the previous implementation: an empty path and a file the upload service cannot see
     * both answer null, and the URL formatting stays here, not in Identity.
     */
    private function photoUrl(?string $storedPhoto): ?string
    {
        if (! $storedPhoto) {
            return null;
        }

        if (! $this->fileUploadService->exists($storedPhoto)) {
            return null;
        }

        return $this->fileUploadService->url($storedPhoto);
    }

    /**
     * Delete message
     */
    public function deleteMessage(int $messageId, User $user): bool
    {
        return $this->chatRepository->deleteMessage($messageId, $user->id);
    }

    /**
     * Resolve the user a private conversation is being opened with.
     *
     * Moved here from ChatController (batch 227) so the controller holds no Eloquent
     * reference. The controller still runs the `exists:users,id` rule first, exactly as
     * before, so a missing row behaves the same as it did.
     */
    public function conversationTarget(int $userId): ?User
    {
        return User::whereKey($userId)->first();
    }
}
