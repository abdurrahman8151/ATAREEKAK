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

The map those decisions produce is in [../ARCHITECTURE.md](../ARCHITECTURE.md).
