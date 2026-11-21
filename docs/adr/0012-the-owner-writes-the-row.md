# 0012 - The owner of a row is the only writer of it

**Status:** accepted (stage 4 A1/A2, 2026-11-20). Extends [0006](0006-strict-data-ownership-schema-frozen.md)
(which fixed who owns a TABLE) to who may WRITE a row.

## Context

The ownership ratchets all measured what a module NAMES. `ModelOwnershipTest` knows which module owns which table and
refuses a module that imports another module's model; `ModuleBoundaryTest` refuses a module that names another
module's implementation. Both were green while `Money\Services\BookingCharge::snapshotBookingCharge()` force-filled
Money's money snapshot onto a **Ride-owned** booking row - because it did it through `BookingView`, a contract Money is
allowed to name. The write was invisible.

The stage 4 A1 census looked for writes instead of names and found **exactly one** site in the whole module tree
(`DB::table(...)` writes on foreign tables: none; static writes on foreign models: none; writes through a foreign
view: that one). A2 fixed it by moving the WRITE to the owner: Money computes the four values and returns them, and
Ride's `BookingEscrowWriter::recordMoneySnapshot()` - behind Ride's own `BookingEscrowLedger` port - performs the same
single UPDATE, on the same model, in the same transaction.

## Decision

**The module that owns a table is the only module whose code writes that table.** A module that needs a row written
does one of two things:

1. returns the DATA to its caller (a DTO) and lets the owner persist it; or
2. calls a WRITE METHOD on a port the owner publishes (`BookingEscrowLedger::recordMoneySnapshot()`,
   `ComplaintRecorder::record()`, `WalletTransactionRecorder`), which the owner implements on its own model.

It may never write the row itself - not through a model import, not through `DB::table()`, and explicitly **not
through a read-only view contract or a relation result it was handed**. The type of the parameter is not the permission.

This is enforced by `tests/Feature/AppFuture/ForeignWriteTest.php` + `Tests\Support\Boundary\ForeignWriteScanner`:
the census is declared data (currently **empty**), a new foreign write fails the build, and the scanner is proven by
coverage (it must walk at least 250 module files) and by fixture needles in `tests/Fixtures/foreign-writes/` - a
synthetic write through a view, a raw `DB::table()` write and a static model write are each detected, while a
read-only consumer and the owner writing its own row are not.

## Consequences

- A cross-module write now fails at the RATCHET, not at the first bad row in production - which is what the old
  comment on that method said it was, for two weeks, while the method did it anyway.
- The fix for a needed write is a method on the owner's port, and the method's docblock must say what it writes and
  why the owner is the one writing it. `Escrow`-family writes already worked this way (the guarded decrement); this
  ADR is the rule behind them.
- The one place the rule is deliberately not enforced is `app/Console/Testing/` (ADR 0005): the dev-only tools
  fabricate rows in every table to make a scenario, and they are loaded only in local/testing.
- A DTO is the preferred carrier: `BookingMoneySnapshot`, `SupportDeskAgent` and the identity `UserSummary` family
  exist so a caller can have the DATA without the write.