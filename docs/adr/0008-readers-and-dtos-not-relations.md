# 0008 - Readers and DTOs instead of relations and eager loads

**Status:** accepted (stage 2).

## Context

`ModelOwnershipTest` already forbids a module from importing another module's model. That leaves a hole: a
module could still get the data by relation (`$booking->ride->driver`) if the relation existed, or by a
`DB::table()` join, and it could still receive a model from another module's service and call `save()` on it.
A model that crosses a module boundary is a writable row leaving its owner.

## Decision

Cross-module reads go through a **Reader contract** in the owning module's `Contracts` namespace: a narrow,
verb-oriented interface (`WalletDirectory::forUser()`, `DriverEarningsReader::completedEarningsForDriver()`)
that returns a **DTO**, never a model. Where a list is involved the contract has a batched method
(`WalletDirectory::summariesFor()`) so switching from an eager load cannot introduce an N+1 - and the query
budgets in the snapshot suite fail the build if one appears.

Cross-module **writes** go through a recorder or a dedicated write port with one verb
(`WalletTransactionRecorder::record()`, `ComplaintRecorder::record()`, `AdminWalletCredit::credit()`), and the
body moves into the owning module rather than being re-implemented by the caller.

The rule for anything that changes a response: **the JSON stays byte-identical**, proved by the snapshot corpus,
and a characterization test is written first where the path has none.

## Consequences

- A DTO must carry every field the caller actually reads - no more (it would be a model in disguise) and no
  less (the caller would add a second lookup). `WalletSummary::balance` is typed `mixed` for exactly this
  reason: casting it to float changed `"1500.00"` into `1500` and broke a snapshot.
- Ports cost a contract, a binding and a wiring line per call site. The alternative is a module that can rewrite
  another module's rows behind a getter.
- Some ports are deliberately *not* pure: `WalletTransactionRecorder::record()` returns `mixed`, because its
  only used surface is `transaction_id`. Typing it would mean a DTO nobody needs or a model leaving the module.

## Alternatives rejected

- **Repositories returning models.** Cheaper, and a caller can call `save()`.
- **A shared "kernel models" namespace for everything.** That is where the project started; it is why twelve
  tables had no owner.
