<?php

namespace App\Http\Controllers\API;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Models\User;
use App\Services\Chat\ChatMessageHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Chat Controller (REFACTORED)
 *
 * BEFORE: 300+ lines with business logic in controller
 * AFTER: 120 lines - thin controller delegating to services
 *
 * Delegates to:
 * - ChatMessageHandler: Message processing
 * - ChatRepositoryInterface: Data access
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly ChatMessageHandler $messageHandler
    ) {}

    /**
     * Get user's conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $conversations = $this->chatRepository->getUserConversations($request->user());

            return response()->json([
                'success' => true,
                'data' => $conversations->map(function ($conversation) use ($request) {
                    return $this->formatConversation($conversation, $request->user());
                }),
            ]);
        } catch (\Exception $e) {
            // RV-13: the message goes to the LOG, not to the client. A QueryException carries the
            // SQL and the table names; concatenating it into the response hands an attacker the
            // shape of the database. The client gets a stable, actionable sentence instead.
            Log::error('Chat: fetch conversations failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch conversations. Please try again.',
            ], 500);
        }
    }

    /**
     * Start a conversation
     */
    public function startConversation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id|different:'.$request->user()->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentUser = $request->user();
            $otherUser = User::find($request->user_id);

            // Check if conversation already exists
            $existingConversation = $this->chatRepository->findPrivateConversation(
                $currentUser,
                $otherUser
            );

            if ($existingConversation) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'conversation_id' => $existingConversation->id,
                        'message' => 'Conversation already exists',
                    ],
                ]);
            }

            // Create new conversation
            $conversation = $this->chatRepository->createConversation(
                [$currentUser->id, $otherUser->id],
                'private'
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation_id' => $conversation->id,
                    'message' => 'Conversation created successfully',
                ],
            ], 201);
        } catch (\Exception $e) {
            // RV-13: message to the log, not the client (see the note on fetchConversations).
            Log::error('Chat: create conversation failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create the conversation. Please try again.',
            ], 500);
        }
    }

    /**
     * Get messages from a conversation
     */
    public function getMessages(Request $request, int $conversationId)
    {
        try {
            $conversation = $this->chatRepository->findConversation($conversationId);

            if (! $conversation || ! $conversation->isParticipant($request->user())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied',
                ], 404);
            }

            $page = $request->get('page', 1);
            $limit = 50;
            $offset = ($page - 1) * $limit;

            $formattedMessages = $this->messageHandler->getFormattedMessages(
                $conversationId,
                $limit,
                $offset
            );

            return response()->json([
                'success' => true,
                'data' => $formattedMessages,
            ]);
        } catch (\Exception $e) {
            // RV-13: message to the log, not the client.
            Log::error('Chat: fetch messages failed', [
                'user_id' => $request->user()?->id,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages. Please try again.',
            ], 500);
        }
    }

    /**
     * Send a message
     *
     * REFACTORED: Business logic moved to ChatMessageHandler
     */
    public function sendMessage(Request $request, int $conversationId)
    {
        try {
            // Delegate to service
            $message = $this->messageHandler->sendMessage(
                $conversationId,
                $request->user(),
                $request->all()
            );

            // Broadcast event
            broadcast(new MessageSent($message));

            // Format and return
            return response()->json([
                'success' => true,
                'data' => $this->messageHandler->formatMessage($message),
            ], 201);
        } catch (HttpException $e) {
            // KEPT INTENTIONALLY. This is the application's OWN abort() message ("You can only
            // delete your own messages"), written for the client. Sanitising it would remove real,
            // actionable feedback - it is not a leaked internal detail.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            // RV-13: this one IS a broad catch, so its message is whatever went wrong internally.
            Log::error('Chat: send message failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send the message. Please try again.',
            ], 500);
        }
    }

    /**
     * Delete a message
     */
    public function deleteMessage(Request $request, int $messageId)
    {
        try {
            $success = $this->messageHandler->deleteMessage(
                $messageId,
                $request->user()
            );

            if (! $success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Message not found or permission denied',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Message deleted successfully',
            ]);
        } catch (\Exception $e) {
            // RV-13: message to the log, not the client.
            Log::error('Chat: delete message failed', [
                'user_id' => $request->user()?->id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete the message. Please try again.',
            ], 500);
        }
    }

    /**
     * Format conversation for API response
     */
    private function formatConversation($conversation, $currentUser): array
    {
        // Zero DB queries — uses the already eager-loaded participants collection
        $otherParticipant = $conversation->participants
            ->firstWhere('id', '!=', $currentUser->id);

        // Zero DB queries — uses the already eager-loaded latestMessage relation
        $lastMessage = $conversation->latestMessage;

        // Zero DB queries — profile already loaded via participants.profile
        // Storage::exists() removed — return URL directly, let client handle missing image
        $profilePhoto = $otherParticipant?->profile?->profile_photo
            ? asset('storage/'.$otherParticipant->profile->profile_photo)
            : null;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->title,
            'other_participant' => $otherParticipant ? [
                'id' => $otherParticipant->id,
                'name' => $otherParticipant->first_name.' '.$otherParticipant->last_name,
                'profile_photo' => $profilePhoto,
            ] : null,
            'last_message' => $lastMessage ? [
                'content' => $lastMessage->type === 'image'
                    ? asset('storage/'.$lastMessage->content)
                    : $lastMessage->content,
                'sender_name' => $lastMessage->sender->first_name, // already eager-loaded
                'created_at' => $lastMessage->created_at->diffForHumans(),
            ] : null,
            'updated_at' => $conversation->updated_at->toIso8601String(),
        ];
    }
}
