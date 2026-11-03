<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Modules\Identity\Contracts\TokenRevokerInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Rotate the password of a restricted admin account (system_admin or sycash).
 *
 * Why a command instead of a UI?
 *   - Restricted accounts cannot be changed via the management API (by design).
 *   - A CLI command requires server access — a much higher bar than a stolen JWT.
 *   - The command invalidates all active sessions immediately after rotation.
 *
 * Usage:
 *   php artisan admin:rotate-password system_admin
 *   php artisan admin:rotate-password sycash
 *   php artisan admin:rotate-password system_admin --password="s3cr3t!"
 *
 * In CI/CD (non-interactive):
 *   php artisan admin:rotate-password system_admin --password="${NEW_PASS}" --force
 */
class RotateAdminPasswordCommand extends Command
{
    protected $signature = 'admin:rotate-password
                            {account : Username of the account (system_admin or sycash)}
                            {--password= : New password (you will be prompted if omitted)}
                            {--force : Skip the confirmation prompt (for CI/CD pipelines)}';

    protected $description = 'Rotate the password of a restricted admin account and invalidate all sessions.';

    public function __construct(
        private readonly TokenRevokerInterface $jwtService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $username = $this->argument('account');

        $employee = $this->resolveRestrictedEmployee($username);

        if (! $employee) {
            return self::FAILURE;
        }

        $password = $this->resolveNewPassword($username, $employee);

        if ($password === null) {
            return self::FAILURE;
        }

        if (! $this->confirmRotation($username, $employee)) {
            return self::SUCCESS;
        }

        $this->applyRotation($username, $employee, $password);

        return self::SUCCESS;
    }

    /**
     * Load the account and refuse anything that is not a restricted admin role.
     */
    private function resolveRestrictedEmployee(string $username): ?Employee
    {
        // ── Load & validate ───────────────────────────────────────────────────

        $employee = Employee::where('username', $username)->first();

        if (! $employee) {
            $this->error("No employee found with username: {$username}");

            return null;
        }

        if (! $employee->role->isRestricted()) {
            $this->error(
                "'{$username}' is not a restricted account ({$employee->role->label()}). ".
                'Use the management API to reset passwords for regular staff.'
            );

            return null;
        }

        return $employee;
    }

    /**
     * Read the new password (prompting when needed) and validate it.
     */
    private function resolveNewPassword(string $username, Employee $employee): ?string
    {
        // ── Get new password ──────────────────────────────────────────────────

        $password = $this->option('password');

        if (! $password) {
            $password = $this->secret(
                "New password for {$employee->role->label()} ({$username})"
            );
        }

        if (! $password || mb_strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return null;
        }

        if (! $this->option('force') && ! $this->option('password')) {
            $confirmed = $this->secret('Confirm new password');
            if ($password !== $confirmed) {
                $this->error('Passwords do not match.');

                return null;
            }
        }

        return $password;
    }

    /**
     * Ask for confirmation unless --force was passed.
     */
    private function confirmRotation(string $username, Employee $employee): bool
    {
        // ── Confirm (unless --force) ──────────────────────────────────────────

        if (! $this->option('force')) {
            $proceed = $this->confirm(
                "Rotate password for {$employee->role->label()} ({$username}) ".
                'and invalidate ALL active sessions?'
            );
            if (! $proceed) {
                $this->info('Aborted.');

                return false;
            }
        }

        return true;
    }

    /**
     * Write the new hash and invalidate every active session.
     */
    private function applyRotation(string $username, Employee $employee, string $password): void
    {
        // ── Apply ─────────────────────────────────────────────────────────────

        $employee->password = Hash::make($password);
        $employee->save();

        // Bump token_version — this invalidates every JWT issued before now
        $this->jwtService->revokeAllTokens($employee->id);

        $this->info("✓ Password rotated for {$employee->role->label()} ({$username}).");
        $this->info('✓ All active sessions have been invalidated.');
    }
}
