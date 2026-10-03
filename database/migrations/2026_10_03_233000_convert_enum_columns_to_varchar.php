<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decision 13 (owner, 2026-10-02, Option A): "PHP enums, drop DB ENUMs".
 *
 * WHY. The PHP enums are already the single source of truth in application code, but the database
 * was enforcing a *second, independent* copy of every value list. That duplication causes real
 * failures: adding a value to `BookingStatus` does nothing until someone remembers to write an
 * ALTER TABLE, and a row written by an older build silently truncates to '' under MySQL's strict
 * mode. The audit already recorded one such gap (`BookingStatus::tryFrom('no_show')` returning null
 * because the PHP enum never declared a value the DB already held). A varchar column removes the
 * second source of truth; the PHP enum keeps the type safety.
 *
 * SCOPE, MEASURED NOT GUESSED. 18 live ENUM columns across 11 tables, taken from
 * information_schema.COLUMNS. Two columns the audit expected are NOT enums and are therefore not
 * touched: `complaints.status`, `complaints.type` and `wallet_transactions.type` were already
 * converted to varchar by an earlier migration. The audit's "17" figure predates this task's own
 * `bookings.status` addition of `expired`, so the live count is the number that matters.
 *
 * EVERY DEFINITION IS PRESERVED EXACTLY - nullability, default and length are copied per column
 * from the live schema rather than assumed, because a MODIFY that drops a NOT NULL or changes a
 * default is a silent data-integrity regression.
 *
 * LENGTH: each varchar is sized to the longest value that column can hold (plus headroom), so no
 * existing row is truncated by the conversion.
 *
 * ROLLBACK IS FAIL-LOUD. `down()` rebuilds the original enums. If any row holds a value outside an
 * enum's set - which is exactly what widening to varchar makes possible - the ALTER would silently
 * coerce it to '' and corrupt the column. So `down()` checks first and throws with the offending
 * values instead. Losing the ability to roll back is better than a rollback that quietly destroys
 * data; the operator can then decide whether to drop the new value or extend the enum.
 *
 * Note: `rides.status`, `bookings.status` etc. are re-created here from the live value list. If a
 * later migration adds a value, THIS file's down() must be updated too - that coupling is called
 * out in the `down()` header rather than left to be discovered during an incident.
 */
return new class extends Migration
{
    /**
     * table => [ column => [ allowed values, nullable, default, varchar length ] ]
     *
     * Values/nullability/defaults are transcribed from information_schema on the scratch schema,
     * which was produced by running every migration in order.
     */
    private const COLUMNS = [
        'bookings' => [
            'status' => [['pending', 'confirmed', 'cancelled', 'no_show', 'completed', 'expired'], false, 'pending', 32],
        ],
        'employees' => [
            'role' => [['system_admin', 'admin', 'support_agent', 'sycash'], false, null, 32],
        ],
        'noshow_reports' => [
            'reporter_role' => [['driver', 'passenger'], false, null, 16],
            'status' => [['pending', 'resolved_reporter_wins', 'disputed', 'void'], false, 'pending', 32],
            'target_role' => [['driver', 'passenger'], false, null, 16],
        ],
        'otps' => [
            // MIXED CASE is intentional and must survive: these values are upper-case in the DB.
            'type' => [['E-PAYMENT', 'WALLET_CREATION', 'registration', 'login', 'password_reset', 'EMAIL_VERIFICATION'], false, null, 32],
        ],
        'photos' => [
            'type' => [['face_id', 'back_id', 'license', 'mechanic_card'], false, null, 32],
        ],
        'profiles' => [
            'gender' => [['M', 'F'], true, null, 8],
        ],
        'push_notification_tokens' => [
            'device_type' => [['android', 'ios', 'web'], false, 'android', 16],
        ],
        'rides' => [
            'booking_type' => [['direct', 'request'], false, null, 16],
            'payment_method' => [['cash', 'e-pay'], false, null, 16],
            'status' => [['active', 'full', 'cancelled', 'finished', 'awaiting_confirmation', 'launched'], false, 'active', 32],
        ],
        'users' => [
            'ban_type' => [['temporary', 'permanent'], true, null, 16],
            'gender' => [['M', 'F'], true, null, 8],
            'verification_status' => [['none', 'pending', 'rejected', 'approved'], false, 'none', 16],
        ],
        'wallet_requests' => [
            'status' => [['pending', 'approved', 'rejected', 'cancelled'], false, 'pending', 32],
            'type' => [['charge', 'withdraw'], false, null, 16],
        ],
        'wallet_transactions' => [
            'status' => [['pending', 'completed', 'failed', 'cancelled'], false, 'pending', 32],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => [$values, $nullable, $default, $length]) {
                $sql = sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` VARCHAR(%d) %s%s',
                    $table,
                    $column,
                    $length,
                    $nullable ? 'NULL' : 'NOT NULL',
                    $default === null ? '' : " DEFAULT '".$default."'"
                );

                DB::statement($sql);
            }
        }
    }

    /**
     * Throws rather than truncating when a value cannot be represented by the original enum.
     *
     * NOTE the coupling: if a LATER migration adds a value to one of these enums, this file's copy
     * must be updated too, or `down()` will refuse to roll back. That is the intended failure mode -
     * it is loud, and it is updated in the same commit that adds the value.
     */
    public function down(): void
    {
        // 1. Refuse up front if any column holds a value its enum cannot encode.
        $problems = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => [$values]) {
                $unexpected = DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, $values)
                    ->distinct()
                    ->limit(5)
                    ->pluck($column)
                    ->all();

                if ($unexpected !== []) {
                    $problems[] = sprintf('%s.%s holds %s', $table, $column, implode(', ', $unexpected));
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Refusing to roll back decision 13: the following columns hold values their original '
                ."ENUM cannot represent, and narrowing would silently truncate them to '':\n  - "
                .implode("\n  - ", $problems)
                ."\nResolve the data (or extend the ENUM in this migration) before rolling back."
            );
        }

        // 2. Rebuild each ENUM, restoring nullability and default exactly.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => [$values, $nullable, $default, $length]) {
                $list = implode(',', array_map(fn ($v) => "'".$v."'", $values));

                $sql = sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` ENUM(%s) %s%s',
                    $table,
                    $column,
                    $list,
                    $nullable ? 'NULL' : 'NOT NULL',
                    $default === null ? '' : " DEFAULT '".$default."'"
                );

                DB::statement($sql);
            }
        }
    }
};
