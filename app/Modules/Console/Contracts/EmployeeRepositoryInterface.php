<?php

namespace App\Modules\Console\Contracts;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

interface EmployeeRepositoryInterface
{
    public function create(array $data): Employee;

    public function findById(int $id): ?Employee;

    public function findByUsername(string $username): ?Employee;

    public function findByEmail(string $email): ?Employee;

    public function update(int $id, array $data): Employee;

    public function listAll(): Collection;

    public function listByRole(StaffRole $role): Collection;

    /**
     * Returns all employees whose role is manageable by the given manager.
     */
    public function listManageableBy(Employee $manager): Collection;
}
