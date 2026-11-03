# 0001 - A modular monolith, not microservices

**Status:** accepted (stage 1, 2026-11; re-affirmed stage 1.5, 2026-11-03)

## Context

The codebase had grown one context per directory at best: `app/Http/Controllers`, `app/Services`,
`app/Repositories` and `app/Interfaces` each held code for rides, money, identity, communication and
the back office side by side, with no rule about who may call whom. Every read of the code was a
search, and every change to a money rule was a change to a file that also contained identity logic.

The two obvious directions were: split the application into services, or keep one deployable and make
the boundaries explicit inside it.

## Decision

One deployable Laravel application, with five modules under `app/Modules/{Communication,Money,Ride,Identity,Console}`,
a shared kernel (`app/Models`, `app/Enums`, `app/DTOs`, `app/Events`, `app/Exceptions`, `app/Mail`,
`app/Jobs`, `app/Listeners`, `app/Observers`, `app/Support`, `app/Services/File`, `app/Http` glue) and
one database. Modules talk to each other only through ports declared in the callee's
`Contracts` namespace (ADR 0003), and the boundaries are mechanically enforced (ADR 0002).

## Consequences

- One transaction can still span a ride, a wallet posting and a notification. The money paths depend on
  that: an escrow hold and its ledger posting must commit or roll back together. Microservices would
  have replaced those transactions with sagas and compensating writes, in the most money-adjacent code
  the project has.
- `composer install`, `phpunit` and `php artisan` stay one command each; CI is one matrix, not a
  compose file per service.
- The boundary is a *review* boundary, not a network one: nothing stops a developer from importing
  another module's class - except the ratchets, which fail the build when they do (ADR 0002).
- A module cannot be deployed or scaled on its own. That was accepted: the traffic profile is a single
  ride-hailing marketplace whose read-heavy endpoints are cached, and the queue already carries the
  slow work (push, mail, OTP).

## Alternatives rejected

- **Microservices or a service per context.** Rejected on transactionality (escrow + ledger + payout are
  one unit of money), on operational surface (five deploys, five migrations, five sets of secrets for a
  solo-maintained project), and because the boundaries were not yet *known* - the ports and the module
  map had to be discovered by moving code, which is precisely what a monolith with enforced seams lets
  you do cheaply.
- **No modules at all, just PSR-4 folders.** That was the status quo, and the audit found the same
  concepts implemented twice in different layers. Folders without a rule are a naming convention.
- **A package per module (composer path repositories).** Rejected as ceremony: it buys dependency
  direction enforcement that the ratchets already give, at the cost of five `composer.json` files and a
  repository-wide install dance for every rename.
