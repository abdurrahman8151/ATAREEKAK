# 0007 - `App\Models\User` is the authenticated principal, not a module model

**Status:** accepted (stage 2).

## Context

`User` is the one table everybody needs and nobody owns. It is the authenticated principal (every middleware,
every policy, every `$request->user()`), and it is also the parent of fourteen relations that other modules care
about: a user has a wallet, rides, bookings, conversations, notifications, ratings.

If Identity owned `User` like any other model, every module that types a controller against the principal would
have to import Identity's model - which would mean the kernel depends on a module, and the module dependency
graph would gain an edge from the framework's auth layer into business code.

## Decision

`App\Models\User` stays in the kernel, and modules may import it **as the authenticated principal type only** -
that is, as a parameter, a return type or a request attribute. Reading user *data* from another module goes
through Identity's `UserDirectory` returning a `UserSummary` DTO.

The `User` model's reverse relations (`wallet`, `rides`, `bookings`, `conversations`, `sentMessages`,
`notifications`, `userNotifications`, `pushTokens`) are removed: they were each a module's table exposed on a
kernel class. Relations that *point at* a user from a module model stay, because those belong to the module's
own table.

## Consequences

- The kernel does not depend on any module for its auth path.
- A module that needs, say, a passenger's name asks Identity for it, batched by id where a list is involved.
  This is more work than `->with('user')` and it is the only shape that keeps the principal exception narrow.
- The exception is narrow enough to be checked: `ModelOwnershipTest` allows `User` as an import and nothing
  else from the kernel, so a module that starts importing a second kernel model fails.

## Alternatives rejected

- **Move `User` into Identity and alias the kernel.** The kernel would need the module to authenticate anyone.
- **Leave the reverse relations and allowlist them.** They are the largest single source of cross-module model
  imports in the codebase; allowlisting fourteen relations would have made the ratchet decorative.
