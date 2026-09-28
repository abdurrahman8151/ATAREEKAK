<?php

namespace App\Http\Controllers\API\Staff;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Models\Profile;
use App\Models\User;
use App\Services\Chat\ChatMessageHandler;
use App\Services\Staff\EmployeeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * StaffChatController
 *
 * Lets staff members (support agents, admins, system_admin) read and reply
 * to user-initiated support conversations from the staff dashboard.
 *
 * Architecture note — shadow Users
 * ─────────────────────────────────
 * The chat system stores participants as User records (user_id FK).
 * Staff are stored as Employee records.  The bridge is Employee::email → User::email.
 *
 * resolveUserAccount() calls EmployeeManagementService::ensureShadowUser()
 * which creates the User transparently if it does not exist.
 *
 * Routes (all behind `staff` middleware — any active role):
 *   GET  /api/staff/chat/conversations               → conversations()
 *   GET  /api/staff/chat/conversations/{id}/messages → messages()
 *   POST /api/staff/chat/conversations/{id}/messages → sendMessage()
 */
final class StaffChatController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepo,
        private readonly ChatMessageHandler $messageHandler,
        private readonly EmployeeManagementService $managementService,
    ) {}

    // =========================================================================
    // LIST CONVERSATIONS
    // =========================================================================

    /**
     * GET /api/staff/chat/conversations
     *
     * Returns conversations the staff member participates in, newest first.
     * The shadow User is created silently on first access if it does not exist.
     */
    public function conversations(Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (! $agentUser) {
            return $this->noEmail($request);
        }

        try {
            $conversations = $this->chatRepo->getUserConversations($agentUser);

            return response()->json([
                'status' => 'success',
                'total' => $conversations->count(),
                'data' => $conversations
                    ->map(fn ($c) => $this->formatConversation($c, $agentUser))
                    ->values(),
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // =========================================================================
    // GET MESSAGES IN A CONVERSATION
    // =========================================================================

    /**
     * GET /api/staff/chat/conversations/{id}/messages
     *
     * Returns paginated messages. The staff member must be a participant.
     *
     * Query params:
     *   page  = int   (default 1)
     *   limit = 1-100 (default 50)
     */
    public function messages(int $conversationId, Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (! $agentUser) {
            return $this->noEmail($request);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $conversation = $this->chatRepo->findConversation($conversationId);

            if (! $conversation || ! $conversation->isParticipant($agentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Conversation not found or access denied.',
                ], 404);
            }

            $limit = (int) $request->get('limit', 50);
            $page = (int) $request->get('page', 1);
            $offset = ($page - 1) * $limit;

            $messages = $this->messageHandler->getFormattedMessages(
                $conversationId,
                $limit,
                $offset
            );

            return response()->json([
                'status' => 'success',
                'conversation' => $this->formatConversation($conversation, $agentUser),
                'data' => $messages,
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // =========================================================================
    // SEND A MESSAGE
    // =========================================================================

    /**
     * POST /api/staff/chat/conversations/{id}/messages
     *
     * Sends a reply as the agent's shadow User account.
     * Broadcasts MessageSent so the user's app receives it in real-time.
     *
     * Body:
     *   message  string  required, max 5000
     */
    public function sendMessage(int $conversationId, Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (! $agentUser) {
            return $this->noEmail($request);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
        ], [
            'message.required' => 'Message content is required.',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $conversation = $this->chatRepo->findConversation($conversationId);

            if (! $conversation || ! $conversation->isParticipant($agentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Conversation not found or access denied.',
                ], 404);
            }

            // The ChatMessageHandler expects 'content' but the staff API
            // accepts 'message' (more natural for dashboard consumers).
            // Remap here so the handler receives the field it validates against.
            $data = $request->all();
            $data['content'] = $data['message'] ?? null;

            $message = $this->messageHandler->sendMessage(
                $conversationId,
                $agentUser,
                $data
            );

            // Real-time delivery to the user's app via WebSocket
            broadcast(new MessageSent($message));

            return response()->json([
                'status' => 'success',
                'message' => 'Message sent.',
                'data' => $this->messageHandler->formatMessage($message),
            ], 201);
        } catch (HttpException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Resolve (or silently create) the shadow User for this employee.
     *
     * Returns null only when the Employee has no email set.
     */
    private function resolveUserAccount(Request $request): ?User
    {
        $employee = $request->attributes->get('staffEmployee');

        if (! $employee->email) {
            return null;
        }

        return $this->managementService->ensureShadowUser($employee);
    }

    /**
     * Format a conversation for the staff inbox response.
     *
     * The "user" block is the OTHER participant — the customer, not the agent.
     * "sent_by_agent" on the last_message block lets the UI show the correct
     * alignment (right = agent, left = user).
     */
    private function formatConversation($conversation, User $agentUser): array
    {
        // Zero DB queries — uses the already eager-loaded participants collection
        $otherUser = $conversation->participants
            ->firstWhere('id', '!=', $agentUser->id);

        // Zero DB queries — uses the already eager-loaded latestMessage relation
        $lastMessage = $conversation->latestMessage;

        // Zero DB queries — profile already loaded via participants.profile
        $profilePhoto = $otherUser?->profile?->profile_photo
            ? asset('storage/'.$otherUser->profile->profile_photo)
            : null;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,

            'user' => $otherUser ? [
                'id' => $otherUser->id,
                'name' => trim("{$otherUser->first_name} {$otherUser->last_name}"),
                'email' => $otherUser->email,
                'profile_photo' => $profilePhoto,
                'account_status' => match ((int) $otherUser->status) {
                    -1 => 'banned',
                    0 => 'inactive',
                    default => 'active',
                },
            ] : null,

            'last_message' => $lastMessage ? [
                'content' => $lastMessage->type === 'image'
                    ? asset('storage/'.$lastMessage->content)
                    : $lastMessage->content,
                'sender_name' => $lastMessage->sender?->first_name, // already eager-loaded
                'sent_by_agent' => $lastMessage->sender?->id === $agentUser->id,
                'created_at' => $lastMessage->created_at->diffForHumans(),
                'created_at_iso' => $lastMessage->created_at->toIso8601String(),
            ] : null,

            'updated_at' => $conversation->updated_at->toIso8601String(),
        ];
    }

    /**
     * Returned only when the Employee has no email at all.
     */
    private function noEmail(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('staffEmployee');

        return response()->json([
            'status' => 'error',
            'message' => "Staff account '{$employee->username}' has no email address. ".
                'Add an email to this account to enable chat access.',
        ], 422);
    }

    private function serverError(\Exception $e): JsonResponse
    {
        Log::error('StaffChatController error: '.$e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
