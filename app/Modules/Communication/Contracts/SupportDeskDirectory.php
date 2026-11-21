<?php

namespace App\Modules\Communication\Contracts;

use App\DTOs\Communication\SupportDeskAgent;

/**
 * Stage 4 D-1: the support-desk lookup `/api/contact` needs, declared by its CONSUMER.
 *
 * Before this contract Communication reached Console twice for one response:
 * `SupportAgentRoutingInterface::leastLoadedSupportAgent()` (Console's `Employee` model) and then
 * `EmployeeManagementInterface::ensureShadowUser($employee)` (the chat principal for that employee). Two ports on
 * one edge, one workflow - and that is what kept the `Communication <-> Console` cycle alive.
 *
 * The inversion is the same move as `ProfilePhotoDirectory` and the stage 3 `ComplaintRecorder`: the module that
 * NEEDS the answer declares the shape, the module that OWNS the data implements and binds it. Console still owns
 * `employees` and still decides who is least loaded; Communication names no Console class at all.
 *
 * The DTO is the kernel's, so neither side names the other's implementation.
 */
interface SupportDeskDirectory
{
    /**
     * The least-loaded active support agent, or null when there is none (the endpoint answers 503).
     *
     * The shadow `User` row is ensured as part of the answer - the same self-heal the endpoint performed as a second
     * call, now inside the owner so a caller cannot forget it.
     */
    public function leastLoadedAgent(): ?SupportDeskAgent;
}
