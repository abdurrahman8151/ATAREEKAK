<?php

namespace Tests\Support\Snapshots;

use stdClass;

/**
 * The single normaliser every API snapshot goes through.
 *
 * A snapshot is only useful if it is deterministic. Three things in every API response are not:
 *
 *   1. **ids** - auto-increment values depend on how many rows earlier tests committed. Replaced
 *      by the key that carries them (`id`, `ride_id`, ... -> "<id>"), never by their value, so a
 *      response that stops carrying a relation is still caught.
 *   2. **timestamps** - `now()` at request time. Replaced by "<timestamp>".
 *   3. **tokens** - JWTs and opaque refresh tokens are freshly minted per call. Replaced by
 *      "<token>".
 *
 * Two further value-level substitutions keep otherwise-deterministic fixtures from leaking
 * randomness into a snapshot: e-mail addresses and long random identifiers (hex/alnum strings of
 * 32+ characters that are not already covered by a key rule).
 *
 * Deliberately NOT normalised: money amounts, counts, statuses, messages, enum values, Arabic
 * text, and the RELATIVE equality of ids (two fields both carrying an id both become "<id>"; the
 * snapshot cannot tell whether they were the same id). That last gap is closed where it matters
 * by the property tests in tests/Feature/Ledger, which compare real ids.
 */
final class SnapshotNormaliser
{
    /** Keys whose value is an id, not a number with meaning. */
    private const ID_KEYS = [
        'id',
        'user_id',
        'driver_id',
        'passenger_id',
        'ride_id',
        'booking_id',
        'wallet_id',
        'sender_id',
        'receiver_id',
        'conversation_id',
        'message_id',
        'complaint_id',
        'photo_id',
        'employee_id',
        'rated_user_id',
        'rater_id',
        'rated_by',
        'rated_by_id',
        'booking_ids',
        'ids',
        'wallet_number',
        'transaction_id',
        'created_by',
        'created_by_id',
        'updated_by',
        'updated_by_id',
        'posting_key',
        'idempotency_key',
        'reference',
        'reference_id',
    ];

    /** Keys whose value is a wall-clock time. */
    private const TIMESTAMP_KEYS = [
        'timestamp',
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'departure_time',
        'arrival_time',
        'finished_at',
        'completed_at',
        'confirmed_at',
        'cancelled_at',
        'passenger_confirmed_at',
        'driver_confirmed_at',
        'expires_at',
        'read_at',
        'sent_at',
        'last_seen_at',
        'date',
        'day',
        'last_login_at',
        'banned_at',
        'time_only',
        'date_only',
        'time_human',
    ];

    /** Keys whose value is a credential. */
    private const TOKEN_KEYS = [
        'token',
        'access_token',
        'refresh_token',
        'push_token',
        'fcm_token',
        'device_token',
        'secret',
        'otp',
        'otp_code',
    ];

    public static function normalise(mixed $value): mixed
    {
        return self::walk($value, null);
    }

    private static function walk(mixed $value, ?string $key): mixed
    {
        if ($key !== null) {
            $rule = self::ruleFor($key);

            if ($rule !== null) {
                return $rule;
            }
        }

        if ($value instanceof stdClass) {
            $out = new stdClass;

            foreach (get_object_vars($value) as $childKey => $childValue) {
                $out->{$childKey} = self::walk($childValue, (string) $childKey);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $childKey => $childValue) {
                // Lists carry no key of their own; their elements are judged on their own keys
                // (or on their scalar shape).
                $out[$childKey] = is_int($childKey)
                    ? self::walk($childValue, null)
                    : self::walk($childValue, (string) $childKey);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::normaliseScalar($value);
        }

        return $value;
    }

    private static function ruleFor(string $key): ?string
    {
        $lower = strtolower($key);

        if (in_array($lower, self::TOKEN_KEYS, true) || str_ends_with($lower, '_token')) {
            return '<token>';
        }

        if ($lower === 'password' || str_contains($lower, 'password') || str_contains($lower, 'secret')) {
            return '<redacted>';
        }

        if (in_array($lower, self::TIMESTAMP_KEYS, true) || str_ends_with($lower, '_at')) {
            return '<timestamp>';
        }

        // Human-readable renderings of a timestamp (`departure_time_human`). They are derived from
        // the same clock instant, so a minute of drift between the generating run and the verifying
        // run turned into a snapshot diff. The machine-readable field next to them stays normalised
        // as a timestamp; the display copy adds nothing a snapshot can police.
        if (str_ends_with($lower, '_human') || str_ends_with($lower, '_formatted')) {
            return '<timestamp>';
        }

        if (in_array($lower, self::ID_KEYS, true) || str_ends_with($lower, '_id') || str_ends_with($lower, '_ids')) {
            return '<id>';
        }

        // Human-facing references derived from a row id (`#DR-1698`, `#TR-471`). They carry the
        // auto-increment value, so they move with every row an earlier test committed.
        if (str_ends_with($lower, '_ref')) {
            return '<id>';
        }

        return null;
    }

    private static function normaliseScalar(string $value): string
    {
        // A timestamp EMBEDDED in a longer string. The escalation log is the live example:
        // "[ESCALATED by Snapshot Staff at 2026-10-31 16:08:47]\n<reason>" - the clock inside the
        // notes changed between the generating run and the verifying run. Any occurrence of the
        // MySQL DATETIME form is replaced; the rest of the sentence stays compared byte-for-byte.
        if (preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1) {
            $value = (string) preg_replace(
                '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?/',
                '<timestamp>',
                $value
            );
        }

        // ISO-8601 (Laravel's default model serialisation) and the MySQL DATETIME form.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
            return '<timestamp>';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return '<date>';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return '<email>';
        }


        // JWTs and opaque 64-character refresh tokens.
        if (preg_match('/^[A-Za-z0-9\-_]{32,}$/', $value) === 1 && preg_match('/\d/', $value) === 1) {
            return '<token>';
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return '<uuid>';
        }

        // A stored upload: the filename is generated per request (Str::random), so the URL is not
        // stable across runs. Only a random-looking basename is replaced; a fixture with a
        // deliberate name ("documents/snapshot-face_id.jpg") keeps its value in the snapshot.
        if (preg_match('#^(?:https?://[^/]+)?/?[a-z0-9_\-/]*/([A-Za-z0-9]{16,})\.(?:jpe?g|png|gif|pdf|webp)$#i', $value) === 1) {
            return '<file>';
        }

        // A paginator URL carries the row id of the resource in its PATH
        // (".../api/admin/wallet/2011/transactions?page=1"), so the same id that becomes "<id>" in
        // the body stayed literal inside first_page_url / last_page_url / path / links[].url. Only
        // the path is rewritten: the query string ("?page=1") is a real value and stays compared.
        // This runs AFTER the upload rule, because a file URL also has a path and must not be
        // reduced to a bare id rewrite.
        if (preg_match('#^https?://#i', $value) === 1) {
            $withoutQuery = $value;
            $query = '';

            if (str_contains($value, '?')) {
                [$withoutQuery, $rest] = explode('?', $value, 2);
                $query = '?'.$rest;
            }

            return (string) preg_replace('#/\d+#', '/<id>', $withoutQuery).$query;
        }

        return $value;
    }
}
