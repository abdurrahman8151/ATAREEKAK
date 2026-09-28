<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T2-11 — staff/admin refresh tokens were stored in PLAINTEXT (Str::random(64)
 * written verbatim and looked up by equality), whereas the user path stores only
 * a SHA-256 digest. A refresh token is a 30-day bearer credential that mints
 * fresh access tokens, so any database disclosure (dump, replica read, backup,
 * or the phpMyAdmin surface in T2-5) handed over directly usable admin/staff
 * sessions with nothing to crack.
 *
 * StaffJwtService now hashes on write and looks up by digest (mirroring
 * JwtService), so every pre-existing row — which holds a raw secret — must be
 * retired. This migration DELETES those rows rather than hashing them in place.
 *
 * Why delete and not re-hash in place: re-hashing a possibly-already-leaked
 * plaintext token produces a digest that the SAME leaked raw value still matches
 * (raw -> hash -> lookup hit), so an attacker holding a dumped token would keep
 * a valid session. Deleting forces every outstanding staff/admin session to
 * re-authenticate, which is the only option that actually closes the exposure.
 * The cost — staff and admins sign in once more — is the intended invalidation.
 *
 * The column is unchanged: staff_refresh_tokens.token is varchar(64), exactly the
 * length of a lowercase hex sha256 digest, so no widen step is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Purge every legacy staff/admin refresh token. Outstanding sessions
        // are invalidated by design; users simply log in again.
        DB::table('staff_refresh_tokens')->delete();
    }

    public function down(): void
    {
        // Irreversible: the raw tokens were the whole point of removing them and
        // are not recoverable, and re-plaintexting new rows would re-open T2-11.
        // Intentionally a no-op so a rollback never re-introduces plaintext storage.
    }
};
