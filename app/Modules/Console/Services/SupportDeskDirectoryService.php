<?php

namespace App\Modules\Console\Services;

use App\DTOs\Communication\SupportDeskAgent;
use App\Modules\Communication\Contracts\SupportDeskDirectory;
use App\Modules\Console\Contracts\EmployeeManagementInterface;
use App\Modules\Console\Contracts\SupportAgentRoutingInterface;

/**
 * Stage 4 D-1: Console answers Communication's support-desk question in ONE call.
 *
 * The two steps the `/api/contact` endpoint used to perform itself - pick the least-loaded agent, then ensure that
 * agent's shadow chat account - now happen here, in the module that owns `employees`. Same routing query, same
 * self-heal, same shadow-user creation; the caller only sees the DTO.
 *
 * Console names Communication's CONTRACT (and the kernel DTO), never its implementation, which is what lets
 * `Communication -> Console` disappear while the cycle stays cut in both directions being legal.
 */
final class SupportDeskDirectoryService implements SupportDeskDirectory
{
    public function __construct(
        private readonly SupportAgentRoutingInterface $routing,
        private readonly EmployeeManagementInterface $employees,
    ) {}

    public function leastLoadedAgent(): ?SupportDeskAgent
    {
        $agent = $this->routing->leastLoadedSupportAgent();

        if ($agent === null) {
            return null;
        }

        // The same permanent self-heal the endpoint ran as its second call.
        $shadowUser = $this->employees->ensureShadowUser($agent);

        return new SupportDeskAgent(
            agentId: (int) $agent->id,
            name: trim($agent->first_name.' '.$agent->last_name),
            shadowUser: $shadowUser,
        );
    }
}
