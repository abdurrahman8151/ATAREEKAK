# 0002 - Boundaries are enforced by ratchets, not by convention

**Status:** accepted (stage 1, 2026-11; extended stage 1.5, 2026-11-03)

## Context

A folder move is a one-day change. Keeping the boundaries afterwards is the hard part: the first
convenience import of another module's service class re-creates the coupling the move was supposed to
remove, and nobody notices until the next audit. Documentation does not fail builds.

## Decision

Every rule about the module layout is a test, and every test fails in the direction the rule cares
about:

| Ratchet | What it refuses |
|---|---|
| `AppFuture/ModuleBoundaryTest` | a module naming another module's implementation; a kernel file naming one; an entrypoint naming one without being declared; the kernel's use of module Contracts growing past its ceiling |
| `AppFuture/CrossContextBoundaryTest` | any context pair importing more than its recorded baseline (all 0), with ports treated as shared |
| `AppFuture/BoundaryDependencyTest` | layer inversions (controller -> repository, model -> service, service -> controller, ...) |
| `AppFuture/DanglingImportTest` | an `App\...` name in the tree that resolves to no class, interface, trait or enum |
| `AppFuture/ModuleGraphTest` | a new module-to-module edge, a new port on an existing edge, a new cycle |
| `AppFuture/ProviderWiringTest` | a provider registration whose abstract or concrete does not exist, or does not resolve |
| `CommittedRowLeakGuardTest` | fixture rows escaping into the test database |
| ~20 review ratchets (`RV13`, `RV16`, `RV28`, `RV29`, `RV30`, `RV36`, `RV38`, `RV39`, `T3`, `T4`, ...) | the specific drift each one was written for |

Two properties matter more than the list:

1. **Baselines only shrink.** Each counts what it found, and the number in the test is a ceiling. A
   commit that lowers a number lowers the ceiling in the same commit; raising one is forbidden.
2. **Every scanner proves it read something.** A "no violations" assertion over an empty file list is
   the failure mode of every ratchet, and stage 1 moved 202 files - exactly the change that empties a
   stale glob. Each scanner asserts a floor on the files, tokens or rows it examined, and every floor
   has been proved load-bearing by pointing the scan at an empty directory in a scratch run and
   watching the test go red (audit sec 250).

## Consequences

- Renaming or moving a class is a mechanical change plus whatever the ratchets say; the build is the
  reviewer.
- The tests are load-bearing infrastructure: a broken scan fails loudly rather than passing silently,
  which is why the floors exist.
- Writing a new module means writing its entry in the graph and its floor in the boundary test - a
  deliberate speed bump.
- Some ratchets encode history (`ModuleBoundaryTest` lists the cycles that could not be cut, with the
  reason). That is the point: the reason lives next to the rule, not in a wiki.

## Alternatives rejected

- **PHPStan/Psalm with a custom architecture ruleset, or deptrac.** A real option, and a better tool for
  the general case. Rejected here because the rules this codebase needs are specific and historical
  ("the kernel may name module Contracts but at most five files may", "these four cycles are declared,
  a fifth is a bug", "this directory is dev-only"), which is easier to express as a test that reads the
  tree than as a dependency-graph config - and because the existing ratchets already encode two years of
  incident-specific rules that would have to be ported.
- **Code review and a CONTRIBUTING section.** Does not fail builds.
- **Module-per-package with composer boundaries.** See ADR 0001.
