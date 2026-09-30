<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-40 — money schema foundation (Wave 3 prerequisite).
 *
 * WHY
 *
 * `bookings` stored NO monetary column. Every settlement/refund recomputed money as
 * `seats * ride.price_per_seat` at the moment it ran — 16 such sites across the money
 * services. `ride.price_per_seat` is mutable and `seats` changes on a partial cancel, so
 * "what a booking paid" was never a fact; it drifted with the ride. `Booking` even casts a
 * `total_price` column that does not exist. A price change, a partial-seat cancel, or a fee
 * change therefore silently altered what an already-paid booking "owed", and the ledger and
 * the truth could disagree with nothing to arbitrate them.
 *
 * This migration makes the amount an IMMUTABLE SNAPSHOT ON THE BOOKING:
 *   - unit_price       the per-seat price captured at charge time
 *   - amount_paid      what the passenger actually paid (seats x unit_price at charge)
 *   - escrow_held      how much of THIS booking is currently sitting in SyCash (the
 *                      settlement source of truth; RV-02 L2 drives idempotency off it)
 *   - payment_method   snapshot of cash/e-pay, so flipping the ride's method cannot change
 *                      how an existing booking is refunded
 *   - idempotency_key  + unique(user_id, idempotency_key) — RV-15's foundation; the old
 *                      Redis-only key was checked outside the transaction and was NOT
 *                      scoped by user (one user replaying another's key got their booking)
 *
 * DESIGN / SAFETY
 *   - Purely ADDITIVE: new nullable/zero-default columns + one composite unique index. No
 *     existing column is changed, so this cannot weaken current money integrity.
 *   - Driver-portable Blueprint (no raw MySQL): safe on sqlite (CI's default) and MySQL.
 *   - No data written here. Backfill of EXISTING bookings is a separate idempotent command
 *     (bookings:backfill-money-snapshot) run deliberately from the authoritative ledger, so
 *     a deploy that only migrates an empty DB never guesses values.
 *   - idempotency_key is nullable; MySQL permits many NULLs in a UNIQUE index, so bookings
 *     created without a key are unaffected — the constraint only bites when two rows of the
 *     SAME user share a non-null key, which is exactly the replay RV-15 must reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)
                ->default(0)
                ->comment('Per-seat price snapshotted at charge time; immutable after the booking is charged');

            $table->decimal('amount_paid', 15, 2)
                ->default(0)
                ->comment('Total the passenger actually paid for this booking (seats x unit_price at charge)');

            $table->decimal('escrow_held', 15, 2)
                ->default(0)
                ->comment('How much of this booking currently sits in SyCash; the settlement/refund source of truth');

            $table->string('payment_method', 20)
                ->nullable()
                ->comment('cash|e-pay snapshot at charge time; decouples refund logic from the mutable ride');

            $table->string('idempotency_key', 191)
                ->nullable()
                ->comment('Client-supplied booking key; RV-15 insert-or-return scoped by user');
        });

        // Composite unique: the same user may not two-phase-commit the same key to two
        // bookings. Scoped by user_id, which is precisely what RV-15 found the old Redis
        // cache did NOT enforce.
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique(['user_id', 'idempotency_key'], 'bookings_user_id_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        // Drop the index first (it depends on the column), then the columns.
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_user_id_idempotency_key_unique');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'unit_price',
                'amount_paid',
                'escrow_held',
                'payment_method',
                'idempotency_key',
            ]);
        });
    }
};
