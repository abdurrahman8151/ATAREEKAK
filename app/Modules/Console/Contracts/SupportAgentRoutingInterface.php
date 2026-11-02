<?php

namespace App\Modules\Console\Contracts;

use App\Models\Employee;

/**
 * Port over the support-agent routing rule (batch 233).
 *
 * COMM needs "which agent should this contact message go to?"; CONSOLE owns the staff table and the
 * load metric. Before this port `ContactController` type-hinted
 * `App\Modules\Console\Services\SupportAgentRoutingService` directly.
 */
interface SupportAgentRoutingInterface
{
    /** The least-loaded active support agent, or null when there is none. */
    public function leastLoadedSupportAgent(): ?Employee;
}
