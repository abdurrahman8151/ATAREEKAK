<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the initial system_admin employee.
 * Credentials are read from environment variables so they are never
 * committed to source control.
 *
 * Required .env keys:
 *   SYSTEM_ADMIN_USERNAME
 *   SYSTEM_ADMIN_PASSWORD
 *   SYSTEM_ADMIN_FIRST_NAME
 *   SYSTEM_ADMIN_LAST_NAME
 *   SYSTEM_ADMIN_EMAIL  (optional)
 */
class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SYSTEM_ADMIN_USERNAME');
        $password = env('SYSTEM_ADMIN_PASSWORD');

        if (! $username || ! $password) {
            $this->command->error(
                'SYSTEM_ADMIN_USERNAME and SYSTEM_ADMIN_PASSWORD must be set in .env'
            );

            return;
        }

        $existing = Employee::where('username', $username)->first();

        if ($existing) {
            $this->command->info("System admin '{$username}' already exists — skipping.");

            return;
        }

        Employee::create([
            'username' => $username,
            'email' => env('SYSTEM_ADMIN_EMAIL'),
            'password' => Hash::make($password),
            'first_name' => env('SYSTEM_ADMIN_FIRST_NAME', 'System'),
            'last_name' => env('SYSTEM_ADMIN_LAST_NAME', 'Admin'),
            'role' => StaffRole::SYSTEM_ADMIN->value,
            'is_active' => true,
            'created_by' => null,
            'token_version' => 0,
        ]);

        $this->command->info("System admin '{$username}' created successfully.");
    }
}
