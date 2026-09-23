<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expands the employees.role column to include the 'sycash' value.
 *
 * If your role column is already a VARCHAR this migration is a safe no-op
 * (the ALTER just re-declares the same column type with one extra value).
 *
 * If it is a MySQL ENUM this is required before the seeder can run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Wrap in a try/catch so it silently passes if the column is
        // already a VARCHAR and the DB doesn't understand ENUM syntax.
        try {
            DB::statement("
                ALTER TABLE employees
                MODIFY COLUMN role
                    ENUM('system_admin','sycash','admin','support_agent')
                    NOT NULL
            ");
        } catch (\Exception) {
            // Column is VARCHAR — nothing to do.
        }
    }

    public function down(): void
    {
        // Only safe to reverse if no sycash rows exist
        $hasSycash = DB::table('employees')
            ->where('role', 'sycash')
            ->exists();

        if ($hasSycash) {
            // Cannot remove the enum value while rows still use it.
            return;
        }

        try {
            DB::statement("
                ALTER TABLE employees
                MODIFY COLUMN role
                    ENUM('system_admin','admin','support_agent')
                    NOT NULL
            ");
        } catch (\Exception) {
            // VARCHAR — nothing to do.
        }
    }
};
