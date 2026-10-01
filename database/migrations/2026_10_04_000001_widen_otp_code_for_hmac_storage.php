<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RV-16 (plaintext OTP storage) — widen `otps.otp_code` to hold the HMAC digest.
 *
 * `otp_code` is `varchar(6)` (the raw 6-digit code). The Otp model mutator now stores
 * `hash_hmac('sha256', …)` = 64 hex chars, so the column must fit it. Raw MySQL ALTER,
 * the exact T3-6 idiom the sibling `increase_phone_number_length_in_otps_table` uses.
 *
 * Why raw ALTER and not Blueprint->change(): RV-40 proved ->change() routes through
 * Doctrine DBAL, which aborts on exotic columns; `otps.type` is an ENUM on this very
 * table, so ->change() is the wrong tool here regardless of the geometry lesson.
 *
 * Existing plaintext rows are NOT rehashed — they are minutes-old single-use codes that
 * expire almost immediately, so no live code is orphaned by the switch (a stale row's
 * digest simply won't match, and the wrong-code path already records an attempt). The
 * change is lossless and additive; down() restores the original 6-char width.
 */
return new class extends Migration
{
    public function up(): void
    {
        // T3-6: raw MySQL-only statement(s) below. Skip cleanly on other drivers.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::select(
            "SELECT CHARACTER_MAXIMUM_LENGTH l FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otps' AND COLUMN_NAME = 'otp_code'"
        )[0] ?? null;

        if ($col === null || (int) $col->l >= 64) {
            return; // table absent, or already widened — idempotent
        }

        DB::statement('ALTER TABLE otps MODIFY COLUMN otp_code VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE otps MODIFY COLUMN otp_code VARCHAR(6) NOT NULL');
    }
};
