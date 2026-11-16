<?php

namespace App\Modules\Console\Services;

use App\Modules\Console\Contracts\EmployeeTokenVersion;
use App\Modules\Console\Models\Employee;

/**
 * Stage 2 C2: Console's implementation of the token-version bump.
 *
 * Same statement Identity ran before: `employees.token_version = token_version + 1` for one row. Kept as an
 * increment in SQL rather than a read-modify-write, so two concurrent revocations cannot land the same value.
 */
final class EmployeeTokenVersionBumper implements EmployeeTokenVersion
{
    public function bump(int $employeeId): void
    {
        Employee::where('id', $employeeId)->increment('token_version');
    }
}
