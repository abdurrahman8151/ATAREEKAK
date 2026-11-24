# 0013 - Module layering: the approved order, the ratchet, and the references that stay upward

**Status:** accepted (stage 5, owner decision D1 = APPROVED O1, 2026-11-24). Extends [0002](0002-boundaries-enforced-by-ratchets.md)
(the ratchet idea) and [0003](0003-ports-live-in-each-modules-contracts.md) (where a port lives).

## Context

After stage 4 the module graph had **14 directed edges, 64 port references, 4 two-cycles and 6 derived loops**. Stage 3
and stage 4 had both shown that a consumer-declared contract only FLIPS an edge - it moves the reference to the other
direction, it does not remove it - and that a real cut means one of two things:

- the lower module stops pulling a value and the upper module passes it in (a scalar, a DTO, an id); or
- the orchestration moves to the module that owns the decision.

Stage 5 asked for a total order of the five modules in which every dependency points DOWN, and for the cycles to be
removed by layering. The owner approved **O1** with amendments: report actual numbers, never move
`BookingEscrowLedger` or a route/response owner, describe the read-only view move as shared kernel vocabulary, and keep
the step gate.

## Decision

**The layer order, bottom to top, is `Communication < Identity < Money < Ride < Console`.** An edge `A -> B` (A names
B's contracts) is legal when A sits ABOVE B and is UPWARD when A sits below B. The order is declared in
`tests/Feature/AppFuture/ModuleLayeringTest::LAYERS`, and the classifier lives in
`Tests\Support\Boundary\LayeringScanner`, proven by a synthetic probe (a new upward edge is caught, a downward one is
not, a module the order does not place is reported).

**The upward references that exist are PINNED, shrink-only**, each with the reason it is still there:

| upward edge | refs | why it stays |
|---|---|---|
| `Identity -> Console` | 3 | `ComplaintDirectory` needs the admin passenger-profile endpoint moved (D2); `EmployeeRepositoryInterface` is load-bearing in the refresh REUSE-DETECTION branch of staff auth; `EmployeeTokenVersion` would change a stored value (the reset path bumps `token_version` twice today) |
| `Identity -> Money` | 4 | the withdrawal OTP/password checks and the top-up + wallet-panel reads: every removal moves a route or response owner (D2) |
| `Identity -> Ride` | 3 | the profile pages render Ride's score, stats and trips; the KYC decision is needed synchronously during ride creation (D2) |
| `Money -> Ride` | 1 | `BookingEscrowLedger`: the guarded decrement is part of Money's atomic settlement and the schema is frozen - the owner ruled it must stay (D2/D3) |

**What stage 5 actually removed**, with measured numbers:

| step | change | edges | references | cycles | loops |
|---|---|---|---|---|---|
| start | - | 14 | 64 | 4 | 6 |
| B1(b) | the two READ-ONLY views move into the shared kernel (`app/Contracts`) - shared vocabulary, not a dependency removal | 14 | 62 | 4 | 6 |
| B1(c) | the cash-ride count is passed in by Ride (which owns `rides`) | 14 | **61** | 4 | 6 |

**The graph is NOT acyclic, and this ADR says so.** Under the approved amendments no cycle can disappear: each one
keeps at least one reference in each direction, and two of them (`Identity <-> Money`, `Identity <-> Ride`) are
composed of ENDPOINTS whose responses render the other module's data - removing them means moving a route or response
owner, which D2 parks. The honest outcome of stage 5 is therefore: **a layering ratchet, 3 fewer references, and every
remaining upward reference pinned with its reason** - not the acyclic graph the phase originally aimed at.

## Consequences

- A new upward edge now fails `ModuleLayeringTest` before it can be declared in `ModuleGraphTest`, so the layering
  cannot rot by someone adding "one more port" in the wrong direction.
- A pinned line may only shrink: cutting one is part of a step and the pin is edited in the same commit, with the
  actual numbers reported.
- The order is the CONVERSATION baseline for future modules: a new module needs a layer before it needs an edge.
- Nothing about responses, status codes, auth rules, query counts or the schema changed for any of this; every step's
  snapshot corpus stayed byte-identical.