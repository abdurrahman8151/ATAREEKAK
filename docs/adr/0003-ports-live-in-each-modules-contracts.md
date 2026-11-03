# 0003 - A module's ports live in its own `Contracts` namespace

**Status:** accepted (stage 1 phase 1(b), 2026-11; enforced by `ModuleBoundaryTest`)

## Context

Before the split, every port lived in `app/Interfaces/` - one flat directory for the whole application.
That was better than concrete coupling, but it had two problems:

1. `app/Interfaces` belonged to nobody. A port could be added there by anyone touching anything, and
   nothing said which module was answerable for keeping it honest.
2. It could not express direction. `Ride` importing `App\Interfaces\PaymentStrategyFactoryInterface`
   *looks* like importing the kernel, so a boundary test could not tell a sanctioned dependency
   inversion from a boundary violation.

## Decision

Each module publishes its ports in `app/Modules/<Module>/Contracts/`, and binds them to its own
implementations in `app/Modules/<Module>/Providers/<Module>ServiceProvider.php`. A module may name
another module's `Contracts` freely - that is the sanctioned edge - and may never name its
implementation. `app/Interfaces` is empty and `ModuleBoundaryTest` fails if a port reappears there.

A port is owned by the module that *provides* the capability, not the one that consumes it:

- `Money/Contracts/PassengerWalletChargerInterface` - Money charges wallets; Identity's profile
  controller consumes it.
- `Identity/Contracts/OtpMailInterface` - Identity owns OTP; the kernel mailer implements it, and the
  *binding* lives in the kernel catalogue because the implementation is a kernel class (ADR 0004's
  spirit: the kernel wires what it owns).
- `Console/Contracts/EmployeeManagementInterface` - the back office owns employees; Communication's
  contact endpoint consumes it.

When a consumer needs a capability that has no port, the fix is a new port in the provider's
`Contracts`, not a direct import. Stage 1.5 item 3 applied exactly that rule to six production
entrypoints (four artisan commands and the two JWT middleware) and the allowlist of files allowed to
name module implementation code went from twelve to **zero**.

## Consequences

- `Ride -> Money` is now a *visible* edge in `ModuleGraphTest`, not an invisible import of "the kernel".
- A port's docblock is written by the module that has to keep the promise, which is why the Contracts
  docblocks name the consumers by path.
- Widening a port is a design decision for that module. The audit parked one such widening (the Ride
  no-show penalty surface, sec 244) instead of publishing it for a file move's convenience.
- Two ports can look identical and still be separate (`UserTokenCleanupInterface` and
  `StaffTokenCleanupInterface`): separate secrets, separate tables, separate `ver` claims. Merging them
  for tidiness would blur a security boundary to save an interface.

## Alternatives rejected

- **Keep `app/Interfaces` as a shared kernel namespace.** It hides direction (see above) and gives no
  owner. Stage 1 phase 1(b) moved 34 ports out of it; the directory is now empty by ratchet.
- **Ports in the consumer's module.** Tempting (the consumer defines what it needs), but it inverts the
  dependency the wrong way round: the provider would have to import the consumer to implement the
  interface, and every new consumer would need the provider to change. It also lets a consumer define a
  port whose shape suits it and nobody else - exactly the drift the graph test now catches.
- **Concrete type-hints with "just be careful".** That is what the audit found: `BookingService`
  type-hinting `PaymentStrategyFactory`, `PasswordResetRepository` type-hinting `JwtService`. Care was
  not enough.
