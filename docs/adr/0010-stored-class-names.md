# 0010 - Stored class names: new rows keep the module namespace, readers accept both, nothing is pinned

**Status:** decision PARKED (owner, 2026-11-19). The note records the behaviour that ships today and what an
un-parking would mean; no code changes with this ADR.

## Context

One database column stores a PHP class name as its value: `score_transactions.reference_type`, written by
`Ride\Services\ScoreLedger::resolveReference()` as `get_class($reference)`, else `Booking::class`, else
`Ride::class` (Stage 2 closing verification, audit sec 377 step 5 - the full inventory lives there).

Stage 2 moved those classes from `App\Models\Ride` / `App\Models\Booking` into `App\Modules\Ride\Models\...`, so:

- rows written BEFORE the move hold the old name (`App\Models\Ride`);
- rows written AFTER it hold the new one (`App\Modules\Ride\Models\Ride`);
- a production table therefore holds BOTH, permanently, unless something rewrites it.

All three readers (`ScoreController`, `ScoreTransactionResource`, `ScoreHistoryService`) go through
`class_basename()`, so both values resolve. No query, report, export, raw SQL or test fixture compares a literal
old name - measured, not assumed. The only visible consequence is that `ScoreHistoryService` groups by the raw
value, so a user with pre- and post-move rows is hydrated in two queries instead of one: same output, one extra
query on that one screen, until old rows age out.

## Decision

**New rows keep writing the class name of the class as it exists today** (the module namespace). Readers keep
accepting both values through `class_basename()`. **No value is pinned back to the pre-Stage-2 name, no mapping
table is introduced, and no backfill is run.**

The owner left the alternative open rather than rejected: PIN-OLD was the option "new rows keep writing the
pre-Stage-2 value and readers accept both", and it remains available if a future client ever keys off the literal
`reference_type` value. It would be implemented in exactly one place - `ScoreLedger::resolveReference()` - plus a
single value-to-class map, with `Relation::morphMap` only if the column ever became a true Eloquent morph. It is
not worth doing today: nothing reads the literal value, and a pinned name would be a lie about where the class
lives.

## Consequences

- The rule for any future model move is: **check this inventory again** (the grep over every committed state
  snapshot for a `"App\\` value is the cheap way), because the column is invisible to every import ratchet.
- A future client that needs one canonical value should get it via the API (which already prints the basename),
  not by reading the column.
- If the parked decision is ever taken, it changes the write side only - readers already accept both, which is
  what makes the decision reversible at any time without a migration.