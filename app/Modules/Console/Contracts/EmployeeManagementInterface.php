<?php

namespace App\Modules\Console\Contracts;

use App\Models\Employee;
use App\Models\User;

/**
 * Port over the shadow-user lookup used by the contact form (batch 233).
 *
 * COMM routes a contact-form message to a support agent; CONSOLE owns staff records and the shadow
 * `users` rows they map to. Before this port `ContactController` type-hinted
 * `App\Services\Staff\EmployeeManagementService` for the one call below.
 */
interface EmployeeManagementInterface
{
    /** The `users` row that represents this employee in chat, created on first use. */
    public function ensureShadowUser(Employee $employee): User;
}
