<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expands the employees.role column to include the 'sycash' value.
 *
 * If your role column is already a VARCHAR this migration is a safe no-op
 * (the ALTER just re-declares the same column type with one extra value).
 *
 * If it is a MySQL ENUM this is required before the seeder can run.
 *
 * Timestamped to run immediately after create_employees_table so the column
 * it modifies is guaranteed to exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees') || !Schema::hasColumn('employees', 'role')) {
            return;
        }

        if (!$this->roleColumnIsEnum()) {
            // Column is VARCHAR — nothing to widen.
            return;
        }

        DB::statement("
            ALTER TABLE employees
            MODIFY COLUMN role
                ENUM('system_admin','sycash','admin','support_agent')
                NOT NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('employees') || !Schema::hasColumn('employees', 'role')) {
            return;
        }

        // Only safe to reverse if no sycash rows exist
        $hasSycash = DB::table('employees')
            ->where('role', 'sycash')
            ->exists();

        if ($hasSycash) {
            // Cannot remove the enum value while rows still use it.
            return;
        }

        if (!$this->roleColumnIsEnum()) {
            return;
        }

        DB::statement("
            ALTER TABLE employees
            MODIFY COLUMN role
                ENUM('system_admin','admin','support_agent')
                NOT NULL
        ");
    }

    private function roleColumnIsEnum(): bool
    {
        $column = DB::selectOne("SHOW COLUMNS FROM employees WHERE Field = 'role'");

        return $column !== null && str_starts_with($column->Type, 'enum');
    }
};
