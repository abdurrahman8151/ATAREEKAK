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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

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
        private readonly ChatRepositoryInterface   $chatRepo,
        private readonly ChatMessageHandler        $messageHandler,
        private readonly EmployeeManagementService $managementService,
    ) {}

    // =========================================================================
    // LIST CONVERSATIONS
    // =========================================================================

    /**
     * GET /api/staff/chat/conversations
     *
     * Shared inbox — returns EVERY support conversation, newest first, not
     * just the ones the logged-in staff member happens to be the assigned
     * agent on. Support conversations are assigned to a single least-loaded
     * agent at creation time, but any active staff role (agent, admin,
     * system_admin) can read and reply to any of them from the dashboard.
     * The shadow User is created silently on first access if it does not exist.
     */
    public function conversations(Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (!$agentUser) {
            return $this->noEmail($request);
        }

        try {
            $conversations = $this->chatRepo->getAllSupportConversations();

            return response()->json([
                'status' => 'success',
                'total'  => $conversations->count(),
                'data'   => $conversations
                    ->map(fn ($c) => $this->formatConversation($c, $agentUser))
                    ->values(),
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // =========================================================================
    // START OR FIND CONVERSATION WITH USER
    // =========================================================================

    /**
     * POST /api/staff/chat/conversations
     *
     * Finds or creates a support conversation between the staff member and a target user.
     *
     * Body:
     *   user_id  int  required, exists:users,id
     */
    public function startConversation(Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (!$agentUser) {
            return $this->noEmail($request);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $targetUser = User::find($request->user_id);

            if (!$targetUser) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if ($targetUser->id === $agentUser->id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot start a chat with your own account.',
                ], 422);
            }

            // Find if a support conversation already exists for this customer
            $existing = $this->chatRepo->findSupportConversationForUser($targetUser);

            if ($existing) {
                // Ensure current agent is attached if not already
                if (!$existing->isParticipant($agentUser)) {
                    $existing->participants()->attach($agentUser->id, [
                        'role'      => 'agent',
                        'joined_at' => now(),
                    ]);
                    $existing->load('participants');
                    Cache::forget("conversation.{$existing->id}");
                }

                return response()->json([
                    'status'          => 'success',
                    'conversation_id' => $existing->id,
                    'is_new'          => false,
                    'conversation'    => $this->formatConversation($existing, $agentUser),
                ]);
            }

            // Create new support conversation
            $conversation = $this->chatRepo->createConversation(
                participants: [$targetUser->id, $agentUser->id],
                type:         'support',
                title:        null,
                roles:        [
                    $targetUser->id => 'customer',
                    $agentUser->id  => 'agent',
                ],
            );

            return response()->json([
                'status'          => 'success',
                'conversation_id' => $conversation->id,
                'is_new'          => true,
                'conversation'    => $this->formatConversation($conversation, $agentUser),
            ], 201);
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
     * Returns paginated messages. Any active staff member may view any
     * support conversation — shared inbox, not participant-gated.
     *
     * Query params:
     *   page  = int   (default 1)
     *   limit = 1-100 (default 50)
     */
    public function messages(int $conversationId, Request $request): JsonResponse
    {
        $agentUser = $this->resolveUserAccount($request);

        if (!$agentUser) {
            return $this->noEmail($request);
        }

        $validator = Validator::make($request->all(), [
            'page'  => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $conversation = $this->chatRepo->findConversation($conversationId);

            if (!$conversation || $conversation->type !== 'support') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Conversation not found or access denied.',
                ], 404);
            }

            $limit  = (int) $request->get('limit', 50);
            $page   = (int) $request->get('page', 1);
            $offset = ($page - 1) * $limit;

            $messages = $this->messageHandler->getFormattedMessages(
                $conversationId,
                $limit,
                $offset
            );

            return response()->json([
                'status'       => 'success',
                'conversation' => $this->formatConversation($conversation, $agentUser),
                'data'         => $messages,
                'meta'         => [
                    'page'  => $page,
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

        if (!$agentUser) {
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

            if (!$conversation || $conversation->type !== 'support') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Conversation not found or access denied.',
                ], 404);
            }

            // Shared inbox: any staff member may reply, not just the agent the
            // conversation was originally assigned to. Join them as a participant
            // on first reply so isParticipant() checks (message send, broadcast
            // channel auth) recognize them, then drop the 5-minute conversation
            // cache ChatMessageHandler reads so it sees the new participant.
            if (!$conversation->isParticipant($agentUser)) {
                $conversation->participants()->attach($agentUser->id, [
                    'role'      => 'agent',
                    'joined_at' => now(),
                ]);
                $conversation->load('participants');
                Cache::forget("conversation.{$conversationId}");
            }

            // The ChatMessageHandler expects 'content' but the staff API
            // accepts 'message' (more natural for dashboard consumers).
            // Remap here so the handler receives the field it validates against.
            $data            = $request->all();
            $data['content'] = $data['message'] ?? null;

            $message = $this->messageHandler->sendMessage(
                $conversationId,
                $agentUser,
                $data
            );

            // Real-time delivery to the user's app via WebSocket
            broadcast(new MessageSent($message));

            return response()->json([
                'status'  => 'success',
                'message' => 'Message sent.',
                'data'    => $this->messageHandler->formatMessage($message),
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status'  => 'error',
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

        if (!$employee->email) {
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
        // Zero DB queries — uses the already eager-loaded participants collection.
        // Looked up by pivot role rather than "not me": now that any staff member
        // can join a support conversation on reply, there can be more than one
        // agent-side participant, and "not me" could resolve to a colleague
        // instead of the customer.
        $otherUser = $conversation->participants
            ->first(fn ($p) => $p->pivot->role === 'customer')
            ?? $conversation->participants->firstWhere('id', '!=', $agentUser->id);

        // Zero DB queries — uses the already eager-loaded latestMessage relation
        $lastMessage = $conversation->latestMessage;

        // Zero DB queries — profile already loaded via participants.profile
        $profilePhoto = $otherUser?->profile?->profile_photo
            ? asset('storage/' . $otherUser->profile->profile_photo)
            : null;

        return [
            'id'   => $conversation->id,
            'type' => $conversation->type,

            'user' => $otherUser ? [
                'id'             => $otherUser->id,
                'name'           => trim("{$otherUser->first_name} {$otherUser->last_name}"),
                'email'          => $otherUser->email,
                'profile_photo'  => $profilePhoto,
                'account_status' => match ((int) $otherUser->status) {
                    -1      => 'banned',
                    0       => 'inactive',
                    default => 'active',
                },
            ] : null,

            'last_message' => $lastMessage ? [
                'content'        => $lastMessage->type === 'image'
                    ? asset('storage/' . $lastMessage->content)
                    : $lastMessage->content,
                'sender_name'    => $lastMessage->sender?->first_name, // already eager-loaded
                // "Not the customer" rather than "not me": in the shared inbox any
                // staff member's reply should read as agent-side, not just the
                // viewer's own messages.
                'sent_by_agent'  => $lastMessage->sender?->id !== $otherUser?->id,
                'created_at'     => $lastMessage->created_at->diffForHumans(),
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
            'status'  => 'error',
            'message' => "Staff account '{$employee->username}' has no email address. " .
                "Add an email to this account to enable chat access.",
        ], 422);
    }

    private function serverError(\Exception $e): JsonResponse
    {
        \Illuminate\Support\Facades\Log::error('StaffChatController error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
