<?php

namespace App\DTOs\Auth;

use App\Models\Profile;
use App\Models\User;

/**
 * Decision un11 (owner, 2026-10-02): the auth cache must not hold a password hash.
 *
 * THE FINDING (R2 sec 30 item 3, RV-29). `JwtService::findUserCached()` cached the whole
 * `User` Eloquent model. `Cache::remember` SERIALISES whatever it is given, so every bcrypt
 * `password` hash for every active user was written into the cache backend (Redis) in plain
 * serialized form, for the full 5-minute TTL, on every authenticated request that missed. The hash
 * is a credential: anything that can read the cache - a compromised Redis, a snapshot, a slow-log
 * dump, a `KEYS` in a shared instance - has the user's password hash for offline cracking. The
 * application never needs it: no code reads `$request->user()->password` (verified below), because
 * every `Hash::check` in the app operates on a FRESHLY queried model (LoginController,
 * ResetPasswordController) or on the separate `Employee` model.
 *
 * WHY A DTO AND NOT JUST A SELECTED COLUMN LIST. Selecting fewer columns does not help: the model
 * is still hydrated with `password => null`, so `$user->password` would silently read null and any
 * future `Hash::check` would compare against null and always fail. Worse, the audit recorded a
 * concrete data-loss hazard: the middleware's temporary-ban auto-lift calls `$user->update([...])`
 * on the CACHED instance, and an update on a model whose attributes were quietly stripped can write
 * the stripped columns back. Storing an explicit, narrow shape and REBUILDING the model from it
 * makes both impossible: there is no `password` key in the payload to write back, and the rebuilt
 * model's missing attribute raises loudly under the armed `preventAccessingMissingAttributes` flag
 * instead of reading null.
 *
 * WHAT IS DELIBERATELY ABSENT. `password` only. Everything the middleware and the app actually read
 * off `$request->user()` is carried: identity, status, the ban fields the auto-lift needs,
 * `token_version` for revocation, the verification flags, and the `profile` relation (which the
 * middleware and many controllers read).
 */
final class CachedUser
{
    /**
     * @param  array<string, mixed>  $attributes  the user's columns EXCLUDING `password`
     * @param  array<string, mixed>|null  $profile
     */
    public function __construct(
        public readonly array $attributes,
        public readonly ?array $profile = null,
    ) {}

    /**
     * Build the cache payload from a freshly-read model, or null if the user does not exist.
     *
     * The null case matters: the middleware answers a deleted/unknown `sub` with a clean
     * 401 USER_NOT_FOUND. Turning it into an exception here would return a 500 instead, so the
     * "no such user" answer is preserved exactly as before this refactor.
     */
    public static function fromOptionalModel(?User $user): ?self
    {
        return $user ? self::fromModel($user) : null;
    }

    /** Build the cache payload from a freshly-read model. */
    public static function fromModel(User $user): self
    {
        // `getAttributes()` is the raw column set; unset the credential rather than trying to
        // remember every other sensitive-ish column, so a future column is cached by default and the
        // one field that must never be cached is explicitly removed.
        $attributes = $user->getAttributes();
        unset($attributes['password']);

        $profile = null;
        if ($user->relationLoaded('profile') && $user->profile !== null) {
            $profile = $user->profile->getAttributes();
        }

        return new self($attributes, $profile);
    }

    /**
     * Rebuild the model the rest of the application expects.
     *
     * `forceFill` because these columns are not (and must not be added to) `$fillable`. `password`
     * is simply absent: any consumer that needs it gets a loud MissingAttributeException in
     * dev/test rather than a silent null, and in production the column is never re-read from the
     * model at all.
     */
    public function toUser(): User
    {
        $user = new User;
        $user->forceFill($this->attributes);
        $user->exists = true;
        $user->setRawAttributes($this->attributes, true);

        if ($this->profile !== null) {
            $profile = new Profile;
            $profile->forceFill($this->profile);
            $profile->exists = true;
            $profile->setRawAttributes($this->profile, true);
            $user->setRelation('profile', $profile);
        } else {
            // Explicitly mark it loaded-and-null so a read of `$user->profile` returns null instead
            // of triggering a lazy load (which the armed lazy guard would reject).
            $user->setRelation('profile', null);
        }

        return $user;
    }
}
