# Architecture decision records

One file per decision that shapes the module layout. They are short on purpose: context, the decision,
the consequences, and the alternatives that were rejected and why. Decisions that were superseded say so
in a line at the top rather than being rewritten.

| ADR | Decision |
|---|---|
| [0001](0001-modular-monolith-not-microservices.md) | A modular monolith, not microservices |
| [0002](0002-boundaries-enforced-by-ratchets.md) | Boundaries are enforced by ratchets, not by convention |
| [0003](0003-ports-live-in-each-modules-contracts.md) | A module's ports live in its own `Contracts` namespace |
| [0004](0004-jobs-listeners-notifications-stay-in-the-kernel.md) | Jobs, listeners and notifications stay in the kernel |
| [0005](0005-console-testing-directory-exemption.md) | `app/Console/Testing` is exempt as a directory, not per file |
| [0006](0006-strict-data-ownership-schema-frozen.md) | Strict data ownership, with the schema frozen |
| [0007](0007-user-is-the-authenticated-principal.md) | `App\Models\User` is the authenticated principal, not a module model |
| [0008](0008-readers-and-dtos-not-relations.md) | Readers and DTOs instead of relations and eager loads |
| [0009](0009-events-move-with-their-publisher.md) | Domain events move with their publisher; jobs and listeners stay in the kernel |
| [0010](0010-stored-class-names.md) | Stored class names: new rows keep the module namespace, readers accept both (decision PARKED) |
| [0011](0011-auth-cache-profile-as-data.md) | The auth cache carries the profile as data; Identity rehydrates it |
| [0012](0012-the-owner-writes-the-row.md) | The owner of a row is the only writer of it |

The map those decisions produce is in [../ARCHITECTURE.md](../ARCHITECTURE.md).
