<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * SpecialAccountSeeder
 *
 * Creates the two privileged accounts that can never be made via the API:
 *   - system_admin : full platform control
 *   - sycash       : financial / wallet management only
 *
 * Run once on initial deployment:
 *   php artisan migrate
 *   php artisan db:seed --class=SpecialAccountSeeder
 *
 * To rotate a password later:
 *   php artisan admin:rotate-password system_admin
 *   php artisan admin:rotate-password sycash
 *
 * Never commit real credentials — set them in .env (see .env.example).
 */
class SpecialAccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(
            role: StaffRole::SYSTEM_ADMIN,
            envPrefix: 'SYSTEM_ADMIN',
            defaults: ['username' => 'system_admin', 'first' => 'System', 'last' => 'Admin'],
        );

        $this->seed(
            role: StaffRole::SYCASH,
            envPrefix: 'SYCASH',
            defaults: ['username' => 'sycash', 'first' => 'SyCash', 'last' => 'Admin'],
        );
    }

    // -------------------------------------------------------------------------

    private function seed(StaffRole $role, string $envPrefix, array $defaults): void
    {
        $username = env("{$envPrefix}_USERNAME", $defaults['username']);
        $email = env("{$envPrefix}_EMAIL");
        $password = env("{$envPrefix}_PASSWORD");

        // Hard-stop: credentials must come from .env, never from defaults
        if (empty($email) || empty($password)) {
            $this->command->warn(
                "[SpecialAccountSeeder] Skipping {$role->label()}: ".
                "{$envPrefix}_EMAIL and {$envPrefix}_PASSWORD must be set in .env"
            );

            return;
        }

        $employee = Employee::firstOrCreate(
            ['username' => $username],
            [
                'email' => $email,
                'password' => Hash::make($password),
                'first_name' => env("{$envPrefix}_FIRST_NAME", $defaults['first']),
                'last_name' => env("{$envPrefix}_LAST_NAME", $defaults['last']),
                'role' => $role->value,
                'is_active' => true,
                'token_version' => 0,
            ]
        );

        if ($employee->wasRecentlyCreated) {
            $this->command->info(
                "[SpecialAccountSeeder] ✓ Created {$role->label()} → username: {$username}"
            );
        } else {
            $this->command->line(
                "[SpecialAccountSeeder] {$role->label()} already exists ({$username}) — skipped."
            );
        }
    }
}
