<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decision 2 (owner, 2026-10-02, Option B): "expire unconfirmed bookings, do NOT auto-confirm".
 *
 * WHY A NEW STATUS rather than reusing `cancelled`.
 * A PENDING booking that the driver never answers is a DIFFERENT event from a cancellation: the
 * passenger did not change their mind, the driver did not reject, nothing failed. Reusing
 * `cancelled` would make "how often do drivers ignore requests?" unanswerable, and would show an
 * expiry in the passenger's history as though they had cancelled. The owner's ruling is to EXPIRE
 * them, so the record should say expired.
 *
 * WHAT EXPIRY DOES *NOT* DO (verified before writing this):
 *   - it moves NO money. A PENDING booking is never charged — `BookingService::bookRide` charges
 *     e-pay only for DIRECT bookings that are immediately CONFIRMED, and `acceptBooking` charges
 *     REQUEST bookings only at the moment the driver accepts. A booking that is still PENDING has
 *     therefore never touched the passenger's wallet and has nothing in SyCash escrow to release.
 *     There is no escrow-release rule to write because there is no escrow; asserting that here so
 *     a future reader does not "helpfully" add a refund path for money that was never taken.
 *   - it returns NO seats. `deductSeats` runs only on accept, so an expired PENDING booking never
 *     held a seat.
 *
 * `down()` restores the previous enum exactly. MySQL cannot drop an enum value in place, so the
 * column is rebuilt from the rows that exist - which is why the up() body is written to preserve
 * every current value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MODIFY rather than change(): the enum must keep every existing value or existing rows
        // would be coerced to '' and silently corrupted.
        DB::statement(
            "ALTER TABLE `bookings` MODIFY `status` ENUM('pending','confirmed','cancelled','no_show','completed','expired') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        // Any row that reached `expired` cannot be represented by the old enum. They are moved to
        // `cancelled` (the closest truthful historical state) BEFORE the column is narrowed, so the
        // rollback never fails on data it cannot encode.
        DB::statement("UPDATE `bookings` SET `status` = 'cancelled' WHERE `status` = 'expired'");

        DB::statement(
            "ALTER TABLE `bookings` MODIFY `status` ENUM('pending','confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'pending'"
        );
    }
};
