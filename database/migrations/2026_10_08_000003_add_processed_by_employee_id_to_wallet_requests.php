<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3-4 - `wallet_requests.processed_by` references `users`, but the acting principal is an Employee.
 *
 * WHAT WAS WRONG. T2-1 made `StaffJwtMiddleware` bind a user resolver so the admin controllers'
 * `$request->user()?->id` stopped writing NULL. The resolver does not return the employee: it
 * returns a SHADOW `users` row created (or found) from the employee's email, because the columns
 * carry real foreign keys to `users` and an Employee id there raises SQLSTATE 23000 and aborts the
 * money-moving transaction.
 *
 * So `processed_by` records a synthetic mirror id rather than the employee who actually approved
 * the request. Worse, `ensureShadowUser()` looks the user up BY EMAIL: if a real customer already
 * holds the admin's email address, the "shadow" is that customer, and the financial audit trail
 * records a real user's id as the acting admin. The value resolves in `users`, so nothing surfaces
 * the confusion - which is exactly what T3-4 describes.
 *
 * WHAT THIS DOES. Adds `processed_by_employee_id`, a nullable FK to `employees`, and writes the real
 * employee id at both admin write sites. The existing `processed_by` column is KEPT and still
 * written, so every row processed before this migration keeps its existing value and no historical
 * row is invalidated (its FK is to `users` and its meaning is unchanged).
 *
 * Nullable on purpose: `pending` and `cancelled` requests are processed by nobody, and historical
 * rows cannot be attributed retroactively. `nullOnDelete` matches `processed_by` and means removing
 * an employee leaves the requests they approved intact rather than deleting the financial records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_requests')) {
            throw new RuntimeException(
                'T3-4: the wallet_requests table does not exist. This migration adds the employee '
                .'attribution column to it and must not be skipped silently.'
            );
        }

        if (Schema::hasColumn('wallet_requests', 'processed_by_employee_id')) {
            return;
        }

        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->foreignId('processed_by_employee_id')
                ->nullable()
                ->after('processed_by')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_requests')) {
            return;
        }

        if (! Schema::hasColumn('wallet_requests', 'processed_by_employee_id')) {
            return;
        }

        Schema::table('wallet_requests', function (Blueprint $table) {
            // Drop the FK by name first so MySQL does not have to infer it.
            $table->dropForeign(['processed_by_employee_id']);
            $table->dropColumn('processed_by_employee_id');
        });
    }
};
