<?php

namespace App\Services\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * EmployeeManagementService
 *
 * All CRUD for Employee accounts lives here — never in the controller.
 *
 * Shadow User Bridge
 * ──────────────────
 * The chat system stores conversations against the `users` table, not the
 * `employees` table.  ContactController and StaffChatController bridge the
 * two by matching Employee::email → User::email.
 *
 * To make this permanent and automatic:
 *   • create()  auto-creates a shadow User when a support_agent is created.
 *   • delete()  removes the shadow User when the employee is deleted
 *               (only if the User was never verified as a driver/passenger).
 *   • The shadow User is only used for chat — agents never log in via the
 *     user-facing app.  Its password is a random unguessable string.
 *
 * Invariants enforced unconditionally:
 *   1. system_admin and sycash (restricted roles) can never be created,
 *      deactivated, or deleted via the API.
 *   2. A requester can only manage roles that StaffRole::canManage() permits
 *      for their own role tier.
 *
 * Public method surface (what the controller calls):
 *   getAll()         — list all employees visible to requester
 *   getById()        — single employee with visibility check
 *   create()         — create a new employee account
 *   update()         — update mutable profile fields
 *   rotatePassword() — rotate password + invalidate all sessions
 *   toggleActive()   — flip is_active + revoke tokens on deactivation
 *   delete()         — permanently delete + revoke tokens
 */
class EmployeeManagementService
{
    public function __construct(
        private readonly StaffJwtService $staffJwtService,
    ) {}

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * All employees visible to the requester.
     *
     * system_admin → everyone
     * admin        → admin + support_agent (restricted rows hidden)
     * others       → caller should gate before reaching here
     */
    public function getAll(Employee $requester): Collection
    {
        return Employee::orderBy('role')
            ->orderBy('username')
            ->get()
            ->filter(fn (Employee $emp) =>
                $emp->id === $requester->id
                || $requester->role->canManage($emp->role)
            )
            ->values();
    }

    /**
     * Single employee — enforces visibility by role tier.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \DomainException
     */
    public function getById(int $id, Employee $requester): Employee
    {
        $employee = Employee::findOrFail($id);

        if (
            $employee->id !== $requester->id
            && !$requester->role->canManage($employee->role)
        ) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) cannot view this account."
            );
        }

        return $employee;
    }

    /**
     * Shape an Employee for API responses.
     */
    public function formatEmployee(Employee $e): array
    {
        return [
            'id'         => $e->id,
            'username'   => $e->username,
            'email'      => $e->email,
            'full_name'  => $e->fullName(),
            'first_name' => $e->first_name,
            'last_name'  => $e->last_name,
            'role'       => $e->role->value,
            'role_label' => $e->role->label(),
            'is_active'  => $e->is_active,
            'created_by' => $e->creator
                ? ['id' => $e->creator->id, 'username' => $e->creator->username, 'name' => $e->creator->fullName()]
                : null,
            'last_login_at' => $e->last_login_at,
            'created_at'    => $e->created_at,
        ];
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Create a new employee account.
     *
     * Guards (checked in order before any DB write):
     *   1. Target role must not be restricted (system_admin / sycash).
     *   2. Requester's role must be permitted to manage the target role.
     *   3. Username must be unique across the employees table.
     *   4. Email (optional for most roles) must be unique if provided.
     *   5. Support agents must supply an email — required for the chat bridge.
     *
     * After creation, support agents automatically get a shadow User account
     * so ContactController and StaffChatController can find them immediately
     * without any manual setup.
     *
     * @throws \DomainException
     */
    public function create(array $data, Employee $requester): Employee
    {
        $role = StaffRole::from($data['role']);

        // ── Guard 1: restricted roles can never be created via the API ────────
        if ($role->isRestricted()) {
            throw new \DomainException(
                "The '{$role->label()}' account cannot be created via the API. " .
                "It is seeded once at deployment. To rotate the password run: " .
                "php artisan admin:rotate-password {$role->value}"
            );
        }

        // ── Guard 2: requester must be permitted to create this role ──────────
        if (!$requester->role->canManage($role)) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) is not permitted to " .
                "create a '{$role->label()}' account."
            );
        }

        // ── Guard 3: unique username ──────────────────────────────────────────
        if (Employee::where('username', $data['username'])->exists()) {
            throw new \RuntimeException("Username '{$data['username']}' is already taken.");
        }

        // ── Guard 4: unique email (if provided) ───────────────────────────────
        if (
            !empty($data['email'])
            && Employee::where('email', $data['email'])->exists()
        ) {
            throw new \RuntimeException("Email '{$data['email']}' is already in use.");
        }

        // ── Guard 5: support agents must have an email ────────────────────────
        // The chat bridge matches Employee::email → User::email.
        // Without an email there is no bridge, and ContactController returns 503.
        if ($role === StaffRole::SUPPORT_AGENT && empty($data['email'])) {
            throw new \DomainException(
                "Support agents must have an email address. " .
                "It is used to link the employee to the chat system."
            );
        }

        $employee = Employee::create([
            'username'   => $data['username'],
            'email'      => $data['email'] ?? null,
            'password'   => Hash::make($data['password']),
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'role'       => $role->value,
            'is_active'  => true,
            'created_by' => $requester->id,   // ← was missing; always track who created whom
        ]);

        // ── Auto-create shadow User for the chat bridge ───────────────────────
        // This runs for support_agent (and admin, who may also participate in
        // chat via StaffChatController).  system_admin / sycash are restricted
        // roles and are already blocked above.
        if ($employee->email) {
            $this->ensureShadowUser($employee);
        }

        return $employee;
    }

    // =========================================================================
    // UPDATE (non-password fields)
    // =========================================================================

    /**
     * Update mutable profile fields on an employee.
     * Password changes are handled by rotatePassword() — never here.
     *
     * @throws \DomainException
     */
    public function update(int $id, array $data, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        // Restricted accounts are immutable via the API
        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be modified via the API."
            );
        }

        // Role changes must target a non-restricted role the requester can manage
        if (isset($data['role'])) {
            $newRole = StaffRole::from($data['role']);

            if ($newRole->isRestricted()) {
                throw new \DomainException(
                    "Cannot assign the restricted role '{$newRole->label()}' via the API."
                );
            }

            if (!$requester->role->canManage($newRole)) {
                throw new \DomainException(
                    "Your role ({$requester->role->label()}) cannot assign '{$newRole->label()}'."
                );
            }

            $data['role'] = $newRole->value;
        }

        // Username uniqueness when changing
        if (
            isset($data['username'])
            && $data['username'] !== $employee->username
            && Employee::where('username', $data['username'])->exists()
        ) {
            throw new \RuntimeException("Username '{$data['username']}' is already taken.");
        }

        // Email uniqueness when changing
        if (
            isset($data['email'])
            && $data['email'] !== $employee->email
            && Employee::where('email', $data['email'])->exists()
        ) {
            throw new \RuntimeException("Email '{$data['email']}' is already in use.");
        }

        $oldEmail = $employee->email;

        $employee->fill(
            array_intersect_key($data, array_flip([
                'username', 'email', 'first_name', 'last_name', 'role',
            ]))
        );
        $employee->save();

        // Keep shadow User email in sync when the employee's email changes
        if (
            isset($data['email'])
            && $data['email'] !== $oldEmail
            && $oldEmail !== null
        ) {
            User::where('email', $oldEmail)
                ->where('is_verified_driver', 0)
                ->where('is_verified_passenger', 0)
                ->update([
                    'email'      => $data['email'],
                    'first_name' => $data['first_name'] ?? $employee->first_name,
                    'last_name'  => $data['last_name']  ?? $employee->last_name,
                ]);
        }

        return $employee;
    }

    // =========================================================================
    // PASSWORD ROTATION
    // =========================================================================

    /**
     * Rotate an employee's password and invalidate all active sessions.
     *
     * Restricted accounts (system_admin / sycash) must use the Artisan command
     * instead — this method throws for them so the API can never touch them.
     *
     * Called by the controller as: $this->managementService->rotatePassword(...)
     *
     * @throws \DomainException
     */
    public function rotatePassword(int $id, string $newPassword, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' password must be rotated via Artisan: " .
                "php artisan admin:rotate-password {$employee->role->value}"
            );
        }

        $employee->password      = Hash::make($newPassword);
        $employee->token_version = ($employee->token_version ?? 0) + 1;
        $employee->save();

        $this->staffJwtService->revokeAllTokens($employee->id);

        return $employee;
    }

    // =========================================================================
    // TOGGLE ACTIVE
    // =========================================================================

    /**
     * Flip is_active on an employee account.
     * Deactivating immediately revokes all tokens so in-flight requests fail.
     *
     * @throws \DomainException
     */
    public function toggleActive(int $id, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be deactivated via the API."
            );
        }

        $employee->is_active = !$employee->is_active;
        $employee->save();

        if (!$employee->is_active) {
            $this->staffJwtService->revokeAllTokens($employee->id);
        }

        return $employee;
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    /**
     * Permanently delete an employee account.
     * Tokens are revoked first so any in-flight requests are rejected cleanly.
     * The shadow User is removed if it was never independently verified.
     *
     * @throws \DomainException
     */
    public function delete(int $id, Employee $requester): void
    {
        $employee = $this->getById($id, $requester);

        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be deleted via the API."
            );
        }

        if (!$requester->role->canManage($employee->role)) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) cannot delete " .
                "a '{$employee->role->label()}' account."
            );
        }

        $this->staffJwtService->revokeAllTokens($employee->id);

        // Clean up shadow User — but only if the User was never independently
        // verified as a driver or passenger (i.e. it is purely a chat bridge).
        if ($employee->email) {
            User::where('email', $employee->email)
                ->where('is_verified_driver', 0)
                ->where('is_verified_passenger', 0)
                ->delete();
        }

        $employee->delete();
    }

    // =========================================================================
    // PRIVATE — SHADOW USER BRIDGE
    // =========================================================================

    /**
     * Ensure a User record exists for this employee so the chat bridge works.
     *
     * Called automatically on create().
     * Also called by ContactController as a self-healing fallback for employees
     * created before this fix was deployed.
     *
     * The shadow User's password is a random unguessable string — agents
     * never log in via the user app.  The account exists solely so the chat
     * system can find them.
     */
    public function ensureShadowUser(Employee $employee): User
    {
        $existing = User::where('email', $employee->email)->first();

        if ($existing) {
            return $existing;
        }

        return User::create([
            'first_name'        => $employee->first_name,
            'last_name'         => $employee->last_name,
            'email'             => $employee->email,
            'password'          => Str::random(64), // never used — agent logs in via staff portal
            'gender'            => 'M',
            'address'           => 'دمشق',
            'status'            => 1,
            'email_verified_at' => now(),
        ]);
    }
}
