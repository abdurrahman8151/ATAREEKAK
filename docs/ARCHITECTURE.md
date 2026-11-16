# Architecture

One Laravel application, five modules, a shared kernel, and a set of tests that refuse to let the
boundaries rot. This document is the map; the decisions behind it are in [`adr/`](adr/README.md).

Numbers in this file were measured on **2026-11-03** (stage 1.5). Where a number is a ceiling, the test
that holds it is named next to it, so the number is never the source of truth - the ratchet is.

- [1. The shape](#1-the-shape)
- [2. The kernel](#2-the-kernel)
- [3. The modules](#3-the-modules)
- [4. The module graph](#4-the-module-graph)
- [5. Ports](#5-ports)
- [6. The ratchets](#6-the-ratchets)
- [7. Entrypoints and the dev-only directory](#7-entrypoints-and-the-dev-only-directory)
- [8. Where does a new class go?](#8-where-does-a-new-class-go)
- [9. How this document can rot](#9-how-this-document-can-rot)

## 1. The shape

```
app/
  Modules/
    Communication/   19 files   5 ports   chat, push, notifications, mail transport
    Money/           35 files   8 ports   ledger, wallets, escrow, refunds, payouts, payment strategies
    Ride/            47 files   5 ports   booking and ride lifecycle, search, scoring, no-shows
    Identity/        59 files  17 ports   passenger and staff auth, tokens, OTP, KYC, profiles
    Console/         48 files   5 ports   the back office, employees, bans, support routing
  Models/ Enums/ DTOs/ Events/ Exceptions/ Jobs/ Listeners/ Mail/ Observers/ Support/ Services/
  Http/             the kernel: shared domain vocabulary and framework glue
```

`app/` is **359 PHP files**: 208 in modules, 151 in the kernel, 40 of the module files being ports.
`tests/` is 298 PHP files.

Why one deployable instead of services: the money paths need one transaction (an escrow hold and its
ledger posting commit or roll back together), and the boundaries were discovered by moving code, which
is cheap inside one process. See [ADR 0001](adr/0001-modular-monolith-not-microservices.md).

## 2. The kernel

The kernel is everything in `app/` that is not under `app/Modules/`. It holds what every module shares
and what the framework calls:

| Directory | Why it is shared |
|---|---|
| `Models/`, `Enums/`, `DTOs/`, `ValueObjects/`, `Exceptions/` | the domain vocabulary: `User`, `Ride`, `LedgerType`, `BookingStatus`. Moving one of these into a module would make every other module import that module to name a column. |
| `Events/`, `Jobs/`, `Listeners/`, `Observers/`, `Notifications/` | framework callback surfaces whose class names are stored in queue payloads - see [ADR 0004](adr/0004-jobs-listeners-notifications-stay-in-the-kernel.md). |
| `Mail/` | the mail transport; the boundary map puts it in the Communication context, but the classes stay in the kernel for the same queue-payload reason. |
| `Http/` | the base `Controller`, the middleware and the request/response glue the kernel itself must own. The *business* controllers live in modules. |
| `Providers/`, `Support/` | the application and container wiring: `AppServiceProvider`, and the binding catalogue `Support/ContainerBindings`. |
| `Services/File/FileUploadService.php` | see below. |

**`FileUploadService` stays in the kernel, at `app/Services/File/`.** It is a stateless file-storage
adapter (disk paths, names, MIME checks) that serves exactly two modules - Communication's
`ChatMessageHandler` and Identity's `ProfileUpdateService` - and it encodes no domain rule. Moving it
into either module would make the other import that module's implementation, i.e. a boundary break, and
publishing a port for it would mean a module owning a transport concern it does not own. Moving it to
`app/Support/File/` was considered and rejected: it is a rename with no behavioural gain, it would churn
three call sites and the ratchet floors, and `app/Services` would then be an empty directory pretending
to be an architectural statement. The rejected alternative worth naming is a `FileStorageInterface` port:
tests do not need it (`Storage::fake()` already isolates uploads), so it would be an interface with a
single implementation and no second consumer - decoration, not a boundary.

**The kernel may name module `Contracts`, but only in a bounded number of files.**
`ModuleBoundaryTest::KERNEL_CONTRACTS_CEILING = 5` and the five are the framework surfaces above:
`Jobs/SendPushNotification.php`, `Jobs/SendPushNotificationJob.php`, `Listeners/SendUserVerifiedNotification.php`,
`Mail/OtpMailer.php`, `Observers/UserObserver.php`. The ceiling only shrinks.

The kernel's own wiring may name a module port when the *implementation* is a kernel class -
`Support/ContainerBindings::registerKernelPortImplementations()` binds `Identity\Contracts\OtpMailInterface`
to `App\Mail\OtpMailer`. That binding used to live in Communication's provider, which made one module
import another module's `Contracts`; moving it to the kernel cut a cycle (section 4) and touched no
baseline, because the kernel is not a context.

## 3. The modules

| Module | Owns | Ports | Depends on (Contracts) | Depended on by |
|---|---|---|---|---|
| **Communication** | chat, push, notification fan-out, message types | 5 | Console (2) | Console (3), Identity (1), Money (1), Ride (1) |
| **Money** | ledger, wallets, escrow, refunds, payouts, cash fee, payment strategies | 8 | Communication (1), Identity (1) | Ride (5), Console (3), Identity (1) |
| **Ride** | booking and ride lifecycle, search, geocoding, score, no-shows | 5 | Money (5), Communication (1), Identity (1) | Console (2), Identity (2) |
| **Identity** | passenger and staff auth, tokens, OTP, KYC, profiles | 17 | Communication (1), Console (1), Money (1), Ride (2) | Console (6), Money (1), Ride (1) |
| **Console** | back office, employees, bans, support routing, moderation | 5 | Communication (3), Identity (6), Money (3), Ride (2) | Communication (2), Identity (1) |

Each module has its own `README.md` with its public ports, its consumers and what it may not do:
[Communication](../app/Modules/Communication/README.md), [Money](../app/Modules/Money/README.md),
[Ride](../app/Modules/Ride/README.md), [Identity](../app/Modules/Identity/README.md),
[Console](../app/Modules/Console/README.md).

A module's shape is consistent across the five:

```
Modules/<Name>/
  Contracts/      the published ports (the only thing another module may name)
  Domain/         pure domain types and policies, where a module has them (Money, Ride)
  Http/           the module's controllers
  Providers/      <Name>ServiceProvider - the module's bindings, registered from config/app.php
  Repositories/   persistence, where a module has it
  Services/       the implementation
```

## 4. The module graph

Measured from code only (comments and docblocks are stripped first) by
`tests/Feature/AppFuture/ModuleGraphTest.php`, which pins every number below as declared data.
Port counts are distinct Contracts names per pair; **every edge is a Contracts edge** - implementation
edges are 0, which is `ModuleBoundaryTest` rule A's job and is asserted again on this graph.

| from \ to | Communication | Money | Ride | Identity | Console |
|---|---|---|---|---|---|
| **Communication** | - | - | - | 0 | 2 |
| **Money** | 1 | - | - | 1 | - |
| **Ride** | 1 | 5 | - | 1 | - |
| **Identity** | 1 | 1 | 2 | - | 1 |
| **Console** | 3 | 3 | 2 | 6 | - |

14 directed edges. Four cycles close in it, and each is declared with the reason it could not be cut:

| Cycle | Why it stays |
|---|---|
| **Communication <-> Console** | Communication's `ContactController` (`POST /api/contact`) needs the least-loaded support agent *and* that agent's shadow user to build its response. An event cannot return the agent, and a kernel listener would be a sixth kernel file naming module Contracts (ceiling is 5, shrink-only). Moving the controller into Console would hand a passenger-facing endpoint to the back-office module. |
| **Console <-> Identity** | Console authenticates staff through Identity (`EmployeeAuthInterface`, `StaffJwtInterface`); Identity reaches Console for `EmployeeRepositoryInterface` because the stage 1 context map puts staff **authentication** in Identity and employee **records** in Console. Cutting it means moving one of those two, i.e. contradicting the map. |
| **Identity <-> Money** | OTP-verified withdrawal (Money -> Identity's `EmailOtpServiceInterface`) versus wallet top-up (Identity -> Money's `PassengerWalletChargerInterface`). Both need a return value; cutting either moves an HTTP endpoint between modules. |
| **Identity <-> Ride** | Driver-document checks at booking time (Ride -> Identity's `DocumentVerificationInterface`) versus ride stats and score on the profile page (Identity -> Ride's `ScoreServiceInterface`, `UserRideStatsInterface`). Same shape as Identity <-> Money. |

**One cycle was cut.** Communication -> Identity was exactly one line in the whole module tree: the
`OtpMailInterface` binding in `CommunicationServiceProvider`, placed there in stage 1 because mail
delivery is Communication's transport. A port is bound by the module that publishes it, so the binding
moved to the kernel catalogue (section 2); the edge is 0 and with it the `Communication <-> Identity`
cycle. The first attempt moved the binding into Identity's provider - `ModuleGraphTest` saw the cycle
gone, but `CrossContextBoundaryTest` saw a new `IDENTITY_to_COMM` import (`App\Mail\OtpMailer` is
Communication's context in the boundary map) against a baseline of 0. The fix would have been to raise a
baseline, which is forbidden, so it moved to the kernel instead: the kernel is not a context, so no
baseline and no edge moved.

The 16 longer loops in the graph are all consequences of those four mutual pairs; `ModuleGraphTest`
asserts that every longer loop passes through a declared pair, so a loop that is a genuinely new design
decision fails the test with its own name in the message.

## 5. Ports

A port is an interface in the provider module's `Contracts/`, bound to that module's implementation in its
provider. Consumers type-hint the port. A module may name any module's `Contracts` and may never name its
implementation. Full reasoning: [ADR 0003](adr/0003-ports-live-in-each-modules-contracts.md).

40 ports today. Adding one is a five-step change, and every step has a ratchet:

1. write the interface in the provider's `Contracts/`, with a docblock naming who consumes it and why it
   is shaped that way;
2. make the implementation declare it (`implements`);
3. bind it in that module's provider, to the same instance the concrete was (same lifetime - `bind` for
   `bind`, `singleton` for `singleton`);
4. switch the consumer to the port, and only the port;
5. run `ModuleGraphTest` - the new edge (or the new port on an existing edge) is declared data and needs
   its line updated with the reason. `ProviderWiringTest` will confirm the registration resolves.

Two rules of taste, learned the hard way in stage 1.5:

- **Do not merge ports to shorten a list.** `UserTokenCleanupInterface` and `StaffTokenCleanupInterface`
  are identical in shape and separate on purpose: separate secrets, tables and `ver` claims. A generic
  port would blur a security boundary to save an interface.
- **Do not publish a surface for a file move's convenience.** The Ride no-show penalty and confirmation
  calls stayed inside the module (audit sec 244) rather than becoming a broad port so that two dev tools
  could reach them.

## 6. The ratchets

Every architectural rule is a test. They fail in the direction the rule cares about, and their numbers
are ceilings: **baselines only shrink**, and lowering one is part of the commit that lowers the count.
Raising one is not a refactor.

| Ratchet | Rule | Baseline / ceiling |
|---|---|---|
| `ModuleBoundaryTest` | A: a module may name its own module, any `Contracts`, the kernel - never another module's implementation. B: a kernel file may name `Contracts`, never implementation. C: implementation-naming entrypoints must be declared (empty list) plus one directory exemption. D: kernel files naming module Contracts. E: per-module file floors. | A/B/C hard 0; D ceiling 5; per-module floors; `MIN_SCANNED_FILES = 353` |
| `CrossContextBoundaryTest` | every context pair's import count | all 0, floor 340 files |
| `BoundaryDependencyTest` | layer inversions inside the kernel (controller -> repository, model -> service, ...) | 8 rules at 0 + `models_to_enums` 3 |
| `ModuleGraphTest` | the module graph's edges, its port counts, its cycles | 14 edges, 4 declared cycles, floors 200/14/30 |
| `DanglingImportTest` | every `App\...` name in `app/`, `tests/`, `routes/`, `config/`, `database/`, `scripts/` resolves to a real class/interface/trait/enum | 0 dangling, floors 750 files / 2000 tokens |
| `ProviderWiringTest` | every abstract and concrete named in the five providers and `ContainerBindings` exists, is bound, resolves, and is the same instance for singletons | 77+ registrations, floor 70 |
| `CommittedRowLeakGuardTest` | no fixture rows survive into the test database | - |
| ~20 review ratchets (`RV*`, `T3*`, `T4*`) | the incident-specific drift each was written for | per test |

Two properties make them trustworthy rather than decorative:

- **They count what they scanned.** A "no violations" assertion over an empty file list is the classic
  false pass, and stage 1 moved 202 files - exactly the change that empties a stale glob. Every scanner
  asserts a floor on files, tokens or rows; stage 1.5 item 2 floored all 23 scanners and proved each floor
  by pointing the scan at an empty directory and watching the test go red (audit sec 250). One of those
  proofs found a real hole: `RV16`'s kernel glob had been matching zero files since the move.
- **They cross-check each other.** A name must resolve (`DanglingImportTest`) and must not cross a
  boundary (`ModuleBoundaryTest`), which is why historical notes in docblocks are written as paths
  (`app/Modules/...`) rather than FQCNs.

When a ratchet's rule is no longer the rule you want, the commit that changes it says so, in prose, in
the audit record. A ratchet is deleted only when its end state is reached, and the deletion is argued.

## 7. Entrypoints and the dev-only directory

An entrypoint is a file whose job is wiring or invocation: an artisan command, HTTP middleware, a
provider, `Support/ContainerBindings`, the HTTP/console kernels. Entrypoints may name module `Contracts`
freely; naming module *implementation* is what rule C counts.

- `ENTRYPOINT_ALLOWLIST` is **empty**. It held twelve files after stage 1; six were production
  entrypoints (four artisan commands and the two JWT middleware) and stage 1.5 item 3 gave each the port
  it needed, so the count is 0.
- `ENTRYPOINT_DIRECTORY_EXEMPTIONS` has one entry: `Console/Testing/`, the dev-only tools. What makes
  them safe is the directory's registration, not any individual command: `App\Console\Kernel::commands()`
  loads it only inside `if ($this->app->environment(['local', 'testing']))`, and
  `DevCommandRegistrationTest` pins that guard from both sides. See
  [ADR 0005](adr/0005-console-testing-directory-exemption.md).

## 8. Where does a new class go?

1. **A controller for a user-facing endpoint** -> the module that owns the resource, in
   `Modules/<M>/Http/Controllers`. The route file is `routes/api.php`; the route names the class.
2. **A domain rule** -> the module that owns the concept, in `Services/` (or `Domain/` when it is pure).
   It may name kernel models/enums/DTOs freely.
3. **Something two modules need** -> a port in the owning module's `Contracts` (section 5). If both
   modules need to *define* it, one of them owns it - usually the one that has to keep the promise.
4. **Something every module needs and that encodes no domain rule** -> the kernel, and then it is a
   `Support/` or `Services/File/` decision to be justified in the PR (and, if it is a port
   implementation, bound in `ContainerBindings`).
5. **A queued job, listener, notification or observer** -> the kernel, taking module capabilities
   through ports ([ADR 0004](adr/0004-jobs-listeners-notifications-stay-in-the-kernel.md)).
6. **A dev-only tool** -> `app/Console/Testing` (loaded only in local/testing), not `Commands/`.

Then run, in this order: `php -l` on changed files, `php vendor/bin/pint --test <files>`, the tests that
reference the changed symbols, the area floors (AGENTS.md has the map), and
`tests/Feature/AppFuture` - which is where a boundary mistake shows up first.

## 9. How this document can rot

The numbers above are measurements, and measurements go stale. The durable parts are the rules, and the
rules are executable. If this document and a ratchet disagree, **the ratchet is right**; update the
document in the commit that moved the number. The three places that will notice first:

- `ModuleGraphTest` - edges, port counts, cycles;
- `ModuleBoundaryTest` - file floors, the kernel Contracts ceiling, the entrypoint lists;
- `DanglingImportTest` - any name in this document that is written as an FQCN and no longer exists. That
  is why it is written as paths in the history notes.
## 10. The data-ownership map

Every table has exactly one owning module ([ADR 0006](adr/0006-strict-data-ownership-schema-frozen.md)).
The schema is frozen - no migrations, no foreign-key changes, no table renames - and every model declares
`protected $table` explicitly, so a class can move between namespaces without its rows moving.

| Owner | Model | Table | Class lives in |
|---|---|---|---|
| Communication | Conversation | `conversations` | `App\Modules\Communication\Models` (moved) |
| Communication | ConversationParticipant | `conversation_participants` | `App\Modules\Communication\Models` (moved) |
| Communication | Message | `messages` | `App\Modules\Communication\Models` (moved) |
| Communication | Notification | `notifications` | `App\Modules\Communication\Models` (moved) |
| Communication | UserNotification | `user_notifications` | `App\Modules\Communication\Models` (moved) |
| Communication | PushNotificationToken | `push_notification_tokens` | `App\Modules\Communication\Models` (moved) |
| Money | Wallet | `wallets` | `App\Models` (pending) |
| Money | WalletRequest | `wallet_requests` | `App\Models` (pending) |
| Money | WalletTransaction | `wallet_transactions` | `App\Models` (pending) |
| Money | LedgerEntry | `ledger_entries` | `App\Models` (pending) |
| Ride | Ride | `rides` | `App\Models` (pending) |
| Ride | Booking | `bookings` | `App\Models` (pending) |
| Ride | NoshowReport | `noshow_reports` | `App\Models` (pending) |
| Ride | ScoreTransaction | `score_transactions` | `App\Modules\Ride\Models` (moved) |
| Ride | UserScore | `user_scores` | `App\Modules\Ride\Models` (moved) |
| Identity | Otp | `otps` | `App\Modules\Identity\Models` (moved) |
| Identity | RefreshToken | `refresh_tokens` | `App\Modules\Identity\Models` (moved) |
| Identity | StaffRefreshToken | `staff_refresh_tokens` | `App\Modules\Identity\Models` (moved) |
| Identity | Photo | `photos` | `App\Modules\Identity\Models` (moved) |
| Identity | Profile | `profiles` | `App\Models` (pending) |
| Identity | ProfileComment | `profile_comments` | `App\Models` (pending) |
| Identity | UserRating | `user_ratings` | `App\Models` (pending) |
| Console | Complaint | `complaints` | `App\Models` (pending) |
| Console | ComplaintAttachment | `complaint_attachments` | `App\Models` (pending) |
| Console | Employee | `employees` | `App\Modules\Console\Models` (moved) |
| *kernel* | User | `users` | `App\Models` - the authenticated principal only ([ADR 0007](adr/0007-user-is-the-authenticated-principal.md)) |

"Pending" means the ownership is declared and enforced by the ratchets, while the class itself still sits in
`app/Models`. Moving a class is NOT only "the imports that still name it" - that reading cost this phase three
rollbacks. The MEASURED blockers (re-derive them with `php scripts/measure-model-readiness.php`; the numbers are
recorded in `docs/audit/App future audit review r2.md` sec 354) are:

- **Ride cluster** (`Ride`, `Booking`, `NoshowReport`): relation-clean internally, but `app/Models/Complaint.php`
  names `Ride` by namespace adjacency and `RV23ComplaintContextTest` asserts that relation resolves. Moving this
  cluster is gated on that owner question.
- **Console cluster** (`Complaint`, `ComplaintAttachment`, `Employee`): `Complaint` names `Ride` (above) and
  `Employee`; `Employee` is named bare by `Complaint::assignedAgent()`. Forced order, therefore:
  **Ride/Booking -> Complaint -> Employee**.
- **Money cluster** (`Wallet`, `WalletRequest`, `WalletTransaction`, `LedgerEntry`): relation-clean internally;
  gated on Console's remaining imports and the two owner decisions in audit secs 291/293 (response payloads,
  where the approval workflow lives).
- **Identity** (`Profile`, `ProfileComment`): the restored relation `User::profile()` on the kernel principal;
  (`UserRating`): `User::receivedRatings()` plus the `UntangleBatchTest` mechanism assertion. Audit sec 350 has
  the three questions. `Photo` has moved.

The mechanical cost when the gates open, per the same measurement: **146** app-file import rewrites and about
**273** test files across the remaining models.

## 11. Who may read a table, and through what

A module reads its own tables directly and every other table through a contract in the owner's `Contracts`
namespace ([ADR 0008](adr/0008-readers-and-dtos-not-relations.md)). The contracts a module publishes are
its public surface: adding a method there is a design decision, adding an import elsewhere is a ratchet
failure.

| Module | Read contracts (return DTOs or scalars) | Write contracts (one verb each) |
|---|---|---|
| Communication | `NotificationPort`, `PushNotifierInterface`, `UnreadNotificationCounter`, `ChatRepositoryInterface`, `MessageTypeInterface`, `MessageBroadcaster` | `NotificationPort` (send), `PushNotifierInterface` (push) |
| Money | `WalletDirectory`, `WalletChargeHistory`, `WalletTransactionPager`, `LedgerAggregates`, `LedgerReconciliation`, `DriverEarningsReader` | `WalletTransactionRecorder`, `AdminWalletCredit`, `LedgerServiceInterface`, `BookingChargeInterface`, `RidePayoutInterface`, `NoShowSettlementInterface`, `CancellationRefundInterface`, `PassengerWalletChargerInterface`, `CashRideFeeServiceInterface` |
| Ride | `RideRepositoryInterface`, `NoShowReportInterface`, `UserRideStatsInterface`, `ScoreServiceInterface`, `GeocodingServiceInterface` | `BookingEscrowLedger` |
| Identity | `UserRepositoryInterface`, `ProfileRepositoryInterface`, `PhotoRepositoryInterface`, `OtpRepositoryInterface`, `PasswordResetRepositoryInterface`, `VerificationRepositoryInterface`, `StaffTokenVerifierInterface`, `UserTokenVerifierInterface`, `EmployeeAuthInterface`, `StaffJwtInterface`, `DocumentVerificationInterface`, `VerificationApprovalInterface`, `EmailOtpServiceInterface`, `OtpMailInterface` | `TokenRevokerInterface`, `StaffTokenCleanupInterface`, `UserTokenCleanupInterface` |
| Console | `ComplaintRepositoryInterface`, `EmployeeRepositoryInterface`, `EmployeeManagementInterface`, `BanLifterInterface`, `SupportAgentRoutingInterface` | `ComplaintRecorder`, `EmployeeTokenVersion` |

Two rules ride along with that table, and both are ratchet-enforced rather than conventional:

- **A batched method exists wherever a list is rendered.** `WalletDirectory::summariesFor()` and the by-id
  readers exist so that replacing an eager load with a contract cannot introduce a per-row query; the query
  budgets in the snapshot suite fail the build if one appears.
- **A response does not change.** Every port in this table was moved behind a byte-identical response, proved by
  the snapshot corpus. Where the payload embeds an Eloquent row, the port is not attempted: it is parked with
  that reason (see the audit record for the two endpoints still in that state).
## 12. Deploy notes: drain the queue before deploying

Stage 2 moved five **queued** classes, and a queued class is the one kind of move a test suite cannot prove safe:

| Old class name | New class name | Why it is a hazard |
|---|---|---|
| `App\Events\MessageSent` | `App\Modules\Communication\Events\MessageSent` | uses `SerializesModels` |
| `App\Events\NotificationSent` | `App\Modules\Communication\Events\NotificationSent` | uses `SerializesModels` |
| `App\Events\RideBooked` | `App\Modules\Ride\Events\RideBooked` | uses `SerializesModels`; broadcast, so it is queued |
| `App\Events\RideCancelled` | `App\Modules\Ride\Events\RideCancelled` | uses `SerializesModels`; broadcast, so it is queued |
| `App\Events\RideCreated` | `App\Modules\Ride\Events\RideCreated` | uses `SerializesModels`; broadcast, so it is queued |

`SerializesModels` writes the class name into the payload. A job or listener queued *before* the deploy holds the
old name, and the worker that picks it up *after* the deploy cannot deserialise it - the job fails, and on a
queue with retries it fails repeatedly. **Deploy with the queue empty, or drain it first.**

Everything else moved in stage 2 is safe by construction: models, contracts, services and readers are resolved
from the container at runtime, not serialised. Listeners, jobs and notifications deliberately stayed in the
kernel ([ADR 0004](adr/0004-jobs-listeners-notifications-stay-in-the-kernel.md)); only the events moved
([ADR 0009](adr/0009-events-move-with-their-publisher.md)).

A future move of any queued class repeats this note. It is a property of the queue, not of these two classes.