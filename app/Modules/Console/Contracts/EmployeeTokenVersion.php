<?php

namespace App\Modules\Console\Contracts;

/**
 * Stage 2 C2: bump an employee's token version, without Identity writing the `employees` table.
 *
 * `StaffJwtService::revokeAllTokens()` revokes the refresh tokens and then increments `employees.token_version`
 * so every outstanding access token stops verifying. That increment is Console's data being written by
 * Identity - invisible to any import- or relation-level check, because it goes through the model rather than
 * a raw query.
 *
 * The contract is a single verb because that is the whole need: Identity must be able to invalidate, and must
 * not be able to read, list or change anything else about an employee. Nothing about the statement changes -
 * one `UPDATE ... SET token_version = token_version + 1`, run by the module that owns the column.
 */
interface EmployeeTokenVersion
{
    /** Increment the employee's `token_version`, invalidating every access token issued before it. */
    public function bump(int $employeeId): void;
}
