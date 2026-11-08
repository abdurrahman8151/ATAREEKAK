# 0006 - Strict data ownership, with the schema frozen

**Status:** accepted (stage 2).

## Context

Twenty-two tables have exactly one module that understands them, but for most of the project's life every
module read every table it liked: Console reporting services queried `wallet_transactions` and `bookings`
directly, Ride wrote `complaints`, Identity touched `employees`. Nothing was wrong with any one of those
queries; the cost was that no table had an owner, so no invariant had a home. The ledger type spellings, the
`whereNull('user_id')` system-wallet boundary and the RV-39 duplicate earnings type were all enforced in
callers, which meant they were enforced nowhere.

The obvious remedy - introduce foreign keys, move tables into module schemas, rename things - was rejected:
this is a live system with production data, and a schema change would have turned a refactor into a
migration project with an unrecoverable failure mode.

## Decision

Every table has exactly one owning module. Only that module may name its Eloquent model, run SQL against its
table, or write to it. The schema is **frozen**: no migrations, no foreign-key changes, no table renames, and
`protected $table` is declared explicitly on every model so that moving a class between namespaces can never
silently change where its rows live.

Enforcement is mechanical, in `tests/Feature/AppFuture/ModelOwnershipTest.php`: which models a module may
import, which relations it may declare, which raw tables it may query, and which Console reporting files may
still touch foreign models (a named, shrink-only list). Ratchets only ever shrink; a commit that lowers one
records the new number in the audit.

## Consequences

- A question about a table now has one place to look. Moving a rule into its owner made the rules visible:
  the two escrow-release spellings and the RV-21 system-wallet guard are now documented beside the queries
  that implement them, not in a report that happened to need them.
- Cross-module work costs a contract. That is the point, but it is a real cost: a new admin screen that needs
  three tables now needs three reader methods.
- Two endpoints embed Eloquent rows in their JSON (`showWalletTransactions`, the wallet-request review list).
  Their payloads are pinned by snapshots, so retiring the last imports is a response-shape decision, not a
  refactor - it is parked with that reason rather than forced.

## Alternatives rejected

- **Foreign keys and enforced referential integrity.** Correct in a greenfield; a migration project here.
- **Separate schemas/databases per module.** Would have changed every connection and every join, for a
  boundary that ratchets already enforce in-process.
- **Convention plus review.** The ratchets exist because convention had already failed once.
