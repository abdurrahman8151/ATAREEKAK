<?php

namespace App\Repositories;

use App\Enums\StaffRole;
use App\Interfaces\EmployeeRepositoryInterface;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

class EmployeeRepository implements EmployeeRepositoryInterface
{
    public function create(array $data): Employee
    {
        return Employee::create($data);
    }

    public function findById(int $id): ?Employee
    {
        return Employee::find($id);
    }

    public function findByUsername(string $username): ?Employee
    {
        return Employee::where('username', $username)->first();
    }

    public function findByEmail(string $email): ?Employee
    {
        return Employee::where('email', $email)->first();
    }

    public function update(int $id, array $data): Employee
    {
        $employee = Employee::findOrFail($id);
        $employee->update($data);

        return $employee->fresh();
    }

    public function listAll(): Collection
    {
        return Employee::with('creator:id,username,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function listByRole(StaffRole $role): Collection
    {
        return Employee::where('role', $role->value)
            ->with('creator:id,username,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function listManageableBy(Employee $manager): Collection
    {
        $manageableRoles = array_map(
            fn (StaffRole $r) => $r->value,
            $manager->role->creatableRoles()
        );

        return Employee::whereIn('role', $manageableRoles)
            ->with('creator:id,username,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();
    }
}
