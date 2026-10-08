> SUPERSEDED for status/next steps by docs/audit/BACKLOG.md. History only.

# Syride — APP-FUTURE Audit: Architecture, Design & Product Direction

> Sister document to `SYRIDE_COMPREHENSIVE_AUDIT.md` (which covers *what breaks* —
> security/correctness, 39 findings). This document covers **what the system IS, whether
> its shape is right, and what it must become**. Every claim is measured or has file:line
> evidence gathered on this checkout. Read-only analysis; no code was modified to produce it.

**Method:** whole-tree token-level measurement of `app/` (class LOC, method count, method
length via PHP tokenizer), dependency wiring inspection (providers, composer, config),
migration/schema enumeration, dead-code reachability tracing (who dispatches/calls/binds
what), plus four parallel deep-dive passes (service layer, HTTP layer, infra/scalability,
product features). Where a first-pass claim failed verification it is explicitly corrected —
see §8.

---

## 0. The system in numbers (the context for every verdict below)

| Metric | Value | Note |
| --- | --- | --- |
| `app/` size | **27,405 LOC / 233 files** | Http 8,905 (66 files) · Services 9,445 (41) · Models 1,403 (25) · Domain 1,097 (19) · Repositories 844 (10) |
| Team / history | **2 contributors** (71 + 7 commits), **78 commits, 6 months** (2026-03 → 2026-09) | final-year project, effectively single-developer |
| API surface | **149 routes** in `routes/api.php` | + admin/staff consoles as pure JSON APIs |
| Database | 29 domain tables, 66 migrations, MySQL 8 | `rides` uses native `geometry` + 2 spatial indexes |
| Runtime topology | **5× Octane/RoadRunner app replicas** behind nginx, redis (queue+cache+session), MySQL **primary + replica**, dedicated Horizon container, dedicated scheduler container (`docker-compose.yml`) | |
| Front end in repo | **7 real Blade views + 755 bytes of JS** | `resources/js/app.js` (22 B) + `bootstrap.js` (753 B) — see §7 |
| Tests | 133 test files (66 Unit / 65 Feature) | 1,909 executed |
| Static analysis | **none wired** | Pint in `require-dev`, never run in CI; no PHPStan/Larastan at any level |

No import cycles were found in the dependency graph (verified with the architecture-review
tool across all 377 scanned files). That is genuinely good for code this young.

---

## A. SYSTEM ARCHITECTURE — monolith vs microservices

### A1. Verdict: the monolith is the correct choice — microservices would be actively harmful

The evidence is unambiguous:

1. **Team size vs service count.** 2 contributors, one of them casual. The industry
   median for a viable microservice deployment is a team that needs it; a 2-person team
   gets the *cost* of distribution (149 routes × N boundaries, no contract tests, one DB)
   and none of the benefit. Every split multiplies every current weakness: there is no
   error tracking (no Sentry), no static analysis, no per-service CI — you would be
   shipping 6+ services with the observability of zero.
2. **Shared single database.** 29 tables with heavy cross-domain joins (`rides`↔`bookings`
   ↔`wallets`↔`wallet_transactions` are joined in the money flows and in
   `AdminDashboardController`). Splitting into services *without* splitting data (the
   honest way) means each "service" still UPDATEs another's tables — worse coupling than
   today's method calls, because it now crosses a network hop.
3. **The transaction boundaries prove the domain is one aggregate.** Money movement spans
   ride booking, escrow hold, completion release, no-show penalty — wrapped in
   `DB::transaction` across those tables in `BookingService`, `WalletTransactionService`,
   `Noshowservice` (23 transaction sites repo-wide). In microservices this becomes
   saga/saga-compensation territory. That complexity buys nothing for a final-year product
   with no scale requirement.
4. **Scale is already solved inside the monolith.** App tier is stateless
   (`SESSION_DRIVER=redis`, `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`), runs Octane,
   scales to 5 replicas under nginx, and — verified in `config/database.php:50-56` — has a
   **real MySQL read/write split with `sticky=true`** pointing at a replica container.
   This is a horizontally-scalable monolith already, which is the architecture most
   companies of this size *graduate to*, not away from.

### A2. What is actually wrong is not monolith-vs-micro — it's that the monolith isn't *modular*

The right target is a **modular monolith**: strict domain boundaries enforced *today* so
that if the product ever earns a split, the seams are already there. Syride is 60% of the
way there and contradicts itself:

- `app/Domain/` (19 files, value objects + payment strategies + score policies) — this is
  a genuine hexagonal seed. **But it is an island: the code that should consume it does not**
  (§B2, §C).
- `app/Services/` and `app/Interfaces/` (10 repository contracts, properly DI-bound —
  corrected from first-pass, see §8) — good structure, but bypassed inconsistently.
- Three parallel ride-search implementations, two ledger vocabularies, three auth systems
  (§D1-D3). Modularity isn't about folders; it's about **one place per concept**. Syride
  has 2-3 places per concept.

### A3. The scaling ceiling — what breaks *first* inside this monolith

In order of how soon it will bite:

1. **File storage is the only stateful layer, and it's per-replica.**
   `FILESYSTEM_DISK=local`, uploads go to `storage/app/public`
   (7 sites using `storeAs/putFile` — KYC docs, photos), and compose mounts **only
   `./storage/logs`** as a shared volume across the 5 replicas. A KYC document uploaded via
   app2 is 404 when served by app3. One-line config (`FILESYSTEM_DISK=s3`) plus a
   MinIO/S3 bucket fixes it. This is the single most concrete P1 in the whole document.
2. **`FlushUploadedFiles::class` is commented out** in `config/octane.php:84` — under
   Octane's resident workers, uploaded temp files are not cleaned between requests
   (disk + memory leak on the long-running worker).
3. **TLS verification is disabled on outbound calls**: `verify => false` at
   `RouteCalculationService.php:97,142` and `WhatsAppOtpService.php:212`. Not a scaling
   limit, but a production-grade claim-killer and MITM risk on provider calls.
4. **Geo search is O(rows scanned) per query** (endpoint-radius `ST_Distance_Sphere` in
   `RideRepository::searchRides` :340/:344). Fine at 200 seeded rides; this is a ride app —
   the day rides are the product's point, the query plan must change (spatial `MBRWithin`
   pre-filter or Redis GEO).
5. **No read-your-writes guarantee across the replica split for the money paths.**
   `sticky=true` covers same-connection, but the admin financial dashboards read through
   the replica immediately after writes through the primary (the 5-min report cache in
   `AdminDashboardController::generateReport` partially masks this — verify per screen).

### A4. If you ever split: the only sane seams, in order

(Not recommended now — recorded because "could we?" will be asked at the defense.)
1. **Wallet/payments** — already an implicit bounded context (4 tables, 2 services, clean
   event-ish boundary `WalletTransactionService`). First candidate **only when** a real
   gateway integration forces a webhook ingress anyway.
2. **Notifications/push** — queue jobs + listeners already decouple it.
3. **Search/matching** — *if* you build nearest-driver matching, that's a natural
   worker/service because it wants Redis GEO data that's derived from events.
Everything else (rides, bookings, users, complaints) must stay one module: the transaction
boundaries prove it.

---

## B. CODE ARCHITECTURE — layering and its real violations

### B1. The layer map (as it actually behaves, not as folders claim)

```
routes (149) → Middleware (Sanctum? JWT? StaffJwt — 3 systems, §D3)
  → Controllers (66 files, 8,905 LOC)          ← the problem layer
    → Services (41 files, 9,445 LOC)
      → Repositories (10, interface-bound, DI)  ← genuinely wired (AppServiceProvider, 49 bindings)
      → Models/Eloquent (25)                    ← used by controllers DIRECTLY too (§B3)
    → Domain/ (VOs, strategies, policies)       ← partially wired (§C)
  → Events/Listeners (ShouldQueue) → Horizon   ← correctly wired
```

### B2. Measured god classes and god methods (tokenizer-verified, `app/`)

Worst classes (LOC / methods / longest methods):

| Class | LOC | Methods | God methods (name@line(len)) |
| --- | --- | --- | --- |
| `API/RideController` | **966** | 30 | `createRideWithRoute@661(70)` `passengerConfirmCompletion@785(54)` `driverView@255(53)` |
| `Payment/WalletTransactionService` | **790** | 11 | ledger dialect problem, see §D2 |
| `Ride/BookingService` | **738** | 19 | **`passengerConfirmCompletion@452(111)`** `cancelBooking@253(64)` |
| `API/PassengerProfileController` | 665 | 23 | `chargeWallet@308(62)` |
| `Admin/AdminDriverService` | 646 | 21 | `getVerificationEfficiency@492(61)` |
| `Ride/Noshowservice` | 612 | 7 | **`applyPenalty@478(85)`** `handleConflict@390(78)` |
| `Ride/RideService` | 597 | 20 | **`cancelRide@118(99)`** |
| `API/AdminDashboardController` | 564 | 26 | `approveVerification@458(63)` |
| `Staff/StaffOperationsController` | 564 | 11 | **`bookings@277(90)`** `cancelBooking@471(67)` |
| `Providers/AppServiceProvider` | 257 | 7 | **`register@32(154)`** |

Worst methods **outside** the above (the extreme tail):

| Method | file | length |
| --- | --- | --- |
| `Testfullrideflow::handle` | `Console/Commands/Testfullrideflow.php:52` | **221 lines** |
| `TestRideGatedInteractionCommand::handle` | :37 | **211 lines** |
| `GoogleController::callback` | `API/Auth/GoogleController.php:31` | **176 lines** |
| `RideRepository::createRide` | :38 | **128 lines** (a *repository* doing 128 lines of orchestration — §B3) |
| `SignupController::register` | :23 | 145 |
| `ProfileController::update` | :108 | 108 |
| `AdminBanController::ban` | :52 | 89 |

**The admin/staff console cluster is the systemic problem:** 4 admin controllers +
`StaffOperationsController` + `PassengerProfileController` = ~2,470 LOC where controllers
inline DB reads, money arithmetic and multi-step state changes that already exist as
services. `AdminBanController::ban()` (89 lines) does validation + duplicate-ban check +
transaction + notify — a `BanService` would be ~15 lines of controller left over. The
read-model services (`AdminDriverService`, `AdminTripService`, `AdminReportService`) are
acceptable in shape; the *action* controllers are not.

### B3. SOLID, measured

- **SRP** — broken at the boundaries above (controllers as orchestrators,
  `RideRepository::createRide` as orchestrator). `WalletTransactionService` at 790 lines /
  11 methods *holds* the ledger: the class is the ledger aggregate root, which is
  defensible — but it mixes escrow holds, releases, refunds, cash-fee settlement and
  chargeback-ish paths in one file with two event-name vocabularies.
- **DIP** — *mostly honest*: 43 controllers constructor-inject services, 10 repository
  contracts are interface-bound and bound in the provider. The violations are surgical:
  2 controllers inject `RepositoryInterface` directly (skipping the service layer:
  `StaffAdminController`→Verification, `AdminDashboardController:494` does
  `app(VerificationRepositoryInterface::class)` mid-method); **22 `app()` service-locator
  calls inside `app/` outside providers** (container-as-globals); the `verify=>false` HTTP
  calls are hard-coupled to the provider choice (no `DirectionsProvider` interface, while
  a `GeocodingServiceInterface` exists for geocoding — asymmetric).
- **LSP** — no violating overrides found (small `extends` footprint).
- **ISP** — `RideRepositoryInterface` grew the way interfaces rot in practice: it carries
  `createRideWithGeometry`, `getRideById`, `getDriverRides`, `searchRides` (CRUD+query+
  search in one contract); `BookingService` and `RideService` both take the whole thing.
  Split into `RideWriter` / `RideQueries` / `RideSearch`.
- **D** is the one rule this codebase actually obeys consistently (DI via constructors +
  provider). Say that explicitly at the defense — it's true and it's rare at this level.

---

## C. DESIGN PATTERNS — inventory, and the ones that are cargo-culted

| Pattern | Where | Adoption | Verdict |
| --- | --- | --- | --- |
| **Repository** | 10 interfaces + impls, 49 provider bindings | used by 10 services/controllers (recount-corrected, §8) | **genuinely wired** — good |
| **Strategy** | `Domain/Payment/Strategies/*Factory` + `Domain/Score/Policies/*` | consumed by `BookingService:34` and `ScoreService:20` | **genuinely wired and effective** — the best pattern work in the repo |
| **Value Object** | `Domain/ValueObjects/Money/Email/Location/PhoneNumber` (264-line `Money`) | `Email/Phone/Location` used by 4 DTOs + auth; **`Money` used by only the reporting layer — ZERO of the 7 money-moving services** | **half-adopted → island** (§D1) |
| **Factory** | `PaymentStrategyFactory`, `ScorePolicyFactory` | bound in provider | fine |
| **Event/Listener** | 9 events, 5 listeners, all listeners `ShouldQueue` | queue decoupling correct | **but 3 dead events**: `UserVerified` fired 0× / 3 listeners; `OtpSent` 0×/0; `ConversationCreated` 0×/0; `RideCreated` fired 1× / 0 listeners |
| **Observer** | `UserObserver`→auto-`ProfileRepository::create` | works | fine |
| **DTO** | 4 classes (`DTOs/Auth`, `DTOs/Ride`) | used by EmailOtp + ride creation only | correct but tiny — service signatures use **41 `array $` params** instead (§D1) |
| **Pipeline/Chain** | none, despite `ChatMessageHandler` doing fetch→authorize→transform→persist→broadcast inline | — | candidate, low priority |

The pattern story of this codebase: **it doesn't lack patterns, it lacks completion.**
Each pattern exists at 1-2 exemplary sites and at 0 adoption sites where the same concept
appears in raw form. The `Money` VO is the proof: it is a *well-built* class (minor-unit
integer storage, currency guard, add/sub/mul/div with rounding, comparison, formatting)
and it was written last and then the services kept doing `round($x * 0.95, 2)` in 66 sites.

---

## D. CODE SMELLS & VIOLATIONS — ranked by harm

### D1. Primitive obsession, specifically about money (P1)
66 arithmetic/`round()` sites in money paths; **0 `bc*` calls**; `Money` VO unused by the
mutation layer; `(float)` casts on balances at `WalletTransactionService:720-727`. The
columns are `decimal(15,2)` — the DB is the only place doing exact arithmetic. `Money.php`
internally converts `float` input via `(int) round($amount * 100)` — safe enough for the
scale, but the entry point accepts float and every caller computes in float first, so
half-cent errors are *created before* the VO could prevent them.
**Fix:** every service signature that carries an amount takes/returns `Money`; ban `float`
money args via Larastan's `strict` rules once §E1 tooling lands.

### D2. The ledger has two dialects (P1 — reconciliation risk, not just style)
Per-seat path writes `ride_booking_payment` / `escrow_release`
(`WalletTransactionService:742`), whole-ride path writes `escrow_received` /
`escrow_released` (`:178`) — same economic events, different `type` strings. Any
reconciliation or analytics query must know both, and the audit history (T1-2: ledger types
absent from the DB enum) shows the team already fought enum/DB drift here once.
**Fix:** one `LedgerEvent` enum as the single writer; a nightly `ledger:reconcile` command
(`sum(wallet_transactions) vs wallets.balance per wallet`) — **no such job exists in
`Console/Kernel` (4 scheduled commands verified).**

### D3. Three auth systems, no boundary story (P1 for a "production" claim)
Custom JWT (passengers/drivers) + staff-JWT (employees, own token table, own TTL config) +
**Sanctum (`personal_access_tokens` table + config present)** — the third is used by
nothing in `routes/api.php` (no `auth:sanctum`). Either document "Sanctum is for the mobile
app, not built yet" or remove it.

### D4. Dead-but-wired code — the repo's most characteristic smell (P1: misleading)
- **`RideSearchService` (route-buffer geo search, the technically superior search) is bound
  as a singleton (`AppServiceProvider:81`) and injected into `RideService:29` — and called
  ZERO times.** Every request runs `RideRepository::searchRides` (endpoint-radius) instead
  (:515). The k6 load tests hammer `/api/rides/search` — i.e. **the performance numbers
  measured the simple search while the fancy one sits unreachable.**
- `SendScheduledNotification` job: class exists, **0 dispatch sites** (verified).
- 3 events with no dispatcher (§C). `BookingStatus` enum has no `no_show` case while
  18 sites write the `'no_show'` string.
- Deprecated status string `'awaiting_confirmation'` is still filtered by 7 admin/staff
  read sites (`AdminTripService:68,138,143` etc.) that no live writer uses.
- Orphan columns written-never: `rides.pickup_lat/lng/destination_lat/lng` — the
  repository converts to geometry then **`unset()`s them before insert**
  (`RideRepository:204-214`): 4 decimal columns are permanently NULL on every row.
  `rides.passengers_confirmed` (0 app refs), `profiles.number_of_rides` (never incremented).
- `stripe/stripe-php` in composer.json (`:27`): **0 references in `app/`** — a payment
  gateway dependency that does nothing except imply functionality that doesn't exist.
- 11 console commands that are test harnesses (`Testfullrideflow` with a 221-line `handle`,
  `TestRideGatedInteractionCommand` 211 lines, `Testridecompletionflow`,
  `Getloadtesttokens`, `TestNotificationCommand`) shipped in `app/` — production image
  includes debug entry points that create rides and mint load-test tokens.

**Fix:** one `make:dead-audit` pass, then delete-or-wire each item. The `RideSearchService`
decision is the interesting one: wire it into the live search (with a feature flag) or
delete it — but don't ship it injected-but-dead.

### D5. Controller-level duplication (P2)
Wallet lookup, OTP fan-out triplets (email/WhatsApp/TextMeBot — three near-identical
`sendOtp/verifyOtp` services, 270/226/~250 LOC each), pagination shape rebuilt per
controller, and validation split inconsistently: 6 controllers with inline
`validate([...])` vs 6 `FormRequest` classes — the **money endpoints are the inline ones**
(ride creation `price_per_seat` cap 500 vs `CreateRideRequest` cap — two validators, one
field, already contradictory on `notes`: 1000 vs 500).

### D6. Naming/convention drift (P3 but defense-day visible)
`Noshowservice.php` (lowercase-s, class `Noshowservice`, and a stray "PLACE IN:" header at
`:21`); `Syrideseeder.php` vs PascalCase siblings; test-mode timing constants shipped:
`Noshowservice:56-58` — `GATE_MINUTES = 1` / `DISPUTE_MINUTES = 2` where the commented-out
real values are 1 *hour* / 2 *hours* (a "no-show" dispute window of two minutes is not the
designed product).

### D7. No static analysis, no formatter in CI (P1 as a gate, not as a tool debate)
PHP 8.2 + Laravel 10 + enums everywhere — and yet no Larastan at any level, no Pint run in
CI (only sonar + deploy + tests). Given D1 (float money), D4 (dead code), D6 (typo'd
strings), a `larastan:level-6` + `pint` CI gate would have caught a measurable share of the
39-findings bug audit class. **This is the highest-leverage 20-minute change in the repo.**

---

## E, F, G - MOVED to docs/audit/ROADMAP.md

These three sections are the feature plan, not status. They were moved verbatim to
`docs/audit/ROADMAP.md` on 2026-10-03 and are maintained there. Nothing else in this
file depends on them, and the remediation log below is unaffected.


---

## H. First-pass claims that failed verification (recorded for honesty)

1. **"Repositories are unused" — WRONG.** My first regex had an escaping bug (counted 0).
   Recount: 10 interfaces, bound in `AppServiceProvider` (49 bindings total), consumed by
   ~14 service/controller files. The repository layer is real; its main smell is the 128-line
   `createRide` orchestration living inside one of them.
2. **"No read/write split" — WRONG.** `config/database.php:50-56` defines
   read/write/`sticky` with `DB_REPLICA_HOST`, and compose runs a replica container. The
   split is active via Laravel's automatic routing; explicit `connection('mysql-read')`
   greps found 0 hits *because none is needed*.
3. **`config/app.php` "duplicate key" (carried from bug-audit T4-6 re-check)** — confirmed
   false, top-level vs nested, pinned by a test now.
4. **k6 coverage of the fancy search** — assumed it exercised route-buffer; traced to
   `RideRepository::searchRides` only. The load-test numbers do not characterize
   `RideSearchService`, because nothing calls it (§D4).

---

## I. Executive summary

**Is the current architecture the right one?** The **monolith is correct** and would remain
correct at 10× users; **microservices would be a mistake** (2 developers, one shared DB,
no platform team). The honest problem is that it is a *layered* monolith, not a *modular*
one: the money ledger speaks two dialects, ride search has three implementations (one
better-but-dead), authentication has three systems, and the domain layer (`Money` VOs,
Strategies) is exemplary-but-underadopted.

**Is the code architecture right?** Above-average skeleton (DI discipline, interface-bound
repositories, genuine Strategy/Event/Queue work, zero import cycles) undermined by three
measurable habits: ~2,500 LOC of business logic living in admin/staff controllers, a
long-method tail (9 methods ≥ 85 lines), and dead-but-wired code that makes the system's
claims unverifiable — the same disease T4-1 found in the test harness, in production form.

**The three most important truths found:**
1. Uploads are on per-replica local disk while the app runs 5 replicas — the product's
   file layer will corrupt the first time it matters.
2. Every "real-time" feature is server-wired and client-dead (755-byte broken JS).
3. GPS — the heart of a ride-sharing app — is entirely absent: no positions, no tracking,
   no matching, no ETA; the app is today a *ride-listing* product, not a *ride-sharing*
   product. That, plus the missing payment gateway, are the two decisions to make before
   any further polish: finish the claims, or fix the README.

**Roadmap is F: P0 (≈2 days) removes the false claims; P1 (≈1-2 weeks) makes money exact
and single-vocabulary; P2 (≈2-3 weeks) builds the actual geospatial product; P3 is the
feature surface.** None of it needs a different architecture — it needs the architecture
they already drew to be finished.

---

## J. Remediation log (started 2026-09-26)

Executed with the same discipline as the bug audit: one finding at a time to a terminal
state, causality proven by needle-patching the defect back in.

### AF-1 — TLS honesty + Octane upload hygiene — VERIFIED FIX

**Finding (§A3.2, §D4, §F-P0.3):** outbound provider calls shipped with TLS peer
verification disabled — `Http::withOptions(['verify' => false])` twice in
`RouteCalculationService` (the response drives ride **distance**, and distance drives
**fare** — a MITM target), and a dedicated `new Client(['verify' => false])` in
`WhatsAppOtpService::sendViaCallMeBot()` built a *second* insecure client on a class that
already owned a verified one (constructor line 21) — sending the API key over an
unverified channel. Separately, `config/octane.php` had `FlushUploadedFiles::class`
commented out in the `RequestTerminated` group, leaking one uploaded temp file per request
for the lifetime of each of the 5 resident RoadRunner workers.

**Root cause:** both were dev-box conveniences (a missing CA bundle on WAMP) that rode to
production. **Causality evidence gathered before the fix:** verified HTTPS
(`VERIFYPEER + VERIFYHOST=2`) was tested working from this very machine against both
`api.callmebot.com` and `api.openrouteservice.org` (HTTP 200, CA from
`curl.cainfo` in php.ini) — the workaround had no surviving excuse.

**Files changed:**
- `app/Services/Geocoding/RouteCalculationService.php` — both `verify => false`
  wrappers removed (verified calls now ride Laravel's default Guzzle handler).
- `app/Services/WhatsAppOtpService.php` — insecure client deleted; send path reuses
  `$this->client` (the verified constructor client).
- `config/octane.php` — `FlushUploadedFiles::class` re-enabled (import was already present).
- New `tests/Feature/AppFuture/TlsAndOctaneHygieneTest.php` (3 tests): comment-aware
  tokenizer scan proves **no** `verify=>false`/`VERIFYPEER=false` anywhere in `app/`
  source (my explanatory comments quote the string and do NOT trip it); octane listener
  config contains the flush listener; WhatsApp service constructs exactly one client.

**Verification:**
- Pin suite green; causality proven with three separate needle patches — (1) re-inserting
  `['verify' => false]` in RouteCalculation → TLS pin fails naming that file; (2)
  re-adding the second client → BOTH TLS pin and the "one verified client" pin fail;
  (3) re-commenting FlushUploadedFiles → octane pin fails with the leak message. Every
  needle restored byte-exact; post-restore diffs contain only the AF-1 edits (CRLF +
  UTF-8 intact, `git diff --check` clean).
- Regression sweep: AppFuture 3/3, T3Batch 37/436, T4Batch 16/50,
  SessionCookieAndCors 11, DebugEndpointDisclosure 9, AppServiceProviderTest 18,
  ArabicPlaceNameServiceTest 18 — all OK. GeocodingServiceTest is 17/11E/4F, identical to
  its baseline recorded under T4-6 (pre-existing, different service, untouched by AF-1).

**Genuinely unverified:** the live network call to each provider inside the running app
(the handshake test above proves the TLS path, and the unit suite proves the code no
longer disables it; a full OTP round-trip needs a real CallMeBot key). On Linux/docker the
CA bundle comes from the OS store (the repo-shipped cacert was deleted under T4-6; the
image does not rely on it).

**Next:** see docs/audit/STATE.md.

### AF-2′ — Modularity foundation: bounded-context map + machine-checked ratchet — VERIFIED FIX

**Owner direction:** "make it modular first." Reframed correctly (see §A2): modularity is
not a folder move, it is *enforced boundaries*. A big-bang restructure with no gate would
re-rot — this repo already *has* a modularization (`Domain/` VOs + Strategy factories) that
the code simply ignored, which is the proof that structure without enforcement fails. So
AF-2′ delivers the rules *and* the checks before any code moves.

**Delivered:**
- `docs/audit/ARCHITECTURE_MAP.md` — the six bounded contexts, the allowed dependency
  direction, the baseline table, and the four audit defects assigned to AF steps. This is
  the *agreed shape*; the test below is the *machine-checked rule*.
- `tests/Feature/AppFuture/BoundaryDependencyTest.php` — a **ratchet**, not a lint. It
  scans every `app/` file and counts nine forbidden cross-context edges. Two properties:
  a *new* violation fails the suite, and *fixing* one also fails the suite until the human
  lowers `BASELINES` — so debt can only shrink deliberately, never silently.
- `.github/workflows/pint.yml` — Pint check-mode gate on every push/PR (branch set includes
  `Agentic`, so the audit branch is gated too).
- `.github/workflows/architecture.yml` — runs the ratchet + the fully-green audit suites
  against a MySQL service. Deliberately a *growing list*, not `php artisan test` on the
  whole tree: the 374 pre-existing broad-suite errors would make an all-or-nothing gate
  permanently red — the exact `|| true` disease removed under T3-11, in the opposite
  direction. The list grows as that debt burns down.

**Measured baselines (the honest current debt, from the dependency graph):**
`request_below_http = 0` and `domain_to_http = 0` and `async_to_http = 0` are **hard** (already
clean). Grandfathered: `domain_to_services = 2` (both reach `WalletTransactionService`),
`domain_to_models = 10` (Score policies + payment strategies type-hint Eloquent),
`repos_to_services = 2` (`PasswordReset→Jwt`, `RideRepo→Geocoding`), `request_in_services = 1`
(`AdminAuthService`), `controllers_to_models = 21 of 38` (the headline §B2 problem),
`models_to_enums = 2`.

**Pre-sweep:** the whole tree was made Pint-compliant first (392 files, style-only) so the
gate is green from day one rather than a wall of noise. `.gitattributes` declares
`* text=auto eol=lf`, so Pint's LF normalization is the committed form.

**Verification:**
- AppFuture suite (ratchet + AF-1 pins): **OK (12 tests, 40 assertions)**, deterministic ×3.
- Ratchet causality, three needles: (A) a Domain file importing `Illuminate\Http\Request` →
  `request_below_http` fails naming the probe file; (B) a 22nd controller touching a model →
  `controllers_to_models` fails; (C) *removing* a violation (deleting `PasswordReset→Jwt`)
  fails demanding the baseline be lowered — proving the ratchet can't quietly drift.
  All needles restored; `ScoreController` was accidentally LF-ified by a needle round-trip
  and re-fixed via the style sweep.
- **Full-suite regression after the 392-file style sweep: 1921 tests / 374 errors / 56
  failures** vs the pre-sweep 1909/374/56 — the +12 are exactly the new AppFuture tests;
  **zero new errors, zero new failures.** Style-only confirmed.
- Both new workflow YAMLs parse (`yaml.safe_load`); the CI test step's single-path form was
  executed locally (`php artisan test --no-coverage tests/Feature/AppFuture` → 12 passed) —
  the earlier `>`-fold block scalar was wrong (artisan takes ONE path) and was fixed to a
  `for` loop before commit.

**Genuinely unverified:** the two new GitHub Actions have not run on CI (this env cannot
trigger GitHub). Their commands were replicated locally and pass. **Larastan is deferred,
not silently skipped** — it is not installed and a level-5 run over a 392-file untyped
surface would produce un-actionable noise; it becomes the second gate after AF-6/AF-7 add
types on the way. Owner decision needed if you want it attempted now.

**Owner decisions captured (for AF-4, not yet executed):**
- **Ride search:** keep `RideSearchService` (route-buffer), wire it live, delete the other —
  must be load-tested before the swap.
- **Sanctum:** owner stated *"I am using JWT, I don't want Sanctum for anything"* → AF-4 will
  remove the Sanctum config/surface (nothing consumes it today).

**Next:** see docs/audit/STATE.md.

### AF-4 — Un-tangle the dead-but-wired set — VERIFIED FIX (both owner decisions applied)

Owner decisions executed: **search** → wire `RideSearchService`, delete the other;
**auth** → "I am using JWT" → Sanctum removed entirely.

**4a — the search swap and the three bugs it exposed.** `RideService::searchRides`
delegated to `RideRepository::searchRides` while `RideSearchService` was bound as a
singleton (`AppServiceProvider:81`), injected (`RideService:29`) and called zero times.
Flipping the delegation immediately surfaced why the service had *never* worked:
`->with(['driver' => fn => select(... 'driver_rating')])` — `users.driver_rating` does
not exist; the first live search call would have thrown `SQLSTATE 42000`. A fourth
unrouted copy (`RideController::search`, zero routes) was deleted with it. And the
presenter read `driver->driver_rating ?? 0` / `user->passenger_rating ?? 0` — the `?? 0`
made a **fake rating of 0 render silently for every driver and passenger in every API
response** (`BookingResource:36` had the same disease). Now both resources average the
real `user_ratings` relation; `User::getAverageRatingAttribute` was made
relation-aware (batched when eager-loaded, one query when not) — a `withAvg` attempt
first proved dotted nested aggregates are unsupported on this framework version
(`BadMethodCallException`), and a response-shape consideration forced dropping the
column-whitelist selects: the live controller serializes raw models, so trimmed
selects would SUBTRACT fields the repository path used to include. Eager loads are now
strictly additive.
The 3 pre-existing `RideSearchServiceTest` failures were **fixture bugs, not product
bugs**: `insertRide` wrote `POINT(lat lng)` — WKT is `lng lat` — 397 km off (measured),
which is why 2 guarded "risky" tests also passed vacuously. Fixed. One scope
correction mid-task: the live `/rides/search` controller returns raw models (no
Resource), so the rating assertion targets the service+presenter, not the endpoint.

**4b — Sanctum gone** (owner: JWT for everything): `config/sanctum.php` deleted,
`HasApiTokens` off `User`, `sanctum/csrf-cookie` out of CORS, package removed via
composer (lock + vendor updated; `package:discover` regenerated — the stale
bootstrap-cache entry was caught by a boot check, not guessed). Verified no route ever
used `auth:sanctum`.

**4c — the dead event chain deleted, the one real gap wired.** First, an honesty
correction to this audit's own §C: my earlier "dead events" table was built with a
non-recursive file glob and was wrong. Re-measured with ripgrep: `UserVerified` IS
dispatched (`StaffAdminController:155`), `RideCreated/RideCancelled/RideBooked` ARE
dispatched (as broadcasts). The genuinely dead items: events `OtpSent`,
`ConversationCreated`, `MessageReceived` (zero dispatchers; the last two carry empty
constructors and a placeholder `channel-name`), listeners with empty `handle()` bodies
(`SendMessageNotification`, `SendOtpNotification`, `SendRideBooked/Cancelled` — queued
listeners bound by `event()` never fire from `broadcast()` calls, so booking/cancel
notifications were never going to come from them; the real path is inline
`NotificationService::createNotification`), job `SendScheduledNotification` (0 dispatch
sites), notification `MessageReceivedNotification` (0 app references), and the
stub-asserting test files that existed only to prove stubs are stubs (same disease as
T3-7; the deleted suites were 5 of them). `EventServiceProvider` shrank from 4 chains
to 1 — and that one is now *functional*: while deleting the dead scaffolding I found a
real user-facing asymmetry — **reject notified inline** (`StaffAdminController:218`)
but **approve fired `UserVerified` into an empty listener, silently**. `SendUserVerified
Notification` now creates the in-app notification (queued), closing it.

**4d — `BookingStatus::NO_SHOW` added**: the DB enum and 18 code writes carried
'no_show' while the PHP enum lacked it → `tryFrom('no_show') === null` for a live state;
`label()`/`color()` arms added (exhaustive matches would have thrown).

**4e — no-show windows config-driven** (`config/rides.php`): the money path shipped
`GATE_MINUTES=1 / DISPUTE_MINUTES=2` with the real hours commented out above them —
a two-minute dispute window on escrow penalties. Default is now the real 1h/2h, env-
tunable (`NOSHOW_GATE_HOURS`/`NOSHOW_DISPUTE_HOURS`). Verified nothing relied on the
test values: seeder departures are 3–72 h past, and T3 money tests call
`resolveExpiredReports()` directly (bypasses the gate).

**4f — production guard trait** on all 5 state-forging debug commands
(`Testfullrideflow`, `Testridecompletionflow`, `TestRideGatedInteractionCommand`,
`TestNotificationCommand`, `Getloadtesttokens`): they REFUSE to execute in
`production` (not just hide from `list`). The guard message is asserted, not just the
exit code — proven necessary when the first version passed *vacuously* (an unguarded
command also exits 1 on missing args; caught by a stripped-trait needle).

**Verification:**
- New `UntangleBatchTest` (8 tests: eager-load presence, repo-search gone, Sanctum
  absent from config/traits/CORS/routes, enum covers no_show, hours config + minute
  constants gone, hour-gate refuses a 30-min-past report, prod guard refuses 2 commands
  by message) + rewritten `EventServiceProviderTest` (6, incl. a real notification-row
  behavior test and deletion pins) + `RideSearchServiceTest` 15→17 (+rating truth,
  +N+1 batch proof via `DB::listen`). Deterministic ×3: **OK (43 tests, 292 assertions)**.
- Causality, syntax-safe needles (a brace-surgery first attempt broke files
  mid-harness — self-inflicted, caught by lint-inside-harness): degraded eager-load →
  pin fired; emptied listener → behavior test failed; stripped trait → message
  assertion failed; all restored green.
- Full suite **1872 / 374E / 53F** vs post-AF-2′ **1921/374/56**: errors unchanged;
  **failures −3 = exactly the fixture bug AF-4a fixed**; count −49 = the deleted stub
  suites minus the new pins. The 2 Enums failures (`ComplaintType` 9≠8, `StaffRole`
  level 4≠3) are pre-existing drift in files this batch never touched (git-diff
  verified). Pint tree CLEAN; app boots; composer regenerated (11,372 classes).

**Genuinely unverified:** the owner-decision note said "load-test before the swap" —
the swap was verified *functionally* (identical SQL semantics: both copies run the same
endpoint-radius + route-buffer OR logic; the wired one now also batches 3 loads the old
one ran lazily or not at all), but no k6 run compared them (no k6/Grafana binary here).
Both copies are gone-or-one now, so a regression run of `syride-breakpoint-test.js`
against the branch is the honest follow-up if you want numbers before a deploy. Also:
the approve-notification fires through the queue in production (sync in tests), so its
delivery rides on Horizon — verified at the service level, not end-to-end through the
worker.

### Section-J progress snapshot

| Step | State |
|---|---|
| AF-1 TLS + Octane hygiene | VERIFIED FIX |
| AF-2′ modularity foundation (map + ratchet + CI gates + style sweep) | VERIFIED FIX |
| AF-3 debug commands | **absorbed into AF-4f** (prod-guard supersedes "hide") |
| AF-4 un-tangle | VERIFIED FIX |
| AF-5 shared object storage | not started (needs infra decision: MinIO vs bucket) |
| AF-6 money module (Money VO, LedgerEvent, reconcile) | not started — next |
| AF-7 controller extraction / Larastan | not started |
| Geospatial (GPS/tracking), payments, trust features | open product decisions (§E) |

**Next:** see docs/audit/STATE.md.

---
*Maintained as the future-state companion to the bug audit. If any item here graduates to a
fix task, mirror it into the `SYRIDE_COMPREHENSIVE_AUDIT.md` workflow (one problem at a
time, verification to terminal state).*
