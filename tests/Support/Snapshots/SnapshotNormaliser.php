<?php

namespace Tests\Support\Snapshots;

use stdClass;

/**
 * The single normaliser every API snapshot goes through.
 *
 * A snapshot is only useful if it is deterministic. Three things in every API response are not:
 *
 *   1. **ids** - auto-increment values depend on how many rows earlier tests committed, and MySQL
 *      does not roll back the counter, so even the ORDER of the suite moves them. Replaced by the
 *      key that carries them (`id`, `ride_id`, ... -> "<id>"), never by their value, so a response
 *      that stops carrying a relation is still caught.
 *   2. **timestamps** - `now()` at request time. Replaced by "<timestamp>".
 *   3. **tokens** - JWTs and opaque refresh tokens are freshly minted per call. Replaced by
 *      "<token>".
 *
 * Two further value-level substitutions keep otherwise-deterministic fixtures from leaking
 * randomness into a snapshot: e-mail addresses and long random identifiers (hex/alnum strings of
 * 32+ characters that are not already covered by a key rule).
 *
 * TWO RULES THAT MAKE THE MASK HONEST (stage 0.5):
 *
 *   - **Only a non-null SCALAR is replaced.** A masked key that holds `null`, a boolean, an array or
 *     an object keeps its shape. Before this, `"id": null` became the string `"<id>"`, `"verified":
 *     true` under a `_at`-like key became `"<timestamp>"`, and `"booking_ids": [1,2]` collapsed to
 *     `"<id>"` - the snapshot then pinned a value the app never sent, and a real shape change (a
 *     list becoming a scalar, a flag becoming null) went unnoticed.
 *   - **ids and e-mails carry per-document ALIASES by first appearance** (`<id:1>`, `<id:2>`,
 *     `<email:1>`), assigned in a canonical traversal order (object keys sorted, arrays in their own
 *     order). Two fields that carry the SAME id get the SAME alias, so their equality is pinned;
 *     two fields that carry different ids get different aliases, so a response that starts mixing
 *     two users up is caught. Before this, every id was the bare string `"<id>"` and the snapshot
 *     could not tell "the same user" from "two different users".
 *
 * AN ALIAS IS SCOPED BY THE ENTITY IT IS AN ID OF, NOT BY ITS NUMBER ALONE (stage 0.5, step 4).
 *
 * The first version keyed the alias map by the raw NUMBER across the whole document, and that made
 * the committed corpus depend on the auto-increment COUNTERS: `rides.id` and `user_ratings.id` are
 * independent sequences, so in a young database they collide (`2` and `2`) and in a mature one they
 * do not. The suite's own history decides how far each counter has drifted, which means a partial
 * run - five modules instead of nine - rewrote thirteen snapshots that had been green for days, and
 * the step-8 order matrix could never pass. The map is now keyed by (entity, number):
 *
 *   - `ride_id` -> entity `ride`, `rated_user_id` -> `rated_user`, `booking_ids` -> `booking`;
 *   - a bare `id` -> the entity named by its ENCLOSING object or list (`data[].id` -> `data`,
 *     `data.wallet.id` -> `wallet`), so two entity kinds in one response cannot merge;
 *   - an id inside a URL path -> the segment before it (`/api/admin/wallet/45/transactions` ->
 *     `wallet`), which is what keeps "the wallet in the paginator URL is the wallet_id field" pinned.
 *
 * E-mails stay in one global namespace: an address identifies one account everywhere, so there is
 * nothing to collide.
 *
 * Known limit, stated plainly: a relation recorded under a different NAME than its owner
 * (`data[].id` for a ride, `ride_id` on its bookings) is now pinned separately, so a snapshot claims
 * "the same ride" only when both sides use the same entity name. The property tests in
 * `tests/Feature/Payment/LedgerInvariantsPropertyTest.php` compare real ids where that matters.
 *
 * Deliberately NOT normalised: money amounts, counts, statuses, messages, enum values, Arabic text.
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

    /** @var array<string, string> raw id value => alias */
    private array $idAliases = [];

    /** @var array<string, string> raw e-mail value => alias */
    private array $emailAliases = [];

    private int $idCount = 0;

    private int $emailCount = 0;

    public static function normalise(mixed $value): mixed
    {
        return (new self)->walk($value, null, null, '');
    }

    /**
     * @param  string|null  $family  'id' or 'email' when the parent key forces the family onto a
     *                               bare list element (`"booking_ids": [4, 7]`)
     * @param  string  $container  the key of the object or list this value sits in; it is what
     *                             a bare `id` is an id OF (`data[].id` -> `data`,
     *                             `data.wallet.id` -> `wallet`)
     */
    private function walk(mixed $value, ?string $key, ?string $family, string $container): mixed
    {
        $rule = $key === null ? null : self::ruleFor($key);

        if ($rule === null && $family === 'id') {
            $rule = '<id>';
        }

        // ONLY a non-null scalar is masked. null, booleans, arrays and objects keep their shape.
        if ($rule !== null && self::isMaskableScalar($value)) {
            return $this->mask($rule, $value, self::subjectFor($key, $container));
        }

        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            ksort($vars); // canonical order: alias numbering must not depend on hydration order

            $out = new stdClass;
            // A child's bare `id` belongs to THIS object: the object's own key is its entity name.
            $childContainer = $key ?? $container;

            foreach ($vars as $childKey => $childValue) {
                $out->{$childKey} = $this->walk($childValue, (string) $childKey, self::childFamily($rule), $childContainer);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];
            // Elements of a list inherit the list's key as their container, so every row of a
            // `data` array carries the same entity name.
            $elementContainer = $key ?? $container;

            foreach ($value as $childKey => $childValue) {
                // Lists carry no key of their own; their elements are judged on their own keys (or
                // on the family the parent key forced onto them).
                $out[$childKey] = is_int($childKey)
                    ? $this->walk($childValue, null, self::childFamily($rule), $elementContainer)
                    : $this->walk($childValue, (string) $childKey, self::childFamily($rule), $elementContainer);
            }

            return $out;
        }

        if (is_string($value)) {
            $valueRule = self::classifyScalar($value);

            if ($valueRule !== null) {
                return $this->mask($valueRule, $value, self::subjectFor($key, $container));
            }

            // A URL carries row ids in its path; they must become the SAME aliases the body uses.
            // Checked AFTER the upload rule (inside classifyScalar), because a file URL also has a
            // path and must not be reduced to a bare id rewrite.
            if (preg_match('#^https?://#i', $value) === 1) {
                return $this->normaliseUrl($value);
            }

            return self::replaceEmbeddedTimestamps($value);
        }

        return $value;
    }

    /**
     * The entity NAME an alias belongs to. Two ids share an alias only when they mean the same kind
     * of thing, so independent auto-increment sequences (a user id and a rating id that are both 2
     * in a young database) can never merge into one token.
     *
     * `ride_id` -> `ride`, `rated_user_id` -> `rated_user`, `booking_ids` -> `booking`; a bare `id`
     * -> the enclosing container (`data[].id` -> `data`, `data.wallet.id` -> `wallet`).
     */
    private static function subjectFor(?string $key, string $container): string
    {
        $name = strtolower($key ?? $container);

        if ($name === '') {
            return 'root';
        }

        if (str_ends_with($name, '_ids')) {
            return substr($name, 0, -4);
        }

        if (str_ends_with($name, '_id')) {
            return substr($name, 0, -3);
        }

        if ($name === 'id' || $name === 'ids') {
            $base = strtolower($container);

            if ($base === '') {
                return 'root';
            }

            // `{"booking_ids": [{"id": 9}]}`: the element's own key is the entity name.
            if (str_ends_with($base, '_ids')) {
                return substr($base, 0, -4);
            }

            if (str_ends_with($base, '_id')) {
                return substr($base, 0, -3);
            }

            // A collection is named in the plural ("wallet_transactions"), the reference that points
            // into it in the singular ("wallet_transaction_id"). Without this they would be two
            // namespaces and a ledger leg could not be tied to the transaction it belongs to.
            return self::singular($base);
        }

        return $name;
    }

    /**
     * The singular of a collection name, by the two rules that cover this API's naming: a trailing
     * `s` is dropped unless the word ends in `ss`, `us`, `is` or `ys` (address, status, analysis,
     * category-style plurals stay put).
     */
    private static function singular(string $name): string
    {
        if (strlen($name) < 3 || ! str_ends_with($name, 's')) {
            return $name;
        }

        foreach (['ss', 'us', 'is', 'ys'] as $keep) {
            if (str_ends_with($name, $keep)) {
                return $name;
            }
        }

        return substr($name, 0, -1);
    }

    /**
     * The family a masked key imposes on bare list elements under it. An id-family key holding
     * `[4, 7]` must alias its elements; a timestamp key holding a list keeps the elements' own
     * shapes (an array of timestamps is not something this app sends).
     */
    private static function childFamily(?string $rule): ?string
    {
        return $rule === '<id>' ? 'id' : null;
    }

    private static function isMaskableScalar(mixed $value): bool
    {
        return is_scalar($value) && ! is_bool($value) && $value !== null;
    }

    private function mask(string $rule, mixed $scalar, string $subject): mixed
    {
        if ($rule === '<id>') {
            return '<id:'.$this->aliasFor($this->idAliases, $this->idCount, $subject.'|'.(string) $scalar).'>';
        }

        if ($rule === '<email>') {
            // E-mails keep one global namespace: an address is one account everywhere, and two
            // accounts cannot share one, so there is nothing to collide.
            return '<email:'.$this->aliasFor($this->emailAliases, $this->emailCount, (string) $scalar).'>';
        }

        return $rule;
    }

    /**
     * The alias for a (subject, value) pair, assigned by first appearance in the canonical
     * traversal. The caller passes the composite key; see `subjectFor()` for why the number alone
     * is not enough.
     *
     * @param  array<string, string>  $aliases
     */
    private function aliasFor(array &$aliases, int &$count, string $value): string
    {
        if (isset($aliases[$value])) {
            return $aliases[$value];
        }

        $count++;

        return $aliases[$value] = (string) $count;
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

    /**
     * The value-level rule for a string that is not covered by its key, or null to keep it as sent.
     */
    private static function classifyScalar(string $value): ?string
    {
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

        // Anything else is kept as sent; a URL's path ids are handled by the caller, which has the
        // per-document alias map.
        return null;
    }

    /**
     * A timestamp EMBEDDED in a longer string. The escalation log is the live example:
     * "[ESCALATED by Snapshot Staff at 2026-10-31 16:08:47]\n<reason>" - the clock inside the notes
     * changed between the generating run and the verifying run. Any occurrence of the MySQL DATETIME
     * form is replaced; the rest of the sentence stays compared byte-for-byte.
     */
    private static function replaceEmbeddedTimestamps(string $value): string
    {
        if (preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) !== 1) {
            return $value;
        }

        return (string) preg_replace(
            '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?/',
            '<timestamp>',
            $value
        );
    }

    /**
     * A URL whose PATH carries row ids (".../api/admin/wallet/2011/transactions?page=1").
     *
     * The id's alias is scoped by the URL SEGMENT before it (`wallet`), which is exactly the entity
     * name a body field gets (`data.wallet.id` -> `wallet`). So "the wallet in the paginator URL is
     * the wallet_id field" stays pinned, while an unrelated table's id with the same number cannot
     * borrow the alias.
     */
    private function normaliseUrl(string $value): string
    {
        $withoutQuery = $value;
        $query = '';

        if (str_contains($value, '?')) {
            [$withoutQuery, $rest] = explode('?', $value, 2);
            $query = '?'.$rest;
        }

        return (string) preg_replace_callback(
            '#/([a-z0-9_\-]+)/(\d+)#i',
            fn (array $m): string => '/'.$m[1].'/<id:'.$this->aliasFor(
                $this->idAliases,
                $this->idCount,
                self::singular(strtolower($m[1])).'|'.$m[2]
            ).'>',
            $withoutQuery
        ).$query;
    }
}
