# 0011 - The auth cache carries the profile as DATA, and Identity rehydrates it

**Status:** accepted (stage 4 C2, owner decision D4 = AGENT, 2026-11-20). Amends
[0007](0007-user-is-the-authenticated-principal.md) and [0010](0010-stored-class-names.md) in no respect - it is about
the cache payload, not the principal or the stored names.

## Context

`CachedUser` (kernel, `app/DTOs/Auth/CachedUser.php`) is what the JWT middleware caches for five minutes instead of the
`User` model, because caching the model wrote every bcrypt hash into the cache backend (decision un11). Until stage 4
it also carried the `profile` RELATION: `CachedUser::fromModel()` read `$user->profile` (the kernel `hasOne Profile`),
and `toUser()` re-materialised a `Profile` model and attached it, so that `$request->user()->profile` worked for every
controller.

Stage 4 C2 moved `Profile` and `ProfileComment` into Identity. That removes two things at once: the kernel DTO may no
longer name the model (rule B), and the kernel `User` may no longer declare the relation (same rule). But
`GET /api/session/user` serializes the authenticated principal, so the profile object it prints is part of a FROZEN
response (D3 = NO): dropping it would be a contract change.

Three candidate homes were considered:

1. **Drop the profile from the cache** and have the session route read it - rejected: the route is in the kernel, and
   a kernel file naming Identity's `ProfileDirectory` spends the (shrink-only) kernel-contracts ceiling; a per-request
   extra read would also land on an endpoint whose budget is frozen.
2. **Keep a `Profile` model in the payload** - impossible: the DTO is kernel code and the model is Identity's.
3. **Carry the row as DATA, read by Identity, and re-materialise it as an Arrayable** - chosen.

## Decision

**Identity reads the profile and hands the kernel a plain column array; the DTO attaches it under the same `profile`
key as a Collection.**

- `JwtService` (Identity) fetches the principal AND its profile row through Identity's own `ProfileRepository`, on the
  cache-MISS path only, and passes the row's `toArray()` into `CachedUser`.
- `CachedUser` stores `?array $profile` and, in `toUser()`, attaches it with
  `setRelation('profile', collect($row))` - a Collection is `Arrayable`, so `relationsToArray()` keeps printing the
  same 20-key object. A user with no profile row attaches `null`, exactly as the retired eager load serialized it.
- **Nothing about the cache geometry changes**: same columns (minus `password`, as before), same 5-minute TTL, same
  key. A stale entry written by the previous release unserialises harmlessly - PHP ignores the extra property a class
  no longer declares, and the payload's keys are additive.
- **The kernel names no module class**: the array is data, the Collection is `illuminate/support`, and the only
  places that mention a profile MODEL are inside Identity.

## Consequences

- `$request->user()->profile` still resolves (to a Collection of the row), so a controller that renders the
  principal keeps its output; what it CANNOT do is save it - which is the point: the cache is not a write path.
- The three consumers that used to read the relation in earnest (the ride and booking resources' avatar fallback, the
  chat senders' photo, the ride-creation precondition) were converted to Identity's ports in the same commit, so no
  hot path depends on the cached relation at all.
- The ADR also records the boundary that was nearly crossed: a cached DTO that reconstructs a MODULE model is a
  kernel -> module reference even though no import appears; the test suite's `ModuleBoundaryTest` only sees imports,
  which is why this decision is written down rather than left to the code.