<?php

namespace App\DTOs\Communication;

use App\Models\User;

/**
 * Stage 4 D-1: the answer to "which support agent should this contact go to, and what is that agent in chat?".
 *
 * The `/api/contact` endpoint used to ask Console TWO questions through TWO ports - `SupportAgentRoutingInterface`
 * (which agent, returning Console's `Employee` model) and `EmployeeManagementInterface` (that agent's shadow `User`
 * row) - because it needs both answers to start a conversation and to print the agent's name. Since it is
 * Communication that needs them, Communication now declares ONE contract and Console answers it with this DTO.
 *
 * It lives in the kernel for the same reason `BookingMoneySnapshot` does: both sides must name it (Communication
 * declares the return type, Console constructs it), and a DTO in either module would make the other module name an
 * implementation class. `User` is the shared principal (ADR 0007), so carrying it is allowed - and it is exactly
 * what the chat repository needs to find or create the conversation.
 */
final class SupportDeskAgent
{
    public function __construct(
        public readonly int $agentId,
        public readonly string $name,
        public readonly User $shadowUser,
    ) {}
}
