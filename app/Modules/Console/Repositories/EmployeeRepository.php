<?php

namespace App\Modules\Console\Repositories;

use App\DTOs\Auth\StaffAccount;
use App\DTOs\Auth\StaffCredentials;
use App\DTOs\Auth\StaffTokenSubject;
use App\Enums\StaffRole;
use App\Models\Employee;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use Carbon\Carbon;
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

    // ── Stage 2 C2: the data Identity's staff-auth path needs, as DTOs not models ──────────────

    /**
     * The login credentials for a username OR email, with the stored hash. Two lookups at most - the
     * same `findByUsername() ?? findByEmail()` the caller used to run itself.
     */
    public function credentialsFor(string $identifier): ?StaffCredentials
    {
        $employee = $this->findByUsername($identifier) ?? $this->findByEmail($identifier);

        if (! $employee) {
            return null;
        }

        return new StaffCredentials($this->accountFor($employee), (string) $employee->password);
    }

    /** The account shape, no hash - one lookup. */
    public function accountById(int $id): ?StaffAccount
    {
        $employee = $this->findById($id);

        return $employee ? $this->accountFor($employee) : null;
    }

    /**
     * The token subject for an ACTIVE employee, or null - the refresh path's whole question. The
     * `is_active` rule lives here, with the row's owner, instead of in Identity's token service.
     */
    public function activeTokenSubjectById(int $id): ?StaffTokenSubject
    {
        $employee = $this->findById($id);

        if (! $employee || ! $employee->is_active) {
            return null;
        }

        return $this->accountFor($employee)->tokenSubject();
    }

    /** Build a token subject from a row the caller already holds - no query. */
    public function tokenSubjectFor(Employee $employee): StaffTokenSubject
    {
        return $this->accountFor($employee)->tokenSubject();
    }

    /** Build the account shape from a row the caller already holds - no query. */
    public function accountFor(Employee $employee): StaffAccount
    {
        return new StaffAccount(
            id: (int) $employee->id,
            username: (string) $employee->username,
            email: (string) $employee->email,
            firstName: $employee->first_name,
            lastName: $employee->last_name,
            role: $employee->role,
            isActive: (bool) $employee->is_active,
            tokenVersion: (int) $employee->token_version,
            lastLoginAt: $employee->last_login_at,
            createdAt: $employee->created_at,
        );
    }

    /**
     * Record a successful login and return the timestamp written - the same single-column update the
     * caller used to run on the model, so its login response reports the new `last_login_at`.
     */
    public function markLoggedIn(int $id): Carbon
    {
        $at = now();

        Employee::whereKey($id)->update(['last_login_at' => $at]);

        return $at;
    }
}
