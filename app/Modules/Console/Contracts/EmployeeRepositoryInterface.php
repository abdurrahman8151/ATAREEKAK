<?php

namespace App\Modules\Console\Contracts;

use App\DTOs\Auth\StaffAccount;
use App\DTOs\Auth\StaffCredentials;
use App\DTOs\Auth\StaffTokenSubject;
use App\Enums\StaffRole;
use App\Modules\Console\Models\Employee;
use Carbon\Carbon;
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

    /**
     * Stage 2 C2: the login credentials for a username OR email, with the stored hash.
     *
     * Identity's staff-auth path used to receive an `Employee` and read `->password`, `->is_active`,
     * `->role` and `->token_version` off it. These four methods return kernel DTOs instead, so Identity
     * never names the model Console owns.
     */
    public function credentialsFor(string $identifier): ?StaffCredentials;

    /** The account shape (no hash) for one employee, or null. */
    public function accountById(int $id): ?StaffAccount;

    /** The token subject for an ACTIVE employee, or null - the refresh path's rule. */
    public function activeTokenSubjectById(int $id): ?StaffTokenSubject;

    /** Build a token subject from a row the caller already holds - no query. */
    public function tokenSubjectFor(Employee $employee): StaffTokenSubject;

    /** Build the account shape from a row the caller already holds - no query. */
    public function accountFor(Employee $employee): StaffAccount;

    /**
     * Stage 2 C2: accounts for many employees in ONE query, keyed by id - the batched replacement for the
     * `Complaint::assignedAgent` relation that Console's complaint screens used to eager-load.
     *
     * @param  array<int, int>  $ids
     * @return array<int, StaffAccount>
     */
    public function accountsFor(array $ids): array;

    /** Record a successful login and return the timestamp written (the login response reports it). */
    public function markLoggedIn(int $id): Carbon;
}
