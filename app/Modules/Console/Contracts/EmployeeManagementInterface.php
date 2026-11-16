<?php

namespace App\Modules\Console\Contracts;

use App\Models\User;
use App\Modules\Console\Models\Employee;

/**
 * Port over the shadow-user lookup used by the contact form (batch 233).
 *
 * COMM routes a contact-form message to a support agent; CONSOLE owns staff records and the shadow
 * `users` rows they map to. Before this port `ContactController` type-hinted
 * `App\Modules\Console\Services\EmployeeManagementService` for the one call below.
 */
interface EmployeeManagementInterface
{
    /** The `users` row that represents this employee in chat, created on first use. */
    public function ensureShadowUser(Employee $employee): User;

    /**
     * Stage 2 C2: the staff middleware resolves the employee behind an access token through this port, so the
     * kernel entrypoint never names the model (the class moved into this module).
     */
    public function findEmployeeById(int $id): ?Employee;
}
