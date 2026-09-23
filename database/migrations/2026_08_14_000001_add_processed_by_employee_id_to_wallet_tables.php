<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `wallet_requests.processed_by` and (the actor slot ad hoc-ed into)
 * `wallet_transactions.user_id` are FK'd to / read as `users`, but the actor
 * for every admin-side wallet action is an Employee (staff), a different id
 * space under `staff` auth. Writing an employee id into either column risks
 * either an FK violation or — worse — silently attributing the action to
 * whichever unrelated `users` row happens to share that numeric id.
 *
 * Adds a dedicated, correctly-scoped column to each table instead of
 * repurposing the `users`-FK'd ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->foreignId('processed_by_employee_id')
                ->nullable()
                ->after('processed_by')
                ->constrained('employees')
                ->nullOnDelete();
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('processed_by_employee_id')
                ->nullable()
                ->after('user_id')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by_employee_id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by_employee_id');
        });
    }
};
