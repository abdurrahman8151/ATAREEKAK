# SyRide - BACKLOG (single source of truth for every audited task and its current status)

Written 2026-10-03. This file **replaces the status/next-steps role** of the four audit documents.
They stay as history: `docs/audit/STATE.md` points here for status, and `docs/audit/ROADMAP.md`
holds the feature plan moved out of `APP_FUTURE_AUDIT.md` sections E-G.

## 1. How to read this file

**File keys used in Evidence** (all under `docs/audit/`):

| Key | File |
|---|---|
| `S` | `SYRIDE_COMPREHENSIVE_AUDIT.md` (`S P1` = Part 1, the original snapshot, lines 86-582; `S P2` = Part 2, current remediation state, lines 583+) |
| `A` | `APP_FUTURE_AUDIT.md` |
| `R1` | `APP_FUTURE_SONNET.md` |
| `R2` | `App future audit review r2.md` |

**Status vocabulary** (one value per row; every value is traceable to a section):

| Value | Meaning |
|---|---|
| `VERIFIED FIX` | Closed. Implemented, verified, committed. Any remainder is a *recorded* classification or deploy-surface item, listed in section 5 - not a code task. |
| `PARTIAL` | A verified half landed and is committed; the remainder is named in section 4 and is still work. |
| `OPEN` | No verified half landed; the task exists and is not closed. |
| `BLOCKED` | Nothing landed, and a specific owner decision or owner action gates the first step. |
| `DEFERRED` | Closed by owner instruction "leave for later" (not an agent decision). |
| `GATED` | Headline refuted or superseded; no code change is warranted until a coupled item lands. |
| `RECORDED` | A verify-first check (V-): its result was measured and recorded; it is not a fix. |
| `SUPERSEDED` | The ID was absorbed into or renamed by another ID; no separate work remains. |

**Rules used to settle status** (per the task brief):

1. Status comes from the **newest section that mentions the ID**. Inside `R2`, the per-task
   sections `sec 10`-`sec 37` (and `sec 26.x`, `sec 28.x`, `sec 29.x`) beat `R2 sec 12`'s progress tables and
   `R2 sec 18.2`/`sec 18.4`, because those tables were written earlier and were never rewritten.
2. Every `VERIFIED FIX` was checked against git: the cited commit **must exist** (`git cat-file -e`).
   Section 3 is the full check: **the Evidence column of section 2 cites 48 commits, 48 exist,
   0 missing**, and every commit cited anywhere in the four audit files exists too.
3. Where two docs disagreed, code/git settled it. Where it stayed unclear, the row is marked
   `OWNER DECISION` in section 6 rather than being guessed.
4. `Blocked by` names an **owner decision** from the index in section 1a: `1a`-`14` are the numbered
   lists (`R1 sec 6` = 1-10, `R2 sec 5` = 11-14, with `1a`/`1b` separating the two different "#1"s),
   and `un1`-`un13` are owner calls the audit records describe but never number. `n/a` = the row is
   closed (any gated remainder is written out in section 5, not hidden). `none` = nothing gates the
   next step. **`none` is only ever written on an `OPEN` or `PARTIAL` row.**
 5. `Order` is execution order, and section 2 is **one table** ordered by it. The wave bands are
    Order ranges, not separate tables: `1-16` Wave 0 verify checks (`R1 sec 3` + `R2 sec 4`, results
    in `R1 sec 9` / `R2 sec 9`); `17-23` Wave 1; `24-34` Wave 2 (`RV-08` last - see below);
    `35-41` Wave 3, the money module (= `AF-6`); `42-44` Wave 4; `45-50` Wave 5; `51-56` Wave 6;
    `57-66` the `AF-` plan; `67-105` the `S` bug audit. Waves come from `R1 sec 7` **as amended by
    `R2 sec 6`** (which puts `RV-34` first in Wave 2 and adds `RV-40` to Wave 3). `RV-08` sits at the
    Wave-2 tail because `R1 sec 7` places it there and `R2 sec 6` omits it (placement unconfirmed -
    conflict 13). `RV-02` L2 is folded into the `RV-02` row rather than duplicated, so each ID
    appears once. Orders are contiguous 1-105, no gaps, no duplicate ID.

**The next task is not listed here.** `docs/audit/STATE.md` derives it from one rule.

### 1a. Owner-decision index (the `Blocked by` column's vocabulary)

`R2 sec 18.3` claims "Decisions 1-10 originate in `R1 sec 6`", but its #1 is **not** `R1`'s #1 - see conflict 16
in section 6. So every number below carries both the text and the row it gates; a `Blocked by` cell that says
`1a` / `1b` resolves to these two lines, never to a guess.

| Key | Decision | Source | Gates |
|---|---|---|---|
| 1a | `sycash` role: does it approve wallet requests? | `R1 sec 6` #1 | RV-26, and the RV-12/RV-19/RV-23 admin-role drift recorded in `R2 sec 30` item 6 |
| 1b | Approve a **staff-authenticated document streaming route** so KYC can leave the public disk | `R2 sec 18.3` #1 | RV-01 storage half |
| 2 | Auto-confirm window for unconfirmed rides / escrow release policy | both | RV-10, RV-02 L2, RV-20 |
| 3 | Platform fee on cancellation payouts (currently none) | both | RV-09, RV-02 L2, RV-11, AF-6 |
| 4 | Phone-OTP endpoints: keep or delete | both | RV-16 |
| 5 | Show driver phone to all authenticated users or only booked passengers | both | RV-29 (`communication_number`) |
| 6 | Staff-initiated cancel: full refund? score impact? | both | RV-03 (the only Wave-1 blocker) |
| 7 | One deployment target: compose+nginx or Render | both | RV-08, T3-10 |
| 8 | Account-enumeration policy (uniform vs friendly errors) | both | RV-16 |
| 9 | Access-token TTL (600 min -> 15-60) | both | RV-04 completion, T4-4 |
| 10 | Add a real `users.phone`? | both | RV-19 |
| 11 | Reject KYC until every required document is attached? | `R2 sec 5` | RV-01 |
| 12 | Delete the stub notification classes and their tests? | `R2 sec 5` | RV-31, RV-35 |
| 13 | Statuses as `string` + PHP enum instead of DB ENUM? | `R2 sec 5` | RV-40 (closed; the question stays open) |
| 14 | Add a whole-booking cancel route, or keep cancel-via-all-seats? | `R2 sec 5` | V16 follow-up, RV-02 L2 |
| un1 | MinIO vs a managed bucket for shared object storage | `A sec J` line 693 | AF-5 |
| un2 | Score policy: tier bands, clamp ceiling, starting score | `R2 sec 26.9` | RV-11 |
| un3 | `wallets.kind` enum + double-entry + adjustment thresholds | `R2 sec 26.5` | RV-21, AF-6 |
| un4 | Error envelope shape + `/api/v1` (changes the Flutter contract) | `R2 sec 20.1` | RV-13 |
| un5 | `finish` / `driver-confirm`: delete, 410, or implement; `distance`/`duration` units | `R2 sec 21.1` | RV-14 |
| un6 | Complaint transition policy (`GET` auto-transition), conflict-notification wording, and whether the public store may accept the internal `no_show` type | `R2 sec 28.3`, `sec 28.8` (a)(b)(c) | RV-23 |
| un7 | Revenue definition (which ledger events count) | `R2 sec 28.7` | RV-19 |
| un8 | Attempt Larastan now (composer network is CI-only)? | `A sec AF-2'`, `R2 sec 1.3` | AF-7 |
| un9 | Enable the lazy-loading flag on 1 error / 18 new failures? | `R2 sec 37` | RV-38 |
| un10 | `wallet_requests.wallet_id NOT NULL` vs 26 tests: schema or tests? | `R2 sec 19.3` | RV-21, T3-4 |
| un11 | The auth-core refactor that makes a cache DTO safe (`JwtAuthMiddleware` auto-lift hazard) | `R2 sec 30` item 3 | RV-29 cache half |
| un12 | k6 `setup()`-seeding + the perf-reporting spec (3x runs, result JSON) | `R2 sec 30` item 7 | RV-17 remainder |
| un13 | The account-status model refactor (drop persisted "logged-out" status=0, `BanService`) | `R2 sec 27` "out of scope on purpose" | RV-12 remainder |

`un*` numbers are owner calls the audit records describe but never numbered. They are flagged as
**OWNER DECISION** in section 6 so they are not mistaken for the 1-14 list.

**All 27 decisions were answered by the owner on 2026-10-02** (walked one at a time). The full
record with the owner's reasons is `App future audit review r2.md` sec 40; the short form:

| Key | Answered | Bucket |
|---|---|---|
| `1a` | A — SyCash never approves; staff only | already-correct |
| `1b` | A — staff-only KYC streaming route | do-now (frontend coordination) |
| `2` | B — expire unconfirmed bookings, no auto-confirm | do-now (reconsider) |
| `3` | A — cancellation money policy UNCHANGED | reconsider, HIGHEST priority |
| `4` | B — keep phone-OTP endpoints | reconsider |
| `5` | DEFERRED — driver-phone visibility needs more thinking | later |
| `6` | A — staff cancel: full refund, no driver score penalty | do-now |
| `7` | B — Render (cost) | do-now |
| `8` | A — uniform errors, no account enumeration | already-correct |
| `9` | B — keep 600-min TTL | reconsider |
| `10` | B — no `users.phone` | reconsider |
| `11` | A — KYC gates the ACTIONS (browse yes, ride no) | do-now |
| `12` | MOOT — stubs already deleted | no action |
| `13` | A — PHP enums, drop DB ENUMs | do-now |
| `14` | A — keep both cancel routes | reconsider |
| `un1` | A — MinIO (free, self-hosted) | do-now |
| `un2` | A — start 70 / max 100 / bands 80-60-40 / gates 50-40 | do-now (+ tier bug fix) |
| `un3` | A — kind + double-entry + thresholds, **needs explanation** | do-now |
| `un4` | C — change nothing until the frontend repo/team is available | later |
| `un5` | C — DELETE `finish` / `driver-confirm` | do-now |
| `un6` | no change — all three parts verified already-correct | no action |
| `un7` | A — revenue = Primary balance; SyCash escrow → 0 | verify later |
| `un8` | A — Larastan report-only, cleanup after | do-now + follow-up |
| `un9` | B — fix the 19 sites, THEN arm the flag | do-now |
| `un10` | A — wallet required before top-up; fixtures are wrong | do-now |
| `un11` | A — full auth cache-DTO refactor | do-now |
| `un12` | A — proper k6 `setup()` harness | do-now |
| `un13` | A — clean account status + `BanService` | do-now |

---

## 2. The backlog

**GATES RE-DERIVED (`R2 sec 66`, 2026-10-03).** Every `Blocked by` cell below was stale: it was written before the
owner answered the 27 decisions, so **17 of the 21 unfinished rows were waiting on questions you had already
answered** and only 4 were genuinely waiting on you. Each cell now says which. Cells marked `ANSWERED` mean the gate
is cleared - they do **not** claim the remaining work was verified, and no row's `Status` was changed by this pass.
Cells marked `NOT re-verified` mean the gate moved but the code was not re-checked and still needs its own pass.


| Order | ID | Title | Pri | Status | Blocked by (owner decision #) | Aliases | Evidence (file section / commit) |
|---|---|---|---|---|---|---|---|
| 1 | V1 | Axis order: Damascus-Aleppo distance in MySQL | P0 | **SUPERSEDED** | n/a | feeds AF-4a, RV-24, RV-25 | R2 sec 128. Same defect as **row 42 (RV-25)**, which shipped the fix (migration `2026_10_06_000001_rv25_correct_transposed_ride_geometry.php`) and is VERIFIED FIX. Re-verified on MySQL 8.2.0 today: `POINT(lat lng)` = **309.00 km**, `POINT(lng lat)` = **257.93 km** - the exact pair this row recorded ("258 km not 309 km"). 4-test ratchet `RV25GeometryAxisOrderTest` passes. V1 itself did no work; closing it stops it being re-investigated from scratch. |
| 2 | V2 | Spatial index on `rides` | P0 | **VERIFIED FIX** | n/a | feeds RV-25 | R2 sec 129. Root cause: `2025_05_19_135630_create_rides_table` created `point()` columns NOT NULL with a `spatialIndex()` on each; `2025_05_20_143208_fix_ride_spatial_columns` **dropped those columns and recreated them as plain `geometry()`**, silently taking both indexes with them. Nothing ever re-added them. Migration `2026_10_12_000001_v2_restore_rides_spatial_indexes` restores one SPATIAL index per column and declares `SRID 4326` (the SRID the writers actually use; the column previously declared none while storing 4326 values). ALTER verified NOT to re-interpret stored ordinates, on populated rows. **Caveat: the app's `ST_Distance_Sphere` queries still do not use the index** - `possible_keys` is empty for them - so this fixes the schema debt, not the search cost; an MBR bounding-box prefilter would be the follow-up. |
| 3 | V3 | Route-buffer units (`ST_Buffer`) | P0 | **VERIFIED FIX** | n/a | feeds RV-25 | R2 sec 127. ST_Buffer on a LINESTRING is unimplemented in a GEOGRAPHIC SRS (MySQL 3618) and `ST_GeomFromGeoJSON` defaults to SRID 4326, so `applyRouteMatching()` raised on EVERY search that met a ride carrying `route_geometry` - a 500 for the whole endpoint, not a missed match. Relabelled the parsed route Cartesian with `ST_SRID(...,0)`; radius stays in degrees (0.05 deg ~= 5.5 km). `route_geometry` is client-settable via POST /rides, so this was reachable in production. |
| 4 | V4 | Client IP behind nginx | P0 | **SUPERSEDED** | n/a | feeds RV-05 | R2 sec 130. Both halves resolved by **row 21 (RV-05)**, VERIFIED FIX (R2 sec 15/sec 12). (a) "collapse CONFIRMED": `TrustProxies::$proxies` was null, so every `ip:`-keyed rate-limit bucket collapsed into one shared bucket behind nginx - fixed by config-driven `TRUSTED_PROXIES` (`config/trustedproxy.php`). (b) "spoof REFUTED": never a defect; the test pins that an untrusted source still cannot forge its IP and that nginx overwrites XFF rather than appending. `ClientIpBehindProxyTest` = 8 tests / 14 assertions, green. **RESIDUAL (ops, not code):** `TRUSTED_PROXIES` is unset by default, so a deployment that does not set it still collapses - now listed under Owner actions outstanding. |
| 5 | V5 | Validation `errors` bag stripped | P1 | **SUPERSEDED** | n/a | feeds RV-13 | R2 sec 131. Fixed by **row 27 (RV-13)**, VERIFIED FIX (R2 sec 20/20.1/20.3). The catch-all `renderable(Throwable)` matched `ValidationException`, mapped it to 422, then REBUILT the body as `{status,message,code}` - discarding `$e->errors()`. `Handler.php` now registers a dedicated `ValidationException` renderable AHEAD of the catch-all, which keeps Laravel's own shape (`message` + `errors` keyed by field) and leaves the rest of the catch-all untouched. Verified: `ValidationErrorBagTest` + `WaveZeroVerificationTest` (V5 recorder) + `CreateRideRouteTest` + `RV13DomainExceptionMaskingTest` = 25 tests / 62 assertions, green. The WaveZero V5 recorder was deliberately flipped by that fix and its comment says it must NOT be flipped back. |
| 6 | V6 | CI driver + skipped tests | P1 | **SUPERSEDED** | n/a | feeds AF-2', RV-18 | R2 sec 134. RESOLVED by RV-18. `sonar.yml` provisions a `mysql:8.0` service on 3306 and sets `DB_CONNECTION=mysql` at the PROCESS level (env:), which the committed `phpunit.xml` `<env> DB_CONNECTION=sqlite` without `force` cannot override â€” PHPunit honours an already-set variable, and Dotenv is immutable so `.env` cannot win either. `CI_REQUIRE_MYSQL=1` arms `CiMySqlDriverTest`, which fails the job red if the driver is not mysql and converts money/geo skips into failures (2 tests / 10 assertions, green here; needle previously recorded as RED with the leak re-injected). `|| true` was removed (T3-11), so a failing suite is a red job. |
| 7 | V7 | `SHOW REPLICA STATUS` under load | P2 | RECORDED | owner: no replica in this environment | feeds RV-28 | R1 sec 3, sec 9; R2 sec 31 - NOT RUN, the only unrecorded V |
| 8 | V8 | `config/system_admin.php` exists? | P2 | **SUPERSEDED** | n/a | feeds RV-19 | R2 sec 132. ANSWERED, no defect. `config/system_admin.php` does not exist and never did. What exists is `config/admin.php`, which is **wallet routing only** - three phone numbers plus wallet prefixes; its own header records that credentials were removed and now live in the `employees` table via `SpecialAccountSeeder`. So the R1 question resolves better than the finding assumed: there is no admin-credentials config file to leak. Both live readers (`Console/Kernel.php:97`, `VerificationRepository.php:67`) only read wallet phones. |
| 9 | V9 | `user_ratings` unique key | P3 | **SUPERSEDED** | n/a | feeds RV-30 | R2 sec 132. SATISFIED. `SHOW INDEX FROM user_ratings` on MySQL 8.2: `user_ratings_rater_id_rated_user_id_unique` carries `NON_UNIQUE = 0` on BOTH columns, i.e. a composite UNIQUE on `(rater_id, rated_user_id)`. Pinned by `RV30DataModelHygieneTest::user_ratings_keeps_unique_rater_rated_user_pair()`, whose assertion text warns that changing it to "one per ride" is a product decision. `rater_id` is nullable on purpose - MySQL does not conflict NULLs in a unique index, which is what allows system ratings at signup. |
| 10 | V10 | `POST /api/rides` method mismatch | P1 | **SUPERSEDED** | n/a | feeds RV-08, RV-14 | R2 sec 132. FIXED. Live route table: `POST api/rides -> RideController@create`. It previously pointed at `createRide`, which does not exist, so the documented create endpoint returned **500 for every caller**; `routes/api.php` labels the fix `RV-14/V10`. Pinned by `CreateRideRouteTest`. |
| 11 | V11 | GET vs POST on `rides/search` | P1 | **SUPERSEDED** | n/a | feeds RV-17, refines R2 sec 1.2(1) | R2 sec 132. RESOLVED. `routes/api.php:190-191` registers BOTH `GET /search` and `POST /search` to the same `RideController@searchRides`; confirmed in the live route table. Either method works, so there is no mismatch left to decide. |
| 12 | V12 | `auth()->id()` null under JWT | P1 | **SUPERSEDED** | n/a | feeds RV-36 (refutes its headline) | R2 sec 132. **REFUTED, not repaired** - so this row is not a duplicate of a fix. `JwtAuthMiddleware` calls BOTH `setUserResolver()` AND `Auth::setUser()`, so `auth()->id()` resolves; the premise was never true. `AuthFacadeRatchetTest` asserts no live bug - it enforces the house rule (take the user from `$request->user()`, not the stateless `auth()` helper), driven to 0 in `app/Http` and `app/Services`. The real defects in that controller were an ownership-scoping gap, fixed separately. |
| 13 | V13 | Suite with network blocked | P2 | **SUPERSEDED** | n/a | feeds RV-07, RV-37 | R2 sec 134. RESOLVED (environment half). `TestDeterminismRatchetTest` green on all five invariants: no test file writes the process environment, no real mailer, the test environment cannot reach the network, every DB-writing class is transactional, no test writes a tracked file. The 7 `putenv()` leaks V13 recorded are gone. |
| 14 | V14 | `--order-by=random` x3 | P2 | **SUPERSEDED** | n/a | feeds RV-37 | R2 sec 134. RESOLVED by RV-37 (`R2 sec 39`). Order-independence demonstrated across FIVE distinct orders (default, 20260929, 424242, 777001, +1), not the 2 the finding asked for. `sonar.yml` now runs the suite a second time with `--order-by=random --random-order-seed=$(date +%s)` and echoes the seed into the log, with no `|| true` and no `if: always()`. **CAVEAT:** the workflow itself has still never executed end-to-end in CI, so this is verified locally, not by a green CI run. |
| 15 | V15 | Group suite errors by message | P1 | **SUPERSEDED** | n/a | feeds RV-34 | `R2 sec 136`. DELIVERED: the grouping was produced on real data rather than asserted. `tests/Unit/Services` alone = 67 problems in **6 distinct root causes**, not the 2 R1 predicted. `tests/Feature/Rides` is now 0 failures. Remaining unowned groups filed as RV-47 |
| 122 | RV-47 | Grouped unowned test failures in tests/Unit/Services (6 root causes) | P1 | PARTIAL | owner: RV-49, RV-50, RV-51, RV-52, RV-46 | V15, cash fee | `R2 sec 143`. PARTIAL: two causes VERIFIED FIX (708d1ff signature drift; 9d8ee7b AdminWalletService seed). AdminDriverServiceTest = stale tests (sec 137). Remaining red is owner-blocked: RV-46 (row 121), RV-49 (124), RV-50 (125), RV-51 (126), RV-52 (127). No code work remains without an owner decision. |
| 16 | V16 | All-seats cancel vs `cancelBooking` | P1 | **SUPERSEDED** | n/a | feeds RV-02 L1 (refuted), RV-09, decision 14 | R2 sec 132. EQUIVALENT, verified not assumed. `CancelSeatsEquivalenceCheck` measures cancelling EVERY seat through `POST /bookings/{id}/cancel-seats` against `cancelBooking()` across both payment branches: e-pay (refund/escrow settlement rows written, score path a documented no-op) and cash (zero refund, observable score penalty -5 mid / -10 late). **2 tests, 7 assertions, green** - money and score agree on both paths. |
| 17 | RV-07 | Committed secrets and stale credentials | P0 | VERIFIED FIX | n/a | RV-37 (V13 leaks), T1-3/T2-8 (same class) | R2 sec 10, sec 12; 0c0ea3c - agent-side only; rotation+history purge recorded sec 5 |
| 18 | RV-06 | Horizon dashboard unauthenticated; nginx upstream leak | P0 | VERIFIED FIX | n/a | - | R2 sec 11, sec 12; 896c331 |
| 19 | RV-01 | KYC documents exposed (IDOR + public storage + unsafe names) | P0 | VERIFIED FIX | **CLOSED (`R2 sec 102`) - no owner action remains.** The move is no longer a deploy ritual: `documents_disk` now DEFAULTS to the PRIVATE `local` disk, so a fresh deploy cannot leak, and `docker/start.sh` runs `kyc:migrate-disk --from=public --force` on every boot (idempotent, non-fatal), so the already-written files are retired automatically instead of by hand. | supersedes AF-5's scope; feeds RV-31 |  `R2 sec 13`, `sec 1.1`; 95ad30d; 0c6a1f6; `R2 sec 97` (mover built + needled). **`R2 sec 102` - CLOSED.** 1b was already shipped (`routes/api.php:419-425`, `066b1dd`). The documented flip was found UNSAFE on its own: `StaffDocumentController:85` reads through the same key, so setting `DOCUMENTS_DISK` without moving bytes 404s every staff KYC view - so the reader now falls back to the legacy public disk READ-ONLY, which keeps existing rows readable while the exposure shrinks to zero. `uploads_disk` deliberately STAYS `public`: profile photos and chat images are meant to be public, and collapsing the two keys would break every avatar. `kyc:migrate-disk` `--from` default corrected to `public` (it resolved to `documents_disk`, which after this change would have made the migration a silent no-op that reports success). NEEDLE: restoring `DOCUMENTS_DISK=public` -> 5 failures, SHA256 restore. `RV01DocumentsPrivateByDefaultTest` 7 tests, `AF5ConfigurableDiskTest` updated deliberately. Bisect on the identity floor: 914 tests / 7 errors / 12 failures -> **926** / **5 errors** / 12 failures: **zero new, and 2 pre-existing errors FIXED** (they travelled the broken read path) |
| 20 | RV-04 | JWT integrity (staff->user replay, empty secret, TTL) | P0 | VERIFIED FIX| **9 - ANSWERED (B: keep the 600-min TTL).** All THREE items are done and PROVEN, not merely asserted in a comment (`R2 sec 93`): (a) staff->user replay closed by the `sub_type` guard in `JwtAuthMiddleware:53` and proven by `RV04TokenAudienceTest` (commit `85643e9`); (b) empty JWT secret refused by the boot guard in `AppServiceProvider:372`; (c) TTL shipped at `config/jwt.php:39` (`env("JWT_TTL", 600)`)| **none.** The recorded blocker "AF-4b (Sanctum already gone)" was never a real gate - Sanctum is absent (`config/sanctum.php` does not exist, re-checked at `3443979`), and T2-9 is closed on the same finding| R2 sec 14, sec 12; d5043b5 (replay + boot guard), 85643e9 (audience separation proven). **`R2 sec 93`: status was STALE - all three items had been complete for some time; identity area floor 419 tests re-run, the 12 problems there are pre-existing and unrelated (filed as row 111)**|
| 21 | RV-05 | Client IP wrong behind nginx | P0 | VERIFIED FIX | n/a | V4 | R2 sec 15, sec 12; 09e86c5 - both halves; `TRUSTED_PROXIES` value is ops (sec 31) |
| 22 | RV-02 | No-show settlement can pay twice / mispay (L1 + L2) | P0 | **VERIFIED FIX** (both halves) | **D1 = A ANSWERED 2026-10-04** - BUILT: unique `wallet_transactions.posting_key` + per-booking `escrow_held` with guarded decrements at all 8 escrow sites. 95/5 split and every refund tier UNCHANGED. L1 closed (`ba45e4b`); **L2 closed `R2 sec 81`** - 12 new tests, 3 needles both directions, controlled bisect 623 tests / 11 failures at HEAD vs 623 / 11 after, failure NAME SET identical. NOTE: the acceptance text below still says `SyCash == SUM(escrow_held)`; the e-pay form is what the test pins, and `sec 77` + `sec 81` record why | L1 refuted by V16; L2 = AF-6/Wave 3; consumes `void` enum (RV-40) | R2 sec 16 (L1 VF), sec 26.14 item 3 (L2 0%, Wave 3 PAUSED), **sec 77 (execution map; 2 corrections recorded in sec 81)**, **sec 81 (L2 VERIFIED FIX)**; ba45e4b; **`abd8634`** |
| 23 | RV-03 | Staff cancel endpoints strand money, no role gate | P0 | VERIFIED FIX | n/a | RV-02, RV-09 | R1 sec 4 RV-03; R2 sec 12; **R2 sec 47 (decision 6: full refund + no driver score penalty, `364cc0c`)**; **R2 sec 59 (owner choice (a): money endpoints gated to `staff:admin,system_admin`, `b64ef96`; 82 tests, denial pinned incl. role-check-before-state)** - both halves CLOSED |
| 24 | RV-34 | Shared test-support layer (both red root causes) | P1 | VERIFIED FIX | n/a | V15; T4-1 harness | R2 sec 17, sec 17.4; 5414344 - 443 -> 71 errors `R2 sec 106`: the support layer's own test asserted TWO system wallets while `SeedsSystemWallets` has seeded THREE since decision un3 added the EXTERNAL capital account. Stale pin, not a product bug; corrected to derive the count from the trait and to assert the external wallet's phone, which is STRONGER than before|
| 25 | RV-37 | Test determinism and hermeticity | P2 | VERIFIED FIX | n/a | V13, V14; RV-18 (CI order) | R2 sec 23, sec 23.6 correction, sec 39 VF (order) + 39.1 VF (hermeticity) + 39.2 (tracked-file ratchet + CI double-run) + 39.3 (CI executed; pre-existing CI install breaks fixed); cd6a49a + c45e05e + a087342 + eaa7f14 + the closure commit. Closed by owner instruction 2026-10-03 `R2 sec 103`: the RV-37 ratchet was RED against this very row's own test - `RV08ReplicaPortTest` called putenv(). Removed, and the environment made hermetic properly by disabling Laravel's PutenvAdapter for the test rather than writing to the process env (dropping the calls alone would have let an unset var fall through to the ambient shell). NEEDLED; Review floor 5 -> 4 failures|
| 26 | RV-18 | CI signal (V6 driver leak, migration guards) | P1 | VERIFIED FIX | n/a | V6, AF-2' | R2 sec 24, sec 24.2 VF; 51b6ca7 - whole-suite red is separate debt (sec 24.1) |
| 27 | RV-13 | Error model (envelope, domain exceptions, leakage) | P1 | VERIFIED FIX | un4 ANSWERED (C: later); (b) DONE; **(a) DONE (`R2 sec 79`) - D3 = A: DomainException messages were leaking in production while \InvalidArgumentException masked; now masked + logged, shape and code unchanged. This UNBLOCKS the 61-site migration as behaviour-preserving** | V5; RV-33 ratchet | R2 sec 20, sec 20.1, sec 20.3; `980741c` - **LEAKAGE HALF CLOSED, ratchet at ZERO**: R2 sec 64 `f9f536a` (Domain exceptions, domain violation 500 -> 422/403/409), sec 64.1 `a1c072c` (leak measured 47, shrink-only ratchet), sec 64.2 `3bfdaa4` (Chat 47->42), sec 64.3 `8d92b60` (ratchet was OVER-BROAD, 42->21), sec 64.4 `7a01fa5` (all non-owner controllers 21->15), sec 64.5 `5fb1a4b` (owner-owned RideController 15->0); needle proven both ways, 211 tests / 321 assertions / 4 failures IDENTICAL to pre-sweep - **`R2 sec 68` remainder (b) CLOSED**: the `$e->getCode() ?: 422` code-as-status channel is gone from `WalletRequestController` (4 sites), `WalletRequestService` now throws the typed hierarchy with **statuses UNCHANGED (422/409)** and shape UNCHANGED; new zero-baseline ratchet `RV13ExceptionCodeAsStatusTest` that self-tests its own detector; needle both ways + byte-identical restore; regression by controlled bisect 576/39E/27F at HEAD vs 583/39E/27F after, failure-name-set diff EMPTY both ways |
| 28 | RV-14 | Route/controller mismatches, lying endpoints | P1 | VERIFIED FIX| **D6 = B ANSWERED and corroborated from the Flutter client (`R2 sec 85`)** - ORS/GraphHopper return metres+seconds, the client divides by 1000/60 only for local fare and display, so server-derived metres are authoritative. The last open item, the `??`-inside-the-guard quirk, is DONE (`R2 sec 92`)| **none** (`R2 sec 91`, `sec 92`). Both original gates are gone: V10 was discharged earlier (`routes/api.php:203` routes to `create`), and the `??` quirk that the row was actually waiting on is now fixed. **Latent, not observed:** the client sends none of the three fields, so nothing in production tripped it - but the endpoint is public and accepts them, and `distance` drives the fare| R2 sec 21, 21.1, sec 62, **sec 85 (D6 resolved)**, **sec 92 (the `??` quirk VERIFIED FIX - a ride could be stored with a real server geometry beside a client distance of 0; needle proved 0.0 was stored, complement test proves complete client data is still kept and the routing service is still not called, bisect 647 tests 10F -> 8F with ZERO new failures)**; 1c18c07, 3616636|
| 29 | RV-16 | OTP and mail flows | P1 | **VERIFIED FIX** | 4, 8 - ANSWERED; **all acceptance criteria discharged** (only a deploy action remains) | RV-31 (mailers), T2-3/T2-4 | R2 sec 22, sec 22.4, sec 22.5, sec 29 (plaintext -> HMAC); cac4084 + d776c1f; **`R2 sec 69`** (signup mail leaves the DB txn); **`R2 sec 72`** (decision 8 APPLIED: 4 enumeration oracles closed, resend needed 2 edits) |
| 30 | RV-22 | TLS and log hygiene leftovers | P1 | VERIFIED FIX | n/a | AF-1 (2/3 landed); V13 | R2 sec 26.15, sec 26.16 VF; 4239087 + b34df62 - slice 3 is deploy-surface (sec 30 item 2) |
| 31 | RV-36 | Notification endpoints no-ops under JWT | P1 | VERIFIED FIX | n/a | V12 refuted the premise | R2 sec 25 VF; 2d6a55e - real defect was existence-oracle + silent no-op |
| 32 | RV-38 | Eloquent strictness outside production | P2 | VERIFIED FIX | n/a | RV-24 (arming), T4-1 | R2 sec 29.2 VF (2 flags), sec 29.5 ROLLBACK, sec 35 ROLLBACK (premise), sec 36 VF (arming), sec 37 OFF; R2 sec 44 + 44.1 - sites fixed, flag ARMED, red set identical to flag-off baseline (87 entries both ways); 00f7b9d + 5bbadad |
| 33 | RV-35 | Stale and bug-pinning tests (inventory) | P2 | VERIFIED FIX | n/a | decision 12, sec 19.3 schema question | R2 sec 19, sec 19.5 VF; d381a7a - 71 -> 52 errors; inventory is the deliverable |
| 34 | RV-08 | Deploy pipeline is broken | P1 | VERIFIED FIX | **none. The gate is discharged.** Placement was decided as 7 = (B: Render, shipped `2114308`), and the remaining owner question - keep or delete the superseded VPS/compose files - was ANSWERED 2026-10-11: **keep the files, fix the defect on the chosen target.** `render.yaml` defines web + worker + cron and `deploy-to-vps.yml` is `workflow_dispatch`-only. **The status was STALE until 2026-10-12 (`R2 sec 121`): this row read BLOCKED while its own Evidence already recorded the owner decision as fully carried out.** A real defect on the CHOSEN target was found and FIXED this task (`R2 sec 96`) - see Evidence | **ANSWERED 2026-10-11 - DO NOT RE-ASK: the superseded VPS/compose files are KEPT, and the known bug in `deploy-to-vps.yml` was fixed instead (`R2 sec 118`, commit `2eef93f`).** The earlier text asked "delete the superseded VPS/compose files?" and reserved the call for the owner because `AGENTS.md` reserves "deleting code or tests". The gate text used to say "OWNER: deploy TARGET" - **that was WRONG**, since decision 7 already chose Render (`R2 sec 96`). The remaining files are `docker-compose.yml`, `deploy-to-vps.yml`, `docker/start.sh`, `docker/nginx-docker.conf`, `docker/mysql/replica-init.sh`, `nginx-docker.conf` | `R1 sec 4 RV-08`, `R2 sec 96`. **VERIFIED FIX for a real defect:** `DB_REPLICA_PORT` was set in THREE places (`render.yaml:84` as a required `sync:false` secret, `phpunit.xml:43`, `scripts/scratch-env.ps1`) and read in NONE - `config/database.php` honoured the replica HOST but not the PORT, so production reads hit the replica host on the PRIMARY port. Fixed at `config/database.php` (`read.port`, mirroring the host guard: production only, falls back to `DB_PORT`); Laravel merges the whole `read` array over the base config (`ConnectionFactory:153`), so that is the correct place. `RV08ReplicaPortTest` 5 tests / 13 assertions, NEEDLED: 4 of 5 fail with the fix reverted, SHA256-proven restore. Config floor 68 tests - the only failure is the pre-existing `controllers_to_models` 21-vs-20 proposal (`R2 sec 95`), zero new. **The Render path is otherwise verified coherent:** `Dockerfile:83` copies to `/start.sh`, `:93` is the CMD, `start.sh:67` runs `migrate --force`, `routes/web.php:22` serves the `/up` health check the blueprint depends on. **THE KNOWN BUG IS FIXED (`R2 sec 118`) - the owner decision (keep the files, fix the defect) is now fully carried out.** `deploy-to-vps.yml:94,97` ran `docker compose exec -T app`, and **`docker-compose.yml` defines no `app` service** (they are `app1`-`app5`, `nginx`, `redis`, `mysql`, `mysql_replica`, `minio`, `queue`, `scheduler`), so the step could never have succeeded if dispatched. Both now target **`app1`**, the ONLY service carrying a `build:` block (`:24-26`) - `app2`-`app5`/`queue`/`scheduler` name `image: syride_octane:latest` with no build section and exist only because `app1` built that tag. **The deliverable is the ratchet, not the two-line edit:** a dead `workflow_dispatch`-only path is exactly why the defect survived. New `tests/Feature/Review/DeployWorkflowServiceRatchetTest.php` (3 tests / 10 assertions, 0.031s, no DB) parses `docker-compose.yml` with `symfony/yaml` and asserts every `docker compose exec` / `run` target across all six workflows is a service it defines; plus its **NEGATIVE CONTROL** (the scanner must find `app1` and must NOT find `app`, or it could pass on a parse that found nothing) and a **PREMISE GUARD** that the workflow is still `workflow_dispatch`-only with no `push`/`schedule` trigger - because if a push trigger returns, this row's "hygiene on a dead path, cannot affect production" premise becomes false. **NEEDLED:** reintroducing `exec -T app` fails 2 of 3 (the trigger test correctly stays green); restore SHA256-identical. Review floor 492 tests / 1738 assertions / no failures; all 6 workflows still parse as YAML. **Deliberately NOT claimed: that the VPS path works** - no VPS, production is Render, no docker daemon in the sandbox. **Status corrected to `VERIFIED FIX` on 2026-10-12 (`R2 sec 121`):** this row had read `BLOCKED` while its own Evidence above already recorded the owner decision as fully carried out, and `Zero decision-free code remainder remains on this row`. The lingering note that the row "stays BLOCKED" because the `Blocked by` column names the superseded deploy target is withdrawn - that column no longer carries a gate. The superseded VPS/compose files remain in the repo BY OWNER DECISION (kept, not deleted), which is a deliberate end state, not an open blocker. **Zero decision-free code remainder remains on this row.** |
| 35 | RV-40 | Money schema additions (prereq for RV-02 L2, RV-09, RV-15) | P1 | VERIFIED FIX | n/a | AF-6 | R2 sec 26.1 VF, sec 26.12 VF (price width), sec 26.13 VF (one report/booking); caebbfa + f4d1df8 + 53fe8d1 R2 sec 54 (decision 13: 18 DB ENUM columns -> varchar, `e93f3c5`, verified up+down, fail-loud rollback) `e93f3c5`; |
| 36 | RV-09 | Money concurrency and side-effect ordering | P1 | **VERIFIED FIX** | 3 - ANSWERED. **ALL THREE ITEMS NOW CLOSED.** (c) "the real throughput fix" WAS RV-02 L2 - VERIFIED FIX (`R2 sec 81`). (a) VERIFIED FIX (`R2 sec 84`): strategies no longer swallow + `attempts: 3` on 14 money sites. **(b) "events carrying ids not models" CLOSED AS NOT-A-DEFECT (`R2 sec 88`), on evidence not opinion: the codebase has exactly ONE event listener (`EventServiceProvider::$listen` has a single entry and `shouldDiscoverEvents()` returns `false`), that listener reads only `$event->user->id` and a string, and the other five events have NO listeners at all - they are broadcast-only. So no listener can act on stale model state. Implementing (b) would change SIX public WebSocket payloads the Flutter client consumes, for zero demonstrable benefit** | n/a | R2 sec 26.3, sec 26.14, sec 81, sec 83, **sec 84 (a)**, **sec 87 (refund semantics pinned)**, **sec 88 (b closed)**; caebbfa, abd8634, e3920c3 |
| 37 | RV-10 | Ride lifecycle and escrow liveness | P1 | BLOCKED | **ONE owner ACTION, not a decision: run `php artisan escrow:stuck-report` against PRODUCTION and name window W** - the number of minutes after which stuck escrow escalates to the staff queue. W cannot be derived from the repo, because it is an operational threshold and the reporter itself is what produces the data. Everything decision-free on this row is DONE and NEEDLED (`R2 sec 99`, `RV10StuckEscrowTest` 10 tests / 32 assertions; swapping the money correlation for a type-name correlation fails exactly 5). Remaining after W: the staff-queue escalation half. Re-statused from PARTIAL 2026-10-12 (`R2 sec 122`): PART overstated it as "half-done code" when the remainder is one owner read. | AF-4e (config windows); T1-1 | `R2 sec 26.7` (search guard VF, rest owner-gated), sec 26.14; caebbfa. **`R2 sec 99` - the reporter existed nowhere; built and verified.** `escrow:stuck-report` correlates escrow by MONEY MOVED (net of `new_balance - previous_balance` per `reference = booking:{id}`, on the SyCash wallet only), NOT by the settlement type name - because `LedgerType` documents that the vocabulary has already drifted three times, and escrow leaves through payout, refund, time-based, no-show and cash-ride paths. `RV10StuckEscrowReportTest` 10 tests / 32 assertions, OK. **NEEDLED:** swapping the money correlation for a type-name correlation fails exactly 5 tests (paid-out, REFUND-with-no-release-leg, no-show, partial settlement, passenger-wallet leg) - the refund case is the one the design exists for. First needle attempt was malformed (PowerShell quoting emitted a bare PHP constant) and was reported inconclusive and redone with a `php -l` gate. Bisect: 307 tests / 13 failures -> 317 / same 13, **zero new**. `php -l` both files; `pint --test` PASS after auto-fix. Removed a defensive NULL-balance path that the NOT NULL schema makes impossible - now pinned by its own test |
| 38 | RV-11 | Score subsystem internally inconsistent | P1 | VERIFIED FIX | **none** - was gated on un2 + `R2 sec 67`, which answered the policy question `R2 sec 1923` required. Now DONE (`R2 sec 74`) | T1-1 state machine | R2 sec 26.9 PARTIAL ~35% (dead `applyScore` deleted), sec 26.14; `caebbfa`, `de61c7b` (un2 policy) - **R2 sec 67: the ride double-count and the 3 policy defects are FIXED** (owner 2026-10-03: a ride counts ONCE). Before the fix: `applyAction:277` + `recordRideCompleted:52` counted every completed ride TWICE (inflating `cancel_rate`, so the 50% high-cancel gate under-fired); `firstOrCreate` used 100 not the pinned 70; no ceiling; a dead `cancel_rate` write. Verified: needle both directions, 12 new tests pin the GATE not just the arithmetic, 275 tests / 451 assertions / 4 failures = the same 4 as sec 64.5, zero new; **`R2 sec 73`** (criterion corrected: `ScoreLedger` did not exist); **`R2 sec 74`** ScoreLedger built, -172 lines in ScoreService, 9 tests, bisect zero new, 2 needles both directions. A claim that the no-show rows denied a fired gate was WRONG - only the two CANCEL policies set that flag - and the test asserting it was corrected, not the code |
| 39 | RV-15 | Booking idempotency | P1 | VERIFIED FIX | n/a | RV-02 L2 `posting_key` | R2 sec 26.2 VF; caebbfa |
| 40 | RV-21 | Wallet identity and money creation | P1 | VERIFIED FIX | n/a | RV-39 (seeder half), AF-6 | R2 sec 26.5 PARTIAL, sec 26.11 VF (seeder half); un10 fixtures `bff1d3e`; R2 sec 56 (`wallets.kind` + DB triggers, `ae09981`); R2 sec 57-60.1 (double-entry: `ledger_entries` + `LedgerService`, every money path converted, External Capital account closes the external flows, `ledger:reconcile` scheduled daily) `3be510a`; R2 sec 61.1 closes the row - no money movement remains that the ledger cannot explain |
| 41 | RV-20 | Payment strategy one-third wired | P2 | VERIFIED FIX | none | ~~RV-02 L2~~ discharged; T2-1 VF; RV-09(a) DONE; **refund half = OWNER DECISION** | R2 sec 26.4, sec 26.14 item 4, sec 83, **sec 84 (precondition met)**, **sec 86 (charge VF + refund blocker)**, **sec 87 (set-level refund semantics pinned, tests only - the aggregate guard, set idempotency and one-row-per-set were previously UNPINNED, so a naive per-booking rewiring would have shipped green)** **`R2 sec 110`: refund half DONE.** `processRefund` re-shaped to `(Ride, Collection, $reason)` per the owner choice - NOT split. `reason` selects between the two real set-level paths (`refundPassengersForDriverCancellation` = 100%, `refundPassengersForStaffCancellation` = policy off `amount_paid`) and an UNRECOGNISED reason is REFUSED, never defaulted. `RV20RefundReasonRoutingTest` 10 tests / 17 assertions. NEEDLE: restoring the pre-RV-20 behaviour (reason ignored, always the driver path) kills exactly the 5 reason-sensitive tests and leaves the other 5 - the driver path itself is unaffected|
| 42 | RV-25 | Search correctness and cost (geometry axis order) | P1 | VERIFIED FIX | n/a | **AF-4a**; V1, V2, V3 | R2 sec 29.6, sec 29.7 VF, sec 29.8 VF (GeoPoint), sec 29.9 VF; 52108b7 + 69c032f + 1af0440 |
| 43 | RV-24 | Ride payload and N+1 (coordinate reads) | P1 | VERIFIED FIX | n/a | RV-38 arming; V2 | R2 sec 29.4 VF; b84cea0 |
| 44 | RV-17 | Load-test validity | P1 | BLOCKED | **Needs a corrected load-test run on a PRODUCTION-SHAPED target.** The contract half is done (`R2 sec 29.3`, sec 51, sec 29.3.1 with commit-SHA provenance and `dbwatch.sh` DB-pressure capture, `ff8e6f0`/`747358f`); un12 is ANSWERED and APPLIED. What is missing is a machine shaped like production (real DB pressure, not the scratch box) - no such host is reachable from the audit environment. Re-statused from PARTIAL 2026-10-12 (`R2 sec 122`): the remainder is an environment, not code. | V11; RV-18 (CI) | R2 sec 1.2(1) correction, sec 29.3 "VERIFIED FIX (contract half)"; 56c989a R2 sec 51 (un12: setup() login, constant-arrival-rate, 3 runs, per-endpoint thresholds) `ff8e6f0`; R2 sec 29.3.1 (commit-SHA provenance + `dbwatch.sh` DB-pressure capture) `747358f`; |
| 45 | RV-12 | Account status model (temporary ban lock-out) | P1 | BLOCKED | **OWNER - decision D7 was ANSWERED 2026-10-04 (option A) but has NEVER BEEN BUILT. Both audited halves are unbuilt, re-verified on disk 2026-10-12 (`R2 sec 122`):** (1) *"stop persisting status=0"* is NOT done - `SignupController:144` still writes `status => 0` at sign-up, with `:141-143` explicitly defending it; (2) *"migrate 0 rows to 1"* has NO migration - the only migration updating rows on the `users` table is the mojibake repair (`2026_10_12_120000`). **Two SAME-DAY RULINGS ALSO CONFLICT here:** `7ab1eff` (`R2 sec 61.2`, also 2026-10-04) deliberately shipped the OPPOSITE behaviour and is pinned by 4 tests, and D7=A cannot be applied literally without editing them (`SignupPasswordOverwriteTest:184`, `AccountStatusBanServiceTest:152`, `AdminBanControllerTest:249,308`). The owner must decide WHICH 2026-10-04 ruling governs. Re-statused from PARTIAL 2026-10-12: the old cell read "ANSWERED" as if answered meant done. | RV-31, RV-29 | R2 sec 27 "VERIFIED FIX (decision-free core); R1's model refactor stays PARTIAL"; 1173a69 R2 sec 55 (`BanService` merges the 3 divergent un-ban copies); R2 sec 61.2 (found and fixed a DEAD DEFENCE: `createUser` hardcoded `status => 1`, silently discarding SignupController's deliberate `status => 0` sign-up defence, `7ab1eff`) `7ab1eff`; |
| 46 | RV-26 | Staff/admin authorization matrix | P2 | GATED | 1a - ANSWERED (A); row stays GATED on purpose: do not "fix" a refuted premise | **headline refuted by T2-2's own fix text** | R2 sec 28.1 GATED; no commit |
| 47 | RV-27 | Push pipeline cannot deliver | P1 | VERIFIED FIX | n/a | T3-14, T2-8 | R2 sec 28.2, sec 28.5 VF (decision-free core); 364c3db - FCM keys = ops, sec 5 |
| 48 | RV-29 | Auth hardening batch | P2 | BLOCKED | **OWNER - decision 5 (driver phone visibility), DEFERRED BY THE OWNER DELIBERATELY.** Four slices are DONE and verified (`R2 sec 28.6`, sec 28.9, sec 29.10, sec 34 ratchet, sec 48; `bc6acaa`/`4185af3`/`1d68c07`/`8732a0e`). Decision 5 is the only remaining item and it is a visibility/privacy call the owner has not made. Re-statused from PARTIAL 2026-10-12 (`R2 sec 122`): `BLOCKED` names the gate truthfully; PART implied code work that does not exist. | **T4-5** item 4, AF-4b | R2 sec 28.6 slice1 VF, sec 28.9 slice2 proven/gated, sec 29.10 item3 VF, sec 34 VF (ratchet); bc6acaa + 4185af3 + 1d68c07; R2 sec 48 (un11: auth cache DTO - password hash no longer reaches the cache backend, auto-lift hazard closed) `8732a0e`. Remaining item is decision 5 (driver phone visibility), deferred to the owner. |
| 49 | RV-19 | Fake or derived numbers in admin | P2 | VERIFIED FIX | none | **OWNER ANSWER NEEDED - ONE LINE (`R2 sec 94`).** V8 was its only gate and is gone, so nothing else blocks it. `AdminDriverService:335` computes `total_earnings` as a DERIVED figure - `SUM(seats * rides.price_per_seat * 0.95)` re-read from the CURRENT rides columns, NOT from the ledger, which is exactly the "fake or derived number" this row is named for. D5 = C says "add TWO clearly-named fields (ledger earnings + estimated gross), keep `total_earnings` as an ALIAS" but does not say WHICH SOURCE each field reads. Choosing the ledger as the authoritative earnings figure is a money-semantics call, and adding fields to this response is a public API shape change - both are reserved for the owner. **Recommended: `ledger_earnings` = sum from `ledger_entries` (RV-21 already builds and reconciles it), `estimated_gross` = today's derived value relabelled honestly, `total_earnings` = alias of `ledger_earnings`** - say yes and it is a two-line change plus tests| R2 sec 28.4, sec 28.7 VF (slice 1), sec 30 item 6; **`R2 sec 71`** (95/5 split unified; ride-level settlement no longer throws on odd-tenth prices) **`R2 sec 109`: item 2 IMPLEMENTED** (owner: "ledger is authoritative"). `ledger_earnings` sums real recorded payouts; `estimated_gross` keeps the old arithmetic relabelled honestly; `total_earnings` is an ALIAS of `ledger_earnings`. **Source is `wallet_transactions`, not `ledger_entries` - see sec 109, the ledger structurally cannot answer "earnings".** Baseline 23 failing -> 23 failing, zero new, tests 269 -> 273. NEEDLE: reverting only the service kills 7 of the 8 earnings tests|
| 50 | RV-23 | Complaints (context, routing, notifications) | P2 | VERIFIED FIX | n/a | RV-31 | R2 sec 28.3 slice1 VF, sec 28.8 slice2 VF; c59fed6 + bfc6fd6; **R2 sec 63 - the three recorded remainders verified satisfied: (a) the `GET` auto-transition is the owner's INTENDED transparency feature (un6), (b) each party is notified exactly once (no duplicate), (c) public `no_show` rejection is already pinned by `test_store_rejects_the_internal_no_show_type` citing the owner decision. No code change needed; 73 tests OK** |
| 51 | RV-28 | Infrastructure hardening | P2 | VERIFIED FIX | n/a | V7 (never run); RV-05 | R2 sec 31 VF (app/config core); 8ece5af - infra halves are deploy-surface, sec 5 |
| 52 | RV-30 | Data model hygiene | P3 | VERIFIED FIX | n/a | V9 | R2 sec 32 VF (index + `down()` correctness); c785f73 - recorded items sec 5 |
| 53 | RV-31 | Dead code wave (grep-confirm each before deleting) | P3 | VERIFIED FIX | n/a | RV-16 (mailers), T4-7, AF-4c | R2 sec 33 VF + recorded classification; 09c5c58 |
| 54 | RV-32 | README / docs corrections | P3 | VERIFIED FIX | n/a | T4-6 | R2 sec 30; e878f25 (+23/-10) |
| 55 | RV-33 | Ratchet additions (`BoundaryDependencyTest`) | P2 | VERIFIED FIX | n/a | AF-2', AF-7 (Larastan baseline) | R2 sec 30; 97792b6 (`RV33BoundaryDependencyTest`, 6 ceilings) |
| 56 | RV-39 | Seeders (prod guard, filename/class, truncate, vocabulary) | P2 | VERIFIED FIX | n/a | AF-4f (trait), RV-21 (escrow seeder), T3-9 | R2 sec 38 VF; 094479a `R2 sec 104`: the completeness ratchet was RED - `ledger_entries` carries FKs into both `wallets` and `wallet_transactions`, both truncated, so every seeded run left orphaned ledger rows and stale balances. Added to TRUNCATE_TABLES (children first); NEEDLED `R2 sec 105`: the ledger-TYPE ratchet also green - owner decision 2026-10-08 was to add the four already-written values (`external_inbound`, `external_outbound`, `staff_cancellation_refunds`, `staff_cancellation_refund`) as first-class cases rather than rename writers onto existing ones, so no row needs a data migration. NEEDLED; money floor 186 tests, 0 new|
| 57 | AF-1 | TLS honesty + Octane upload hygiene | P0 | VERIFIED FIX | n/a | **-> RV-22** (absorbed, 2/3 landed) | A sec J AF-1 VF; b9643f1 - R1 sec 2 A3.3 re-scoped it into RV-22 |
| 58 | AF-2 | CI gates: Pint + Larastan + tests-required | P1 | SUPERSEDED | n/a | renamed/extended as **AF-2'** | A sec D7, line 518 (`Next: AF-2`), sec F.5 |
| 59 | AF-2' | Modularity foundation: context map + ratchet + CI gates | P1 | VERIFIED FIX | n/a | **-> RV-18/V6**, RV-33, AF-7 | A sec J AF-2' VF; 92f454e - `ARCHITECTURE_MAP.md` + `BoundaryDependencyTest` |
| 60 | AF-3 | Hide/delete `Test*` debug commands | P2 | SUPERSEDED | n/a | **= AF-4f** (prod guard supersedes "hide") | A sec J snapshot line 691; R2 sec 3 RV-39 (same guard owed to seeders) |
| 61 | AF-4 | Un-tangle the dead-but-wired set | P1 | VERIFIED FIX | n/a | parent of AF-4a..AF-4f | A sec J AF-4 VF (both owner decisions applied); 2687872 |
| 62 | AF-4a | Search swap + the three bugs it exposed | P1 | VERIFIED FIX | n/a | **<-> RV-25**; V1/V2/V3 | A sec J 4a; 2687872 - its "397 km" proof was axis-order-agnostic (R2 sec 1.2(2)); V1 decided |
| 63 | AF-4f | Production-guard trait on state-forging commands | P2 | VERIFIED FIX | n/a | **= AF-3**; owed onward to RV-39 | A sec J 4f (5 commands); 2687872 |
| 64 | AF-5 | Shared object storage (`FILESYSTEM_DISK=s3`) | P0 | VERIFIED FIX | **none.** Re-verified on disk 2026-10-12 before closing (`R2 sec 122`): `config/filesystems.php` defines `s3` (`:47`) and `minio` (`:84`), `documents_disk`/`uploads_disk` are config-driven (`:148`), and all 4 call sites read the config (`FileUploadService`, `ComplaintService`, `ImageMessageType`, `MigrateKycDocumentsCommand`). **The only remaining `disk('public')` literals are `StaffDocumentController:99,102`, and they are CORRECT** - a deliberately documented READ-ONLY legacy fallback so pre-migration rows stay readable while `kyc:migrate-disk` retires the publicly-reachable copies; nothing is ever written back to `public`, so exposure can only shrink. The privacy half closed WITHOUT object storage (`R2 sec 102`, RV-01) and `R2 sec 89` fixed the half-wired switch. **Scope boundary, stated plainly:** the actual migration of data to S3/MinIO is NOT done and is explicitly NOT required - no privacy requirement depends on it and it is optional until the app runs on more than one host. What is verified is that the storage layer is correctly SWITCHABLE, which was this row's deliverable. | RV-01 (its own storage half remains: existing public URLs resolve until files move) | `R2 sec 1.1`, `sec 89` (call-site move VF + half-wired switch fixed); 3e9a304; `R2 sec 97`; **`R2 sec 102` - the privacy half is now closed WITHOUT object storage**, so the bucket stopped being a gate and became an optional scaling step. Nothing here requires creating a bucket or supplying S3/MinIO credentials **re-scoped by RV-01**; ROADMAP sec F.1, A sec I truth 1 A sec J snapshot line 693; R1 sec 2 A3.1 + R2 sec 1.1 - the fix as written is WRONG (disk is hard-coded `'public'`) |
| 65 | AF-6 | Money module (`Money` VO, one `LedgerEvent`, `ledger:reconcile`) | P1 | BLOCKED | **NONE of its own - blocked ONLY on RV-10 (window W).** Its own Evidence states "No code task remains on this row" and that criterion 4's seventh alias is `RV-10`, which is BLOCKED on an owner action. **The stale part of this cell, corrected 2026-10-12 (`R2 sec 122`):** it used to ask the owner for the signed-`Money` design decision. That decision WAS made and IS implemented - `R2 sec 115` (`ledger:reconcile` FAILS the daily job on drift), `R2 sec 116`/`117` (Money is signed), commits `058adb0`/`89d2b58` - and criterion 1 is MET. Re-statused from PARTIAL 2026-10-12: `BLOCKED` on RV-10 is the truthful state; PART implied work on this row that does not exist. | **RE-MEASURED against its own 4 acceptance criteria (`R2 sec 112`) - 1 met, 2 met only in a corrected form, 1 not met.** (1) `Money` VO: **NOT MET** - 5 files, and ~20 of ~29 sites are `formatted()` presentation calls; `WalletTransactionService:1229`, `CashRideFeeService:343/388`, `LedgerService` (6 sites), `AdminDriverService:417-421`, `PassengerProfileController:479/481/513/543` and 2 commands still do raw float + per-call `round()`. The GOAL clause "rounding decided in one place" is ALREADY met where it matters: the 95/5 split routes through `FeeSplit::driverAndPlatform()` in integer minor units (`sec 71`; its docblock records the real defect that fix closed). (2) One vocabulary: **MET, under a different name** - `App\Enums\LedgerType`, not `LedgerEvent`, pinned repo-wide by `RV39SeederHygieneTest::test_every_wallet_transaction_type_written_anywhere_is_a_shared_ledger_type`, which walks every `WalletTransaction::create(` block in `app/` and `database/seeders/` and resolves literals plus variables traced to their literal assignment. The stated MECHANISM ("the column rejects unknown values") is **REFUTED**: `2026_10_03_233000_convert_enum_columns_to_varchar.php` made the column varchar (owner decision 13, `sec 54`), so the RATCHET holds the vocabulary, not the schema - as the `LedgerType` docblock already says. (3) `ledger:reconcile`: scheduled and real (`Kernel:84`, daily 04:30, `onOneServer`), but "proving `SUM(balances)`" is a **REFUTED premise** - `ReconcileLedgerCommand:70-71` compares `SUM(wallet_transactions.amount)` against `SUM(ledger_entries.amount)` per wallet and never reads the `wallets.balance` column. `SyCash == SUM(bookings.escrow_held)` is not checked by this command at all but IS pinned in the corrected e-pay form by `RV02EscrowDerivationTest::sycash_equals_the_sum_of_escrow_held_for_epay_bookings` (`sec 77` / `sec 81` record why the literal form cannot hold). "Reports a real mismatch when one is injected" is **NOT PROVEN**: the only test asserts a CLEAN run exits 0, and a repo-wide grep of `tests/` for `unexplained` / `SYSTEM DOES NOT BALANCE` returns no match. (4) Aliases: **6 of 7**; RV-10 is the seventh. **THE HANDOFF LEAD WAS A MISREADING, recorded so it is not re-derived:** the claim that "5 files still call `WalletTransaction::create` directly - the thing AF-6 wants to eliminate" is wrong on both halves. `LedgerService:19-26` states the design outright - it is "deliberately additive", "does NOT move balances", and a converted path IS "write the single-sided row, then call `postTransfer()`". Those 36 sites in 5 files are the DESIGNED SHAPE, not a bypass, and RV-21 (`sec 57-60.1` `3be510a`, `sec 60`, `sec 61.1`) already converted every money path | A sec J snapshot line 694 "not started - next", sec F.7-8; R1 sec 7 Wave 3; R2 sec 6; **`R2 sec 112` (re-measurement).** ONE REAL DEFECT FOUND AND FIXED: the `ReconcileLedgerCommand` docblock AND the `comment()` it prints still said the admin wallet paths "are NOT converted, pending the owner decision" and that unexplained movement there was "EXPECTED" - false since `sec 57-60.1`, where `AdminWalletService:118`, `AdminWalletRequestController:211` and `PassengerProfileController:396` all post `postExternalTransfer()` and `AdminWalletService:129` THROWS if the External Capital account is missing. An operator would have dismissed a real money-path defect as designed behaviour, which is the same failure the two self-certifying seeder comments caused in `sec 104`. Corrected in both places together, because correcting one alone would leave them contradicting each other. Verified: `php -l` clean, `pint --test` PASS (1 file), money floor (`DoubleEntryLedgerTest` + `tests/Feature/Wallet` + `tests/Feature/Payment` + `tests/Unit/Domain`) 182 tests / 257 assertions / 3 failures, and a controlled bisect against the HEAD blob produced the SAME 3 failures with the same names, restore SHA256-identical - zero regressions. No needle claimed: a comment and an output string have no behaviour to invert. **EXIT CODE DELIBERATELY UNCHANGED** - still `SUCCESS` on per-wallet drift; recorded as owner call (a) above**`R2 sec 114` - criterion 3 was NOT PROVEN and is now demonstrated:** no test had ever shown `ledger:reconcile` DETECTING anything, only exiting 0 on clean data. New `LedgerReconcileDetectsDriftTest.php` injects an unledgered movement - the only fault shape a real bug can take, since `postTransfer` refuses unbalanced legs - and proves the per-wallet report fires, names the wallet and quantifies the drift with its sign. 8 tests / 25 assertions, NEEDLED (drift made unreportable fails 6 of 8, leaving only the negative controls green; command restored SHA256-identical). **STILL `PARTIAL` and still owner-gated:** criterion 1 needs the signed-money DESIGN call (`Money` refuses negatives; the money paths are built on negatives), and the exit code on drift is owner call (a). The new test is deliberately silent on the exit code so this task supplies evidence for that decision rather than pre-empting it **`R2 sec 115` - owner call (a) ANSWERED and APPLIED: `ledger:reconcile` now returns `FAILURE` when any wallet is unexplained.** It used to print a correct, actionable drift report and exit 0, so at 04:30 the scheduler logged an alarm and reported success - the report was a logfile line, not an alert. Edits are confined to the command: `return self::FAILURE` in the unexplained branch, the description, and the docblock paragraph that documented the old permissiveness. NEEDLED: reverting the decision fails **7 of the 9** `LedgerReconcileDetectsDriftTest` tests and leaves only the two that assert exit 0 either way; command restored SHA256-identical; money floor identical under the needle. **`DoubleEntryLedgerTest::the_reconcile_command_runs_against_real_data_and_reports_success` is still green and was NOT touched** - clean data must exit 0. **STILL `PARTIAL` for one reason only: criterion 1, the signed-money sweep, which the owner has APPROVED in principle (widen `Money` to signed) but which is NOT implemented - a large money-semantics change with real call-site blast radius, so it is its own task.** RV-10 remains `BLOCKED` on the owner window W **`R2 sec 116` - criterion 1 STEP 1 of 2 DONE: `Money` is SIGNED (owner decision 2026-10-11, widen rather than add a second type).** The constructor no longer rejects negatives and `subtract()` no longer refuses a negative result, so a debit is expressible in the type. **The protection MOVED rather than vanished** - two callers depended on it by accident and now state it: `FeeSplit::driverAndPlatform` calls `assertNotNegative` (a negative release would split into two negative shares that still sum correctly) and `AdminWalletService::chargeWallet` calls `assertPositive` (a negative "charge" would post an `admin_credit` while moving the wallet DOWN). Added `isNegative()`, `negated()`, `absolute()`, `assertNotNegative()`, `assertPositive()`; the OPERAND guards on `multiply`/`divide`/`percentage` are KEPT because a negative multiplier is a caller mistake whatever the receiver sign. **TWO NEEDLES, both load-bearing:** restoring the old throw fails **12** of the new tests, and deleting the moved `FeeSplit` guard fails **exactly 1** - `a_negative_escrow_release_is_rejected` - which is the proof the rule was moved and not deleted. Both files restored SHA256-identical. Money floor + `RV19FeeSplitTest` + `BoundaryDependencyTest`: 221 tests / 321 assertions / 3 failures (the same pre-existing OTP items). **The 4 `AdminWalletServiceTest` `charge_wallet` errors are PRE-EXISTING AT HEAD** (missing `SystemWalletSeeder`), proven by reverting all 5 changed files: identical 4 errors, 69 tests / 123 assertions. `MoneyTest` had two tests pinning the OLD contract; they were REPLACED with tests of the new contract and the docblocks say so, rather than deleted or edited to pass. **STILL `PARTIAL`: criterion 1 remainder is converting the 7 services / ~66 raw-decimal sites to `Money`, now possible and ungated but large.** **`R2 sec 117` - criterion 1 STEP 2 of 2 DONE, so criterion 1 is now MET: the raw decimal math is gone.** 14 sites in 7 files converted after RE-DERIVING the census (the "66 sites" in the criterion was stale - it counted what `sec 71` folded into `FeeSplit`). The balance invariant in `LedgerService::postTransfer` was a float running sum compared with `!== 0.0`, correct only because of a trailing `round()`; it is now an exact sum of integer minor units, and the per-leg normalisation at write time went the same way so what is stored is what was checked. Signs are derived with `negated()`. **`PassengerProfileController:480` deliberately NOT converted: it is `round($avgRating, 1)`, a RATING.** **This fixed no live bug and is recorded as such** - a controlled probe of the old float form against the new reports **0 of 7 disagreements** on realistic leg sets; what changed is the MECHANISM, rounding decided once inside `Money::from()` rather than at 14 call sites. Held by new `tests/Feature/Review/MoneyRoundingIsDecidedInOnePlaceTest.php` (5 tests / 24 assertions) - a structural ratchet, its NEGATIVE CONTROL, and a test that non-money rounding survived. NEEDLED three ways: removing the balance check fails exactly `an_unbalanced_transfer_is_refused_rather_than_recorded`; a restored per-call money `round()` fails the ratchet reporting `LedgerService.php:87`; reverting one file to raw floats fails the ratchet AND the control. **Controlled bisect 986 tests / 2729 assertions / 31 failures - IDENTICAL at HEAD.** Row stays `PARTIAL` for one reason only: criterion 4 seventh alias **RV-10 is `BLOCKED` on owner window W**, an action not a decision. No code task remains on this row. Also fixed a duplicated `reserves for the owner.` fragment my own `sec 116` close-out left inside the criteria. |
| 66 | AF-7 | Controller extraction / Larastan | P1 | VERIFIED FIX | none | **none.** Only remaining item is the boundary baseline, which is an OWNER APPROVAL not a code blocker (`R2 sec 95`)| `R2 sec 95`; **`R2 sec 93`** (row 110). **VERIFIED FIX for the real defect: the rides-as-driver / bookings-as-passenger status rollup was duplicated VERBATIM in `ProfileController`:413/426 and `StaffOperationsController`:132/144 - identical `selectRaw("status, COUNT(*)")` -> `groupBy` -> `pluck` - and is now one `UserRideStatsService`. Behaviour-preserving: reshaping stays in each controller because the two endpoints expose different public shapes (`total_created`/`no_show` vs `total`), which `AGENTS.md` reserves. Bisect over 478 tests, method-level: 11 named failures at HEAD -> 12 after, the ONLY difference being `BoundaryDependencyTest`, i.e. zero regressions. **`controllers_to_models` 21 -> 20; propose lowering the baseline, NOT applied (ask-first).** Remaining ~38 sites are trivial model fetches and are not defects **`R2 sec 111`: baseline LOWERED 21 -> 20, owner-approved.** Measured, not assumed: the run printed "only 20 violations remain ... lower the number in BASELINES" and listed all twenty files, so the claim rests on the test output. `BoundaryDependencyTest` 9 tests / 36 assertions, OK. **Why it matters:** at 21 with 20 real violations the ratchet did NOT bite - a NEW controller reaching for a model could be added with the suite still green. At 20 it bites again|
| 67 | T1-1 | Ride-completion state machine self-contradictory; E-PAY escrow never released | P0 | VERIFIED FIX | n/a | RV-02, RV-10, RV-11 | S P1 T1-1; S P2 sec T1-1 VF; 3443979 - re-checked: `BookingService.php:510-516` uses `RideStatus::tryFrom` |
| 68 | T1-2 | Ledger writes use a `type` value absent from the DB enum (fatal in strict mode) | P0 | VERIFIED FIX | n/a | RV-40, AF-6 (LedgerEvent) | S P2 sec T1-2 VF; 3443979 |
| 69 | T1-3 | Secrets committed in git history, still recoverable | P0 | BLOCKED| owner action (P0, not optional): rotate `JWT_SECRET` and `PUSHER_APP_SECRET` (both STILL LIVE and in git history), rotate the PAT embedded in `origin`'s URL, then `filter-repo` (244 commits, 4 refs, force-push = owner-only). `R2 sec 98`. Gates `RV-07, T2-8` were STALE (both VERIFIED FIX) | RV-07, T2-8 | `R2 sec 5`, `S P2 sec T2-8`; 3443979, 0c0ea3c. **`R2 sec 98` - full reconnaissance, no values recorded or printed.** `.env` was tracked: added `6091b15` (2026-05-17), modified `df96d68`, deleted `df9304c` (2026-08-09) - now correctly untracked and gitignored (`.gitignore:40`), so the exposure is historical. `docker-compose.yml` carries literals across 13 revisions, latest `3e9a304`. **Severity matrix (current value vs every historical literal): `JWT_SECRET` and `PUSHER_APP_SECRET` MATCH - rotate immediately; `APP_KEY`, `MAIL_PASSWORD`, `DB_PASSWORD`, `DB_USERNAME`, `REDIS_PASSWORD` do NOT match, i.e. already rotated. No production DB credential was leaked: every literal `DB_HOST` in history is a local/dev host, while the current `.env` points at a third-party cloud DB whose value never appears in history.** Also LIVE right now: a PAT is embedded in `origin`'s URL (`https://user:token@github.com`), which is in `.git/config`, not history, and is readable by anything that can read that file. `filter-repo` scope: **244 commits, 4 refs** (`Agentic`, `main`, `origin/Agentic`, `origin/main`) - force-push required, which `AGENTS.md` forbids, so it is the owner's to run `R2 sec 108`: the owner tree was committed and PUSHED to `Agentic`, which does NOT close this - the live JWT_SECRET / PUSHER_APP_SECRET are still in the pushed history and the PAT is still embedded in the `origin` URL. Rotation remains the only fix and needs the owner|
| 70 | T2-1 | Admin identity always null; audit fields never recorded | P1 | VERIFIED FIX | n/a | RV-03, RV-26 | S P2 sec T2-1 VF; 3443979 |
| 71 | T2-2 | Admin role gate contradicts the token issuer; financial-admin locked out | P1 | VERIFIED FIX | n/a | RV-26 (**its fix text refutes RV-26's headline**) | S P2 sec T2-2 VF (premise partly DISPROVED); 3443979 |
| 72 | T2-3 | OTP attempt limit defined but never enforced | P1 | VERIFIED FIX | n/a | RV-16 | S P2 sec T2-3 VF; 3443979 |
| 73 | T2-4 | Signup overwrites an unverified account's password | P1 | VERIFIED FIX | n/a | RV-16, decision 8 | S P2 sec T2-4 VF; 3443979 |
| 74 | T2-5 | Deploy config ships literal DB credentials; phpMyAdmin exposed; source mounted | P1 | VERIFIED FIX | n/a | RV-08, RV-28 | S P2 sec T2-5 VF (2 premises corrected); 3443979 |
| 75 | T2-6 | Unauthenticated infra disclosure + exception leakage on health/debug routes | P1 | VERIFIED FIX | n/a | RV-13, RV-29 | S P2 sec T2-6 VF; 3443979 |
| 76 | T2-7 | Public broadcast channel authorizes everyone | P1 | VERIFIED FIX | n/a | RV-28 | S P2 sec T2-7 VF (real defect = producer channel type); 3443979 |
| 77 | T2-8 | Pusher credentials as in-code fallback defaults | P1 | VERIFIED FIX | n/a | T1-3, RV-07 | S P2 sec T2-8 VF ESCALATED; 3443979 - rotation owed, sec 5 |
| 78 | T2-9 | Google OAuth issues a Sanctum token no route accepts | P1 | VERIFIED FIX | n/a | AF-4b | S P2 sec T2-9 VF; 3443979 - `config/sanctum.php` absent (re-checked) |
| 79 | T2-10 | Rate limiters IP-keyed only; config duplicated | P1 | VERIFIED FIX | n/a | T4-1 | S P2 sec T2-10 VF; 3443979 |
| 80 | T2-11 | Staff refresh tokens stored plaintext | P1 | VERIFIED FIX | n/a | RV-29 | S P2 sec T2-11 VF (rows purged, deliberate); 3443979 |
| 81 | T2-12 | Session cookies JS-readable; CORS wildcard + credentials | P1 | VERIFIED FIX | n/a | RV-22 | S P2 sec T2-12 VF ('encrypt' premise DISPROVED); 3443979 |
| 82 | T3-1 | `chargeWallet` mints txn id from a timestamp (same-second collision) | P2 | VERIFIED FIX | n/a | RV-15 | S P2 table row T3-1 VF; 3443979 |
| 83 | T3-2 | Financial columns use three precisions for one currency | P2 | VERIFIED FIX | n/a | RV-40, RV-14 | S P2 table VF; 3443979 |
| 84 | T3-3 | Wallet-creation OTP optional; unaudited create-direct route | P2 | DEFERRED | owner: deferred | RV-21, T3-4 | S P2 table `DEFERRED (owner: leave for later)` |
| 85 | T3-4 | `wallet_requests.processed_by` references users; admin actors are employees | P2 | **VERIFIED FIX** | **D8 = A** EXECUTED. `processed_by` recorded the employee's SHADOW `users` row, and `ensureShadowUser()` returns whatever user it finds by EMAIL - so when a customer held the admin's email, `processed_by` named that CUSTOMER on a financial approval. Added `processed_by_employee_id` -> `employees(id)` ON DELETE SET NULL, written at both admin sites from `attributes['staffEmployee']`. `processed_by` KEPT. One test demonstrates the collision (proves `processed_by` IS the customer) while `processed_by_employee_id` IS the employee. **FOLLOW-UP: `users.banned_by` and `wallet_transactions.user_id` are still shadow-based and still wrong in this scenario - the fix pattern is now known** | T2-1 **VERIFIED FIX** - satisfied | R2 sec 82; S P2 table `NOT STARTED`; R2 sec 41 (un10 done) |
| 86 | T3-5 | Conflicting/duplicated indexes; `down()` drops unconditionally | P2 | VERIFIED FIX | n/a | RV-30 | S P2 table VF; 3443979 |
| 87 | T3-6 | MySQL-only raw SQL contradicts the SQLite test config | P2 | VERIFIED FIX | n/a | RV-18, V6 | S P2 table VF (7 migrations guarded); 3443979 |
| 88 | T3-7 | `VerifyOtpMiddleware` a no-op; a test asserts it works | P2 | VERIFIED FIX | n/a | RV-16 | S P2 sec T3-7 VF (stub + misleading test deleted); 3443979 - file absent (re-checked) |
| 89 | T3-8 | `AdminUserSeeder` reads deleted config keys; makes a null-email user | P2 | VERIFIED FIX | n/a | V8, RV-19, RV-26 | S P2 sec T3-8 VF (seeder deleted); 3443979 |
| 90 | T3-9 | Hardcoded credentials in seeders and load-test scripts | P2 | VERIFIED FIX | n/a | RV-39 (`ResolvesSeedCredentials` precedent), RV-07 | S P2 sec T3-9 VF; 3443979 - trait present on disk (re-checked) |
| 91 | T3-10 | Deploy resets the server to a feature branch; token persisted in remote URL | P2 | OPEN | **D9 = C ANSWERED 2026-10-04**: hygiene DONE (`R2 sec 80`): token no longer persisted into `.git/config` on the VPS, deploy follows the triggering ref. Target still the owner to choose - row stays OPEN | **OWNER, 2 of 3 gates remain. "decision 7" DISCHARGED (`R2 sec 91`)** - answered (= B: Render, shipped `2114308`); the hygiene half is already DONE (`R2 sec 80`). LIVE: **(a) T1-3** = `BLOCKED`, an owner action (rotate the leaked credentials + `filter-repo`) that no code change substitutes for; **(b) RV-08** = now an **OWNER DELETION APPROVAL**, not a target choice - decision 7 already chose Render and the target's own config defect is fixed (`R2 sec 96`)| S P2 table `NOT STARTED (deploy automation)`; no commit |
| 92 | T3-11 | CI never fails on test failures; writes a plaintext JWT secret | P2 | VERIFIED FIX | n/a | RV-18, the "always-true" guard class | S P2 table VF (Sonar pinned to SHA); 3443979 - the always-true escape hatch is gone except at `gitleaks.yml:40` (re-checked) |
| 93 | T3-12 | Unauthenticated API docs; generated spec committed | P2 | VERIFIED FIX | n/a | RV-06 (same exposure class) | S P2 table VF; 3443979 - `DOCS_ALLOWED_IPS` gate re-checked |
| 94 | T3-13 | Silent `catch (Throwable) {}` suppresses failures | P2 | VERIFIED FIX | n/a | RV-13 (the sweep continues it), RV-33 ratchet | S P2 table VF (27 sites -> `Log::warning`); 3443979 |
| 95 | T3-14 | Push dispatched synchronously; failure blocks the request | P2 | VERIFIED FIX | n/a | RV-27, T2-8 | S P2 table VF (boot guard); 3443979 |
| 96 | T3-15 | Pending-withdraw guard subject to a race | P2 | VERIFIED FIX | n/a | RV-09, RV-21 | S P2 table VF (`FOR UPDATE`); 3443979 |
| 97 | T3-16 | No-show resolution applies penalties without locking the report row | P2 | VERIFIED FIX | n/a | **RV-02 (L1)** (same lock precedent), RV-40 | S P2 table VF; 3443979 |
| 98 | T3-17 | `/up` runs a real DB query on every health probe | P2 | DEFERRED | owner: deferred | RV-28 | S P2 table `DEFERRED (owner: leave for later)` |
| 99 | T4-1 | No test could catch the two critical money bugs | P3 | VERIFIED FIX | n/a | RV-34, RV-33 | S P2 sec T4-1 VF; 3443979 |
| 100 | T4-2 | `scopeNearLocation` built an unbindable WKT | P3 | VERIFIED FIX | n/a | RV-25, V1 | S P2 sec T4-2 VF; 3443979 |
| 101 | T4-3 | `cleanupExpiredTokens` DELETE relied on operator precedence | P3 | VERIFIED FIX | n/a | RV-29 | S P2 sec T4-3 VF; 3443979 |
| 102 | T4-4 | JWT lifetimes inconsistent and undocumented | P3 | VERIFIED FIX | n/a | RV-04 (TTL decision 9), decision 9 | S P2 sec T4-4 VF (deliberate deviation); 3443979 - `config/jwt.php` reads env (re-checked) |
| 103 | T4-5 | `User::$fillable` includes every privileged column | P3 | BLOCKED | owner: rolled back twice | **RV-29 item**, sec 30 item 5 | S P2 sec T4-5 `ROLLED BACK (owner instruction)`; 1d68c07 = ratchet instead (sec 34) - privileged keys still in `$fillable` (re-checked) |
| 104 | T4-6 | Dead/duplicate artifacts in the repository root | P3 | VERIFIED FIX | n/a | RV-31, RV-32 | S P2 sec T4-6 VF; 3443979 |
| 105 | T4-7 | No-op/stub implementations reporting success | P3 | VERIFIED FIX | n/a | RV-31, AF-4c | S P2 sec T4-7 VF; 3443979 |
| 106 | RV-11-B | Boundary edge `models_to_enums` exceeds its baseline | P2 | VERIFIED FIX | **D10 = A ANSWERED 2026-10-04 - VERIFIED FIX**: raised 2 -> **3** (the ratchet counts FILES, so the 4 enum PAIRS are 3 edges). Needle: a 4th file fails the ratchet | V5 | Found 2026-10-03 running `BoundaryDependencyTest` for RV-11 (a `use` line was added). **PRE-EXISTING, proved by controlled bisect**: identical failure with `ScoreService.php` + `UserScore.php` reverted to their HEAD blobs (9 tests / 36 assertions / 1 failure both times), files restored byte-identically. `models_to_enums` max 2 vs `Models/Complaint.php`, `Models/Employee.php`, `Models/Wallet.php`; none is a model RV-11 touched. Baseline deliberately NOT raised. Decision: break the edge in those 3 models, or accept it and set the baseline to the true value with a written reason |

| 107 | T4-8 | Source comments are double-encoded UTF-8 (mojibake), which breaks edit anchors | P3 | VERIFIED FIX | n/a | AGENTS.md "Edit hygiene" - "Em-dashes and other non-ASCII characters in anchors have repeatedly failed to match" | R2 sec 90. **250 runs repaired across the same 9 files, every one an exact inverse; no character was guessed and no ASCII stand-in was substituted.** The earlier "does not converge" finding in `sec 88` was WRONG and its cause is now known: `ISO-8859-1` cannot represent U+20AC, so `mb_convert_encoding($s,"UTF-8","ISO-8859-1")` returns its INPUT UNCHANGED, which reads identically to "already a fixed point". With **Windows-1252** the transform is invertible, and repeating it unwinds the multi-pass corruption (8 chars -> 3 -> 1). Recovered exactly: U+2192 (1 and 2 passes), U+2019, U+2500, U+2014, U+2265, U+2190. Scope was held to COMMENT TOKENS structurally, via PHP tokenizer byte spans, so no string literal was reachable. Proof: `verify.php` re-tokenises original vs repaired and requires every non-comment token byte-identical, comment token count, per-line ASCII skeleton and line count unchanged - **9 files, 0 failures**, with needles failing on a 1-character string-literal edit and on code whitespace. Gate: `tests/Feature/Review/T48CommentEncodingTest.php` 26 tests / 29 assertions, zero baseline; needle-injected mojibake makes it fail naming file+line. Bisected regression 848 tests 19F -> 18F, the one difference being the new gate going red -> green. Idempotent; `php -l` + `pint --test` clean. `RideController.php` excluded (owner edits) and independently confirmed to hold no recoverable comment mojibake. The Arabic STRING-literal remainder was filed as row 108. **TRAP carried forward: do not inspect these bytes in a terminal** - the console re-encodes them (this corrupted the `sec 88` byte dump itself). `sec 88`, `sec 90` |

| 108 | T4-9 | The SAME mojibake in USER-VISIBLE Arabic string literals | P2 | VERIFIED FIX | **OWNER DECISION - behaviour change.** Repairing these changes text the Flutter client already renders, so the choice is: repair in place, or normalize at the API boundary, or leave as-is | **none.** The owner chose repair-in-place (2026-10-11) and the Idlib spelling (2026-10-12). Decided and done. | **DONE (`R2 sec 120`): the 89 mojibake runs in user-visible Arabic string literals are repaired, and the repair covers the STORED DATA and the VALIDATOR, not just the display text.** 41 distinct literals rewritten across 6 files (AdminWalletRequestController 1, AdminReportService 19, PassengerProfileController 13, RideService 7, ProfileController 1, StaffAdminController 4); every one recovered cleanly in one or two inverse passes, none unrecoverable. **The reason this was more than a text fix:** `ProfileController:121` validates `address` with `in:` against the garbled list and `AdminReportService:168-181` uses the garbled names as the keys matching `User.address` rows, so repairing the source alone would have rejected the values existing users already have AND dropped every affected user out of the city report. **New migration `2026_10_12_120000_repair_mojibake_user_addresses.php`** rewrites only the 14 exact stored garbled values, 1:1 and reversible, matched **BINARY** so a collation cannot fold two distinct garbled strings together, with the map written as `\u{...}` escapes so the file is ASCII-only and cannot itself be re-corrupted. **The owner settled what the bytes could not:** the bug DROPPED the hamza from Idlib (the source reverses to the bare alef, U+0627 U+062F U+0644 U+0628), so the owner chose the standard hamza spelling (U+0625 U+062F U+0644 U+0628). The override must apply to the LITERAL REWRITE as well as the migration, or the source keeps the bare alef while the migration writes the hamza and the two disagree about what a valid city is - a test caught exactly that. **New `tests/Feature/Review/T49MojibakeRepairTest.php`, 8 tests / 14 assertions**, pinning that the stored rows, the validator and the report all agree. **Verification:** `php -l` and `pint --test` clean on all 8 files; Review floor 501 tests / 1756 assertions / no failures; controlled bisect over 8 directories (Profile, Auth, Admin, Rides, Bookings, Wallet, Unit/Domain, Review) with the repair vs at HEAD = 957 tests both, **19 failures identical either way, NONE new**; **NEEDLE 1** reverting the validator to the garbled HEAD list catches 2 tests; **NEEDLE 2** emptying the migration MAP catches 6 of 8; restore SHA256-identical both times; no residual mojibake markers; 43/43 non-ASCII literals valid UTF-8 with no C1 controls. **Two FAKE TESTS the needles caught, recorded because both looked green:** the first validator test built its own `in:` rule from the migration map instead of reading the one that ships in ProfileController (reverting the file changed nothing, so it passed against a broken validator - now it parses the shipped rule), and the first report test asserted the absence of the literal `Unknown` when `AdminReportService:196` actually falls back with a null-coalesce on the stored address, so that literal never appears and the assertion could not fail (now asserts the repaired Arabic is exposed and no garbled value survives). Also: a hand-typed garbled literal in the test was wrong, so the tests now read the garbled keys from the migration file and never type mojibake by hand. **NOT CLAIMED: the Flutter client.** There is no pubspec.yaml in this repo, so what the app sends could not be checked. If it still sends the GARBLED address values it will now be rejected by the validator - correct behaviour, but client-visible. |
| 109 | T4-10 | The `Blocked by` column is STALE on all five OPEN rows | P3 | VERIFIED FIX | n/a | n/a - this row CREATES no gate; it removes 8 false ones | `R2 sec 91`. **8 of the 13 gates named by the 5 OPEN rows were already discharged**, so the rule (first OPEN row with `Blocked by = none`) selected nothing and every session ended at "none unblocked" - the same failure `sec 66` diagnosed for `PARTIAL` rows, recurring because the column was never re-derived after the repairs landed. **Each gate discharged from its owning row's own Status or from the code, never from memory:** RV-08 <- V10 (`routes/api.php:203` now routes to `create`, so V10's "500, `createRide` missing" no longer reproduces); AF-6 <- RV-02 L2, RV-09, RV-11, RV-15, RV-21 (all `VERIFIED FIX`); AF-7 <- RV-33, RV-24 (both `VERIFIED FIX`, which **unblocks the row**); T3-10 <- decision 7 (answered, shipped `2114308`). **Still live, all owner-gated:** RV-08 deploy target, RV-10 W window, RV-20 refund-half design, T1-3 credential rotation, T4-9 client-visible text. **A second defect found while checking: the table has no stable column index** - the obvious positional access (split the line on the cell delimiter, then take index 7) silently returns the Decisions column on 3 of the 4 rows I touched, because a Decisions cell that ends in `sec 66` splits the same way a column boundary does. Row 66 also has a different field count from its neighbours, and row 108 shipped in `sec 90` with its provenance sentence sitting in the gate slot. Cells must be addressed from the header, never by position. No code changed and no test was run: this is a documentation reconciliation. `sec 66`, `sec 91` |
| 110 | AF-7b | AF-7 re-scoped: 18 controllers still query models inline | P2 | **VERIFIED FIX** (the re-scope acceptance set is re-measurement + triage, and `R2 sec 95` + `sec 111` discharged both) | **none** (`R2 sec 93` - never gated; `sec 93` found it unblocked) | `R2 sec 93`; `ARCHITECTURE_MAP.md` sec 2. **Re-measured, because the map's "21 controllers" is stale: it is now 18 controllers / 42 inline model-or-query sites**, concentrated in `PassengerProfileController` (6), `StaffAdminController` (5), `StaffOperationsController` (5), `AdminBanController` (3), `AdminWalletRequestController` (3), `NotificationController` (3). The Larastan half is ALREADY DONE and shipped (un8 = A, `larastan/larastan` 2.9 in composer, `phpstan.neon.dist`, `.github/workflows/static-analysis.yml` running it report-only with a deliberate `continue-on-error`). **Not started here on purpose:** this is a refactor across auth, money and ride paths, and starting one that cannot be finished and verified would leave exactly the partial work `AGENTS.md` forbids. Suggested first slice: `AdminBanController` + `AdminDashboardController` (5 sites, no money, no auth-rule change). `sec 93` | `R2 sec 93` measured 18 controllers / 42 inline model-or-query sites; **`R2 sec 95` triaged them and the premise was wrong.** Only ONE was real duplication (extracted and fixed); `RideController:232` aggregates SEATS not counts and `PassengerProfileController:688` is COMPLAINTS - both were false positives from a regex sweep, and both were checked before being dismissed. The rest are single `findOrFail`/`first` lookups that are correct Laravel and would get worse behind a service. Row closed: re-measurement + triage done; **`R2 sec 113` - the `Status` cell said `OPEN` while this very cell said "Row closed", and the row carried a NINTH cell against an 8-column header, so the status authority misread it.** That made this the one `OPEN` row the next-task rule could legally select - at `Order` 110 it is the lowest-Order qualifying row - and three sessions in a row concluded the rule selected nothing. Re-verified before correcting, not assumed: `UserRideStatsService` exists (`app/Services/Ride/UserRideStatsService.php:32`) and BOTH former duplication sites delegate to it (`ProfileController:413`, imports at `:10` and `:58`; `StaffOperationsController:134`, imports at `:15` and `:44`); `BoundaryDependencyTest` 9 tests / 36 assertions OK at baseline 20 (commit `732dfc7`); both `sec 95` dismissals re-checked and still correct - `PassengerProfileController:685` is `complaintCounts()` over `Complaint`, and `RideController:254` is `COALESCE(SUM(seats), 0)`, i.e. SEATS not counts. The `ID`/`Title` cells were transposed by the same splice and are restored. **The other 6 malformed rows are filed as row 113, untouched here** |
| 111 | RV-04b | Tests encode a PRE-TIGHTENING auth model, so 12 identity-floor tests are red | P1 | VERIFIED FIX | **none.** ALL FIVE owner decisions were taken 2026-10-12 and every change is in: the app is correct in all five clusters and the tests were stale. Controlled A/B bisect on the identity+staff+middleware+admin selection (490 tests): HEAD had 5 errors + 16 failures, the change set has 0 NEW failures, and the 12 originally-red identity tests plus 5 `ReviewModerationServiceTest` tests go green. Needle: removing `loadMissing()` alone reproduces 10 errors; restoring the old `status == 0` label rule alone reproduces the staff-label failure. Nothing is left to decide. | Found while re-verifying RV-04 (`R2 sec 93`) | `R2 sec 123`. **Identity/staff/middleware floor: 250 tests / 580 assertions green.** The 5 red causes and the owner ruling on each are recorded in `R2 sec 122`. **Evidence that each change is load-bearing, not decorative:** (1) N+1 - needle removing `loadMissing()` from `ReviewModerationService::format()` reproduces 10 errors; (2) route guard - `tests/Feature/Staff/EmployeeManagementControllerTest` 4 tests went red at HEAD and green with the 403 assertions; (3) `TOKEN_INVALID` - `JwtAuthMiddlewareTest::test_using_refresh_token_as_access_token_returns_401` and `StaffJwtMiddlewareTest::test_staff_refresh_token_rejected_with_token_type_invalid` went red at HEAD; (4) `national_id` - `StaffAdminControllerTest::test_can_approve_passenger_verification` went red at HEAD, plus a NEW negative test pins the 422. **Caveat stated honestly:** the 4 changed EXISTING tests each carry a comment naming the app line that justifies the new expectation, because the owner ruled the app correct and the test stale - the same exception recorded in `R2 sec 119`. |
| 112 | RV-04c | `users.token_version`: migration defaults to 1, `UserFactory` forces 0 | P3 | VERIFIED FIX | **OWNER DECISION - auth fixtures.** Fixing it means either a migration (ask-first) or changing a widely-used factory | **none.** The gate was an owner decision (migration vs factory), answered 2026-10-11: change `UserFactory`, no migration. Cleared and done. | **DONE (`R2 sec 119`): `UserFactory` now sets `token_version = 1`, matching the column default, so every factory user is a state production actually has.** Every claim re-verified ON DISK rather than taken from this row: `users.token_version` defaults **1** (`2026_05_10_172303:21-22`), `employees.token_version` defaults **0** (`2026_05_15_230503:24`), `JwtService:87` is `if (! isset($payload['ver'])) return false;` and `:91` casts both sides, `JwtService:270` mints `ver` FROM the row, `StaffJwtService:88` uses `?? -1`. **So the row's "NOT a security hole" was right** - any starting value is self-consistent and this was a FIDELITY gap: the suite exercised a value the database never produces. Blast radius measured over all 72 `token_version` occurrences in `tests/`: nearly every `=> 0` is an EMPLOYEE fixture, the user fixtures that matter already pass 1, and every assertion on the column is RELATIVE, so none depends on the starting value. **THE EDIT TO AN EXISTING TEST, stated plainly because AGENTS.md forbids it and this is the one judgement call in the task:** `RV04TokenAudienceTest::token_version_parity_...` asserted a factory user and a fresh employee start on the SAME value and then `markTestIncomplete`d to record a divergence it could not close - it sat in the suite as `Incomplete` on every run. The owner ruling is that they must NOT agree, so the factory change turns that Incomplete into a real FAILURE and the only correct move is the one this row already named: assert each actor against its OWN schema default. **That is stricter, not weaker** - before, NOTHING held the factory to the schema; now both tables are pinned, each against a live `information_schema` default. `markTestIncomplete` is gone because the divergence is genuinely closed, and the Review floor's standing `Incomplete: 1` is now `Incomplete: 0`. A second test asserts a factory user's token round-trips at whatever version its row holds, read from the real row so it survives a future default change. **Controlled bisect across 14 directories (identity floor + every area referencing `token_version`): 1678 tests / 4565 assertions / 45 errors / 47 failures with the change, 1677 / 4561 / 45 / 47 + 1 incomplete at HEAD - the failing test NAMES are the IDENTICAL 92 either way, NONE new and NONE fixed.** **NEEDLED:** putting the factory back to 0 fails `each_table_matches_its_own_schema_default` with `Failed asserting that 0 is identical to 1`; restore SHA256-identical. Review floor 493 tests / 1742 assertions / no failures. Incidental: Pint's `php_unit_method_casing` silently rewrote `..._its_OWN_schema_default` into `..._its_ow_n_schema_default`, splitting `OWN` - renamed to `each_table_matches_its_own_schema_default`. **Nothing remains on this row**; parent RV-04 (order 20) was already VERIFIED FIX and is unaffected; RV-04b (order 111) is a separate, still owner-gated item. |
| 116 | AF-7c | The `Status` authority table has 7 rows with the wrong cell count | P3 | **VERIFIED FIX** | **none** - decision-free. It was filed mid-task as a follow-up and then discharged in the same task, because leaving 6 of the 7 instances of one defect unrepaired is a partial fix | row 110 (repaired at `R2 sec 113`); the editing traps in `sec 100` and `sec 111` | `R2 sec 113`. **MEASURED, not asserted: a scan of the section-2 table against the 8-column header found 7 rows with the wrong cell count**, and repairing them exposed two more defects that the scan could not see. **(a) Order 110 (AF-7b) was the decision-relevant one:** its `Status` cell read `OPEN` while its own `Evidence` cell ended "Row closed: re-measurement + triage done", because the row carried a NINTH cell. That made it the ONLY `OPEN` row with `Blocked by = none` - the one row the next-task rule could legally select - and three consecutive sessions therefore reported "none unblocked" when the rule had been answering all along. Re-verified before correcting, not assumed: `UserRideStatsService` exists and BOTH former duplication sites delegate to it, `BoundaryDependencyTest` 9/36 OK at baseline 20, and both `sec 95` dismissals re-checked and still correct (`PassengerProfileController:685` is `complaintCounts()`, `RideController:254` is `COALESCE(SUM(seats), 0)`). **(b) The scan could not see the worse one:** row 112 had swallowed the entire `section 3. Commit verification` HEADING into its last cell, so section 3 had no heading at all and rows 113-115 sat below a heading that should have ended the table. The heading is restored on its own line after the last table row. **(c) Orders 35, 44, 45 and 64** had their `Evidence` cells split by a stray delimiter (64 had three); merged. **(d) Orders 110, 111 and 112** had `ID` and `Title` transposed by the same splice; unspliced. **(e) Order 109** carried a literal pipe character inside a code span, which breaks the row in rendered markdown as well as for a parser; its Evidence was restored from `sec 91` in substance, pipe-free. **`ID` 113 was already taken by AF-13 - the row first filed for this defect was renumbered 116 rather than left as a duplicate Order, the mistake `sec 106` already recorded once.** **PINNED:** new `tests/Feature/Review/BacklogTableShapeTest.php`, 6 tests / 133 assertions - cell count per row, Order uniqueness, no heading swallowed into a row, sections 1-7 present once each and in order, plus a self-test proving the detector reports a 9-cell and a 10-cell row so the ratchet cannot pass on a table it cannot read. Scoped to the section-2 table after the first run fired on the section-1a and section-7 tables (`sec 64.3` lesson). **THREE NEEDLES, each reproducing a real defect, all caught by the right test:** 9th cell after `Status` -> `cellcount`; `section 3.` glued into a row -> `cellcount` + `swallowed`; duplicate `Order 1` -> `duporder`. BACKLOG.md restored SHA256-identical after each. `php -l` clean, `pint --test` PASS |
| 117 | RV-42a | Admin "suspended" counts and labels TWO different populations | P2 | VERIFIED FIX | **none.** Owner ruled 2026-10-12: "suspended" means BANNED. Implemented and verified on disk with a new test; the count, the filter and the label now all go through one predicate. | Found while re-statusing the `PARTIAL` rows (`R2 sec 122`), confirmed on disk by an independent audit pass | `R2 sec 124`. **New `tests/Feature/Review/RV42aSuspendedMeansBannedTest.php`: 8 tests, 22 assertions, all passing.** **THE FIX:** new `User::scopeBannedNow()` (`app/Models/User.php`) is the SQL twin of `isBannedNow()`, so a COUNT or a list cannot drift from the single-row answer; all seven readers now use one or the other - `AdminUserService:99` count, `:163` filter and `resolveUserStatus()`, `AdminDriverService:99` count, `:148` filter and `resolveDriverStatus()`, `StaffOperationsController:174` `account_status`. `ban_type` and `ban_expires_at` are both NULLABLE (see the add-ban-fields migration), so the expiry test mirrors `banHasExpired()` instead of using a plain `>`, and NULL is handled on both columns. **NEEDLES, both proved and both restored SHA256-verified:** reverting the scope to the old `status = 0` rule fails 2 tests (`test_the_query_scope_agrees_with_is_banned_now_row_for_row`, `test_the_suspended_count_counts_banned_accounts_only`); reverting the three label sites to `status == 0` fails `test_the_staff_screen_labels_banned_suspended_and_logged_out_active`. **The endpoint test really hits `/api/staff/users/{id}` - it does not skip.** `JwtAuthMiddleware:103` also reads `status == 0` and was deliberately LEFT ALONE: it rejects a stale token for a logged-out user, which is token validity, not a "suspended" report. **BEHAVIOUR CHANGE THE OWNER SHOULD KNOW:** `suspended_users` / `suspended_drivers` were counting every logged-out and every unbanned account and now count only genuinely banned ones, so those numbers will DROP sharply on live admin endpoints. A first draft of the staff-label test was tautological (it compared `isBannedNow() ? a : b` with itself and could not fail) and was replaced with one that hits the endpoint - the failure mode `R2 sec 119`/`120` recorded twice already. |
| 118 | RV-43 | Admin login returns 401 for the seeded system admins - 9 tests red at HEAD | P1 | VERIFIED FIX | **none.** Two owner rulings were taken on 2026-10-12, both AFTER the diagnosis was proven with tests rather than argued: (a) fix the stale tests AND close the ratchet hole; (b) sycash IS admitted on staff routes. The app was correct in all 9 failures - in three of the causes it had been FIXED while these tests still asserted the old broken behaviour. | Surfaced 2026-10-12 by the RV-42a bisect, not caused by it - confirmed present at HEAD with all changes stashed | `R2 sec 126`. **It was NOT one defect: the 9 failures were FOUR distinct causes, and in three of them the APP HAD BEEN FIXED while the test still asserted the old broken behaviour.** (1) STALE FIXTURE - admin auth moved onto the `employees` table (`AdminAuthService:16-21,49-63`, the RV-29/RV-34 migration) but this file still posted a config email that matches no row, so 2 login tests got 401. Six other test files were updated for that migration and say so in their comments; this one was missed. (2) THE 500s WERE FIXED - `handleAdminToken()` used to leave the `adminConfig` attribute null so `getAdminWallet()` and `chargeWallet()` 500d; both now return 200 and the tests asserted the 500. (3) 401-vs-403 WAS FIXED - `StaffJwtMiddleware::fail()` used to answer 401 for everything, so an AUTHENTICATED sycash lacking a permission was indistinguishable from a failed login; it is now 403, and 4 tests asserted 401. (4) `national_id` is required to approve (`AdminDashboardController:466`), so that test 422d - the same precondition RV-04b found at `StaffAdminController:115`. **A FIFTH PROBLEM THE ROW DID NOT KNOW ABOUT: 3 tests in `tests/Unit/Middleware/StaffJwtMiddlewareTest.php` had the SAME stale fixture but were hidden behind a `markTestSkipped` guard, so the suite reported green for code it never executed.** All are fixed and now run. **THE RV-35 RATCHET HAD A HOLE:** `NoDuplicatedFixtureHelpersTest::FORBIDDEN_LOGIN` required the value to start with a variable, so it never matched the LITERAL email login this file used - the guard was green while the file it was written to catch sat red. It is now widened; two files carry explicit, reasoned exemptions, and `test_the_email_login_exemptions_are_still_needed` fails if either goes stale. **VERIFICATION:** new `tests/Feature/Review/RV43AdminLoginEmployeeTest.php` (8 tests) proves the app works with a real Employee, that email identifiers are accepted, and that the DENIED paths still fail closed; `AdminDashboardControllerTest` went from 24 tests with 9 failures to **33 tests, 0 failures**; **floor: 1000 tests / 2892 assertions / 0 failures** (skips 3 to 2). **NEEDLE:** re-narrowing the ratchet pattern reproduces `test_the_rv35_ratchet_now_catches_a_literal_email_login` failing; restore SHA256-verified. **OWNER RULING ON sycash** (a permission rule, so it was escalated rather than assumed): sycash IS admitted on staff routes, because `StaffRole::isAdminRole()` (:50) returns true for `SYSTEM_ADMIN` **and** `SYCASH`, the route is `/api/staff/me` (the caller's own record), and staff routes carry no role gate. The restriction that matters is on admin-privileged endpoints, where 4 tests pin sycash at 403. `test_sycash_admin_token_is_rejected_on_staff_route` was renamed to `..._passes_a_staff_route` and now asserts 200. |
| 113 | AF-13 | `RideFactory` is unusable: DB::raw geometry vs an array mutator | P2 | VERIFIED FIX| none| none| `R2 sec 99` (found while building RV-10). **`R2 sec 101` - VERIFIED FIX.** `RideFactory.php:19-20` wrote both geometry columns with `DB::raw("ST_GeomFromText(...)")` - a `Query\Expression` - while `Ride::setPickupLocationAttribute(array $coords)` type-hints `array`, so **every** `Ride::factory()->create()` died with a `TypeError`. Latent because nothing called it. Fix: pass the named coordinates `['lat' => .., 'lng' => ..]`, routing the write through the mutator and `GeoPoint::fromLatLng()` - the single source of truth every real writer uses. **Geometry is UNCHANGED**: `GeoPoint::wkt()` emits `POINT(lat lng)` and the old raw literals were already in that order, so no axis-order decision was invented (RV-25 is exactly the bug a hand-rolled rewrite would have reintroduced). Side benefit: the mutator now materialises `pickup_lat`/`pickup_lng`, which the raw write skipped and which had needed migration backfill. `AF13RideFactoryGeometryTest` 6 tests / 17 assertions, OK. **NEEDLE:** restoring `DB::raw` reproduces the original defect exactly (6 errors, `TypeError ... Query\Expression given`), SHA256 restore. Bisect: 659 tests / 2 errors / 10 failures -> **665** (the 6 new) / **same 2 errors, same 10 failures**, zero new, zero disappeared. `php -l` both files; `pint --test` PASS after fixing `single_blank_line_at_eof`|
| 114 | RV-41 | `database.connections.mysql.read.port` was an ARRAY, breaking every read query in production | P0 | VERIFIED FIX | none | none | `R2 sec 102` (found while closing RV-01, not in the original audit). `kyc:migrate-disk --dry-run` died with `Array to string conversion ... No connection could be made because the target machine actively refused it`. `host` IS an array (Laravel replica list) but `port` is interpolated straight into the DSN. Invisible to the suite: `RefreshDatabase` migrates first, `sticky => true` pins every query to the WRITE PDO, so the `read` config is never exercised. `RV08ReplicaPortTest` had CODIFIED it by asserting `port[0]`. NEEDLED: 2 errors + 2 failures, original failure reproduced verbatim. Fixed 2 pre-existing RV-30 errors |
| 115 | RV-42 | `bookRide` refused with a generic message, so a caller could not tell WHICH rule stopped them | P2 | VERIFIED FIX | none | none | `R2 sec 107` (found while clearing the red suite). The catch-all returned "The request could not be completed. Please try again." even when the domain refused on purpose with a curated sentence. `KycActionGateTest` was red because of it (owner decision 11 requires the gate to be named). Fixed with a dedicated `\InvalidArgumentException` branch - every writer in the ride/booking domain emits a user-facing sentence, and the technical `InvalidArgumentException`s live in unrelated services (RouteCalculationService, FileUploadService). RV-13 intact: QueryException and every other throwable keep the generic message. **File is OWNER-OWNED and deliberately left UNCOMMITTED** alongside the owner's RV-38 edits. NEEDLED: 5 failures -> 4, zero new
| 120 | RV-45 | 46 controller catch-alls can swallow domain errors into a 500 | P1 | OPEN | none | RV-44, error model, CI | `R2 sec 134` - MEASURED, not fixed: 46 `catch (\Throwable)` in `app/Http/Controllers`, 36 wrapping a service call. Owner ruled this endpoint only for RV-44 OWNER RULING 2026-10-13: sweep ALL 36 service-wrapping catch-alls (rethrow DomainException / InvalidArgumentException as 422). Changes response codes; each affected test needs a justified update. `R2 sec 146`: scope measured, NOT started as a sweep. 46 catch (\Throwable) sites confirmed by grep across 10 controllers (RideController ~20, AdminBan, AdminWalletRequest, Signup, Verification, EmployeeManagement, StaffAdmin, StaffComplaint, Complaint, PassengerProfile). Per-site review shows the sites differ: some return a deliberate generic 500 envelope that clients read (routeOptions :148), and some map a client-visible rule (create :83). Changing each status is a public response-shape change, so the sweep is one verified controller at a time, not a bulk rethrow. Row stays OPEN until all 36 service-wrapping sites are done. `R2 sec 147`: step 1 (RideController::create) ROLLED BACK (VERIFIED ROLLBACK). Allowed-path 500 proven PRE-EXISTING on HEAD, not caused by the edit. Real cause: a valid verified driver with no uploaded documents is refused by validateDriverCanCreateRide with a plain \Exception (missing Face ID, Back ID, Driving License). \Exception is outside the 422 branch, so it returns a generic 500. Separate finding, filed as RV-53. |
| 119 | RV-44 | `passenger-confirm` returns 500 for every refusal | P0 | **VERIFIED FIX** | n/a | Rides, Bookings, pass-confirm | `R2 sec 133` - owner ruling 2026-10-12 (422, endpoint only); catch-all no longer swallows domain errors |
| 121 | RV-46 | Cash-ride creation-fee refund: binary rule vs 4 graduated tiers | P0 | OPEN | none | RV-02, cash fee, money | `R2 sec 135` - TO BE CONSIDERED (owner 2026-10-12). `CashRideFeeService.php:342-343` returns 100% unless (elapsed>=30% AND hadActiveBookings), i.e. 100/0; `CashRideFeeServiceTest` asserts elapsed tiers 100/70/50/0 - 5 tests red. Fee IS real electronic money even on a cash ride (:246-250 moves both wallet balances) OWNER RULING 2026-10-13: GRADUATED refund tiers (100/70/50/0), as the tests state. This changes refundCashRideCreationFee money behaviour; implement only after the owner-facing change is reviewed. |
| 123 | RV-48 | Every user gets an automatic 3.0 rating at signup; it is averaged into `average_rating` | P2 | OPEN | none | RV-47, ratings, admin dashboard | `R2 sec 137` - `UserObserver::created` (`:27-35`) `firstOrCreate`s a `UserRating` with `rater_id = null`, `rating = 3.0` for EVERY new user. `AdminDriverService::getStats()` averages ALL rows for verified drivers including these, so the dashboard average is anchored by synthetic platform data and a 5-star user reads 4.0 OWNER RULING 2026-10-13: KEEP the synthetic 3.0 signup ratings in the dashboard average. Update test expectations to match; no code change to UserObserver. `R2 sec 148`: suspended-fixture half FIXED in AdminDriverServiceTest: 7 fixtures moved from status 0 (logged-out) to a permanent ban (status -1), per RV-42a. Failures 15 to 10. The 10 remaining are rating-average expectations that ignore the synthetic 3.0; they need the RV-48 ruling applied to the expectations, not done in this step. Status stays OPEN until those 10 are updated. |
| 124 | RV-49 | resolveEscalated appends an audit prefix; the test expects the exact note text | P2 | OPEN | none | RV-47, audit trail | `R2 sec 138`. `StaffComplaintServiceTest::test_resolve_escalated_persists_resolution_notes` asserts the note equals the admin text; the service stores `"\n\n[RESOLVED by <name> at <time>]\n" + text` - a deliberate audit prefix. Test is red (1 failure). Not edited: the prefix is audit output, so the expectation or the prefix must be chosen by the owner OWNER RULING 2026-10-13: KEEP the [RESOLVED by ... at ...] audit prefix; update StaffComplaintServiceTest to expect it. |
| 125 | RV-50 | GeocodingServiceTest calls a removed geocode() with a contract the service does not provide | P2 | OPEN | none | RV-47 | `R2 sec 139`. 17 tests (11 errors + 4 failures + 2 more). Tests call `geocode($address)` expecting `[lat, lng]` or `null` on no match. Service has `geocodeAddress(): array` (Arabic-first, English fallback). Differences: (a) no-match returns `[]` shape not `null`; (b) English branch restricts `countrycodes=sy` (Syria), so an Amman test address cannot resolve, while the tests assume Jordan. Not a rename: making them pass needs a country-scope and a null-contract decision. Not edited OWNER RULING 2026-10-13: KEEP the service with Syria scope (countrycodes=sy). Rename tests to geocodeAddress(), expect [] on no match, and use Syrian test addresses. |
| 126 | RV-51 | ImageMessageType tests encode a retired chat-image contract (path as content) | P2 | BLOCKED | owner: the 2026-10-13 ruling assumed process() was dead; it is not | RV-47 | `R2 sec 141`. 4 failing tests in tests/Unit/Services/ImageMessageTypeTest.php. Production uploads an UploadedFile through ChatMessageHandler::sendImageMessage (:105-134) to FileUploadService::uploadChatImage, and validate() requires an uploaded image. The tests pass a stored path as content, so validate() returns false and process() reads a missing image key. Not edited: removing or rewriting process() is a design choice, not a test fix OWNER RULING 2026-10-13: REMOVE ImageMessageType::process() (dead branch) and its 4 tests. Deletion approved by the owner in this ruling. `R2 sec 149`: ruling "remove ImageMessageType::process()" CANNOT be applied as written. process() is a required method of MessageTypeInterface (app/Interfaces/MessageTypeInterface.php:15) and TextMessageType implements it too. ChatMessageHandler::handle calls $handler->process($data) for every non-image message (:83). Removing it from ImageMessageType alone breaks the interface contract. The ruling rested on the premise that process() is unreachable; it is unreachable only for uploaded images. Not edited. Owner must choose between an interface change and keeping the method with fixed tests. |
| 127 | RV-52 | Driver can cancel a ride after departure: validateCanCancelRide check is commented out | P1 | **VERIFIED FIX** | none | RV-47, cancellation, money | `R2 sec 144`. Owner ruled the window: 1 hour before departure. Re-enabled the commented-out check in `RideValidationService::validateCanCancelRide` (`:98-106`). Verified through the real route: inside the window = 422, ride stays active; 3 hours out = 200, ride cancelled. Past departure refused. RideValidationServiceTest 17 tests OK (was 1 failure). Rides 90 green, Bookings 11 green, Unit/Domain 122 green, KycActionGateTest + RV37ScorePolicyTest 10 green. Pint PASS. Commit: see git log. |
| 128 | RV-53 | Driver without verification documents gets a generic 500 on ride create, not a curated refusal | P2 | **OPEN** | owner: should missing-document refusal be 422 with a curated message | RV-45 | `R2 sec 147`. RideValidationService::validateDriverCanCreateRide throws a plain \Exception ("Missing required driver verification documents: Face ID Photo, Back ID Photo, Driving License") for a verified driver lacking documents. RideController::create catches only DomainException and \InvalidArgumentException, so this falls to the catch-all and returns a generic 500. Reproduced on HEAD with a fixture that has no documents. Not edited: changing the exception type or the status is a public response-shape decision |

## 3. Commit verification (every Evidence commit was checked in git)

The command reads only the **Evidence column** of the section 2 table, so the numbers below cannot
drift when prose elsewhere in this file names a hash:

```powershell
$pat  = '\b[0-9a-f]{7,40}\b'
$bl   = [System.IO.File]::ReadAllText('docs/audit/BACKLOG.md',[System.Text.Encoding]::UTF8)
$ev   = ($bl -split "`n") | Where-Object { $_ -match '^\| \d+ \| ' } | ForEach-Object {
          $c = $_.Split('|'); if ($c.Count -eq 10) { [regex]::Matches($c[8], $pat) } } |
        ForEach-Object { $_.Value } | Sort-Object -Unique
$good = $ev | Where-Object { git cat-file -e "$_^`{commit`}" 2>$null; $LASTEXITCODE -eq 0 }
"evidence tokens=$($ev.Count) commits=$($good.Count) missing=$($ev.Count - $good.Count)"
```

Output on this checkout: `evidence tokens=48 commits=48 missing=0`.

| Provenance of those 48 | Count | Note |
|---|---|---|
| Cited by the four audit files | 26 | the docs cite 30 existing commits in total; 4 are deliberately **not** used as task evidence here - `726b094` "audit progress tables added" and `83ecd1d` "audit sec 18 (commit map, open decisions, next)" are documentation commits (`R2` lines 1027/1029), `fa33fca` is the AF-4 documentation follow-up (`R2` line 1034, AF-4 itself is evidenced by `2687872`), and `993fab5` is cited by `S` only as "HEAD at the time of the original audit", not as a fix. |
| Cited by `STATE.md`, not by the four audit files | 2 | `e878f25` (RV-32), `97792b6` (RV-33). |
| Resolved from `git log` because the section that recorded the terminal state says "this commit" instead of a hash | 20 | `3443979`, `caebbfa`, `cd6a49a`, `51b6ca7`, `2d6a55e`, `cac4084`, `1173a69`, `364c3db`, `bc49fc6`, `c59fed6`, `bfc6fd1`, `4239087`, `b34df62`, `09c5c58`, `c785f73`, `8ece5af`, `53fe8d1`, `f4d1df8`, `70d1f36`, `c92e1e2`. Each was accepted only when **its own subject line names the task it is cited for**, checked with `git log -1 --format='%h %ad %s'`. |

Two further checks:

- The 39 hex-shaped tokens in the four audit files include **9 that are not commits and not cited as
  commits**: phone numbers (`0987654321`, `0912345678`, `963999000001`, `00963983337214`,
  `0983337214`, `963983337214` at `R1` line 237 / `S` line 1984), a date (`20260929`, `R2` line 250),
  the Aiven hostname fragment `1e81c0db` (`R2` line 980, inside
  `mysql-1e81c0db-atareeqak.b.aivencloud.com`), and the upstream MySQL 8.2.2 tag SHA
  `ba9859ea...000f4` (`S` line 2451). Excluding those, **every commit cited anywhere in the four
  audit files exists**. `HEAD` = `a6c4064`, branch `Agentic`, 136 commits on all refs.
- Nothing verified rests on a commit that does not exist. **15 of the 105 rows carry no commit hash
  in the Evidence column, and none of them is `VERIFIED FIX`** (recompute with the command above,
  changing `$c[8]` to `-not [regex]::IsMatch($c[8],$pat)` over all rows):
  - 12 rows whose work has not landed - `RV-03` (BLOCKED), `RV-26` (GATED, no change warranted),
    `T1-3` (BLOCKED), `T3-3` / `T3-17` (DEFERRED), and the OPEN rows `RV-08`, `RV-39`, `AF-5`,
    `AF-6`, `AF-7`, `T3-4`, `T3-10`.
  - `V7` (RECORDED but never run - a check needs no commit) and the two rename/absorb rows `AF-2`
    and `AF-3` (SUPERSEDED: the evidence is the pointer, and the work is committed under
    `AF-2'`/`92f454e` and `AF-4`/`2687872`).
  Every `VERIFIED FIX` row (56 of them) names at least one commit that `git cat-file -e` accepts.
  `RV-39`'s residual was additionally re-verified against the files on disk on 2026-10-03
  (section 4, RV-39), since it is the one OPEN row the rule currently selects.

The `T-` batch has no per-finding commits: all 39 `T-` findings landed in **`3443979`** (2026-09-28,
"Agentic: full audit remediation (T1-T4) + architecture/product audit"), except `T4-5`, whose
rollback is pointer-only in `S P2 sec T4-5` and whose later ratchet is `1d68c07`.
## 4. Acceptance criteria - OPEN / PARTIAL rows (corrected form)

Written against the newest evidence, with `R2 sec 1` corrections and refuted premises applied. These are
acceptance conditions, not implementation instructions.

**RV-01 - KYC documents exposed** *(blocked by 1b and 11)*
- KYC and complaint attachments live on a non-public disk, and no unauthenticated URL to them is reachable
  (`Storage::fake('public')` disappears from `VerificationControllerTest`, `DocumentControllerTest`,
  `ComplaintControllerTest`, `ComplaintAttachmentTest`; the `... contains 'storage'` URL assertion goes with it).
- A staff-authenticated streaming route exists first (decision 1) and is proven: authorized staff 200,
  another passenger requesting someone else's documents 403/404, `id`-guessing gives no oracle.
- Correction kept: `FILESYSTEM_DISK=s3` does **not** fix this (`R2 sec 1.1`) - `storeAs(..., 'public')` is hard-coded
  at >=8 sites, so acceptance is the call sites moving, not the default disk changing.
- Decision 11: a submission with a missing required document is rejected rather than accepted with an empty payload.

**RV-02 - no-show settlement (L2; L1 is closed)** *(blocked by 2 and 3)*
- `bookings.escrow_held decimal(15,2)` (column already added by RV-40) is the single source for "what this booking's
  escrow still owes"; every escrow movement is a conditional decrement (`WHERE escrow_held >= :amount`) and the
  transaction aborts unless exactly 1 row changed - so a second settlement cannot pay anything.
- `wallet_transactions.posting_key` is set and unique for once-only postings, and a partial seat cancel uses a key
  distinct from the full-settlement key (no collision, no double spend).
- Invariant proven by a test: `SyCash balance == SUM(bookings.escrow_held)` (derived, per the owner's direction).
- Do not re-litigate L1's premise: `V16` recorded that all-seats `cancel-seats` is already ledger+score equivalent to
  `cancelBooking`; the accepted defect is the missing lock and the missing per-booking escrow, not a proven double pay.

**RV-03 - staff cancel strands money** *(blocked by 6 - the only Wave-1 blocker)*
- Both staff cancel paths route through `RideService::cancelRide` / `BookingService::cancelBooking` with an explicit
  staff actor, so escrow is released/refunded instead of stranded and `processed_by`/audit fields are real (`T2-1`).
- A `staff_actions` audit row is written (employee, action, subject, reason, before/after state).
- The role gate matches the token issuer (`T2-2`) and denies `support_agent` with 403 while allowing the roles the
  owner names - verified on both the allowed and the denied path.
- Refund percentage and score impact come from decision 6, not from a guess.

**RV-04 - JWT integrity** *(blocked by 9)*
- A staff access token presented to a user endpoint is rejected (audience/type separated), proven by a test that
  reuses a real staff token against `/api/user` and gets 401.
- The empty-secret boot guard still fires, and `token_version` is identical in `User` defaults, `UserFactory` and the
  staff path so `sub`+`ver` cannot collide across tables.
- Access-token TTL is the value decision 9 picks (15-60 min), read from `config/jwt.php` (T4-4 already made it
  configurable - the remaining item is the number plus staff parity).
- Correction kept: there is **no** Sanctum half left to unify (`AF-4b` deleted it; `config/sanctum.php` absent) - the
  scope is the two hand-rolled HS256 families on one shared secret.

**RV-08 - deploy pipeline** *(blocked by 7; placement unconfirmed - conflict 13)*
- One deployment target is chosen (compose+nginx or Render) and the pipeline deploys to it end-to-end without a manual
  step, recorded as a run log rather than as intent.
- The build fails loudly on a missing migration or a bad env (no `continue_on_error`, no `|| true` - `T3-11` class).
- Secrets come from the deploy surface, never from a generated committed `.env` (`RV-07`).
- Acceptance must re-verify the premise: `R1 sec 4` wrote this before `T2-5`/`T3-10` touched deploy config; `T3-10` is
  still open, so "broken" currently means "unverified after the T-batch", not "proven broken".

**RV-09 - money concurrency** *(blocked by 3 / Wave 3 PAUSED; safe subset is closed)*
- Money paths stop swallowing exceptions (`PaymentService`/strategy layer re-throws) so a failed debit cannot look
  like success.
- Events carry IDs, not hydrated models (`ShouldBroadcast` after-commit serialization), so no stale-model money read.
- The throughput fix is the one the owner paused: replace the single mutable SyCash row with per-booking `escrow_held`
  (RV-02 L2) so SyCash is derived, verified under a concurrent-harness test with 2 writers on one booking.
- Correction: `DB::transaction` retry cannot fix the hot-row serialization; the SyCash-first lock ordering already
  shipped in seven entrypoints makes it deadlock-free, which is a different property from throughput.

**RV-10 - ride lifecycle and escrow liveness** *(blocked by 2)*
- `rides:advance-status` exists and moves `active -> launched -> completed` using the auto-confirm window from
  decision 2, tested at both sides of the boundary (just inside / just outside).
- Escrow release stops depending on a driver tap that may never arrive; a stuck booking cannot hold money forever.
  **D2 = D+C ANSWERED 2026-10-04**: read-only stuck-escrow reporter first, then staff-queue escalation.  - and this row was wrong to call the remainder decision-free. Verified in code: a
  `CONFIRMED` e-pay booking's escrow is released only when THAT passenger confirms
  (`EPayPaymentStrategy::processRideCompletionPayment`) or when `checkAndCompleteRide` clears BOTH gates (driver
  confirmed AND every confirmed booking's passenger confirmed). Anyone who taps is paid, so this is NOT a
  whole-ride freeze - the exposure is a passenger who never taps on a ride whose driver also never taps, and
  `bookings:expire-stale` cannot help because it only touches `PENDING` bookings that never took money
  (`R2 sec 53`). **The fix is a money-semantics choice** - release to driver, refund to passenger, or escalate to
  staff - and the WINDOW is the missing number. Decision 2 already ruled "do NOT auto-confirm" for pending
  bookings, so this must not be inferred. A read-only sweeper that only REPORTS stuck escrow (age + amount, moves
  no money) is decision-free and was deliberately NOT built, because it was not asked for.
- The booking rule for past-departure rides is decided once and applied consistently; the ~335 settlement fixtures
  that deliberately book past-departure rides are moved to a supported test path instead of relying on the hole.
- Closed already, do not redo: the decision-free search guard (departed rides no longer returned) is `VERIFIED FIX`.

**RV-11 - score subsystem** *(the 4 defects are FIXED - `R2 sec 67`; what remains is a BUILD, not a migration - `R2 sec 73`)*
- **DONE - the double-count.** A completed ride increments `total_rides` exactly once, so `cancel_rate`, whose
  thresholds gate penalties, is right. Previously `ScoreService:277` and `recordRideCompleted:52` both incremented it.
  Pinned by `RV11RideCountTest` at 1 ride and at 3, for the driver and for every passenger.
- **DONE - the consequence, which is the part that matters.** Three of the new tests assert whether the **50%
  high-cancel gate actually fires**, including two users given the same cancellations and different ride counts
  where the gate must fire for one and not the other. Penalties previously under-applied; no user was over-charged.
- **DONE - the policy.** `START_SCORE` 70 / `MIN_SCORE` 0 / `MAX_SCORE` 100 are now constants on `ScoreService` used
  by all four creation paths and by both mutation paths, instead of four separate literals of which one said 100.
  The `applyDelta` docblock that claimed a `[0, 200]` ceiling - the scale the owner rejected in un2 - is corrected,
  and so is an orphaned comment that still described the pre-sec-42 tier arrangement.
- Remaining, and none of it causes a wrong score today:
  - **DONE (`R2 sec 74`).** **CORRECTED first (`R2 sec 73`) - this criterion was factually wrong.** It said "One mutation path
  (`ScoreLedger::apply()`) writes the score; the service still has several." **`ScoreLedger` does not
  exist** - no file, no caller, no `score_ledger` table - so there is nothing to migrate TO. `R2 sec 67`
  had already recorded the opposite thirty lines earlier; the status authority was the wrong side of that
  disagreement. The real remaining work is to BUILD `ScoreLedger::apply()` as the single write path, not to
  route call sites to an existing one - a larger job than this sentence implied. It is now genuinely
  unblocked: `R2 sec 1923` said it needed the owner to pick the clamp ceiling, tier bands and start score,
  and un2 (`de61c7b`) + `R2 sec 67` + `RV37ScorePolicyTest` have since answered all three - so
  `Blocked by = none` is correct for the first time. Not started: wide-blast-radius refactor of a subsystem
  ride completion, cancellation, no-show and rating all run through. **Built and verified in `R2 sec 74`.**
  Also confirmed here: no `config/score.php` exists, and the `tier` column IS dead - the only two `tier`
  references in `app/` are `$userScore->tier` (`ScoreController:157`, `StaffOperationsController:183`), which is the accessor - **still open, dropping a column is ask-first**;
  which is the computed accessor, not the column. Dropping it is a migration and remains ask-first. the exact edge values, not sampled in the middle. **DONE** - `RV37ScorePolicyTest`.

**RV-12 - account status model** *(blocked by un13)*
- The `status = 0` "logged out" and "banned" overloading is separated: a temporary ban that has expired can never lock
  a user out (`isBannedNow()` + `banHasExpired()` shipped; remaining work is removing the persisted-0 convention).
- Every reader goes through one `BanService`/`Ban` value object instead of 9 scattered `status` comparisons, and the
  admin list readers and the auth middleware agree on what "active" means.
- `createUser` no longer hard-sets `status => 1` for paths the owner did not intend.
- A test proves: expired ban -> login works, live ban -> login refused with the right code, lift via the staff path ->
  caches busted (`auth.user.{id}` evicted, the shipped `bustBanCaches` half).

**RV-13 - error model** *(envelope = un4; halves 1-2 are decision-free and unblocked)*
- `App\Exceptions\Domain\*` exists with a code + HTTP status per rule violation, and the 61 services currently throwing
  `InvalidArgumentException` throw domain exceptions instead, so a domain rule violation is no longer a 500.
  **HALF DONE (`R2 sec 64`, `f9f536a`):** the hierarchy + `Handler` mapping ship and a domain violation answers
  422/403/409 instead of 500. The 61 call sites still throw the base `InvalidArgumentException`, so they get one generic
  `DOMAIN_RULE_VIOLATION` code rather than a per-rule one - that migration is the remaining decision-free work.
- Controllers stop catching `Throwable` and stop returning `getMessage()`: 96 catch blocks and 121 `getMessage()`
  returns drop to the ratchet ceiling, so SQL text and table names stop reaching clients.
  **DONE (`R2 sec 64.1`-`64.5`):** measured 47, ratcheted shrink-only, swept to **ZERO**. The ratchet counts only BROAD
  catches, because 21 of the original 47 were app-authored messages under a NAMED catch (`sec 64.3`); the 3 remaining
  `InvalidArgumentException` sites are correctly not counted.
- `Handler::report`/`render` stop stripping the validation `errors` bag (the `V5` defect, fixed in `980741c`) -
  regression-pinned.
- The `$e->getCode() ?: 500` pattern elsewhere: an exception CODE is not an HTTP status, so a caught database error can
  currently become a hard failure inside the error handler. Decision-free, still open (`R2 sec 64.4`).
  **DONE (`R2 sec 68`)** - with a correction: the live sites were 4 x `: 422` in `WalletRequestController`, not `: 500`
  (the `: 500` form was already removed from `ProfileController`). `$code` defaults to 0, so any throw that forgot a
  code silently became 422 - the status was being GUESSED at the controller - and any non-HTTP code would have been
  passed straight to `response()->json()`. `WalletRequestService` now throws `BusinessRuleViolation`/`ConflictViolation`
  with stable codes and the controller reads `$e->httpStatus`. **Statuses (422/409) and response shape are unchanged**
  on purpose: moving an endpoint's answer, or adding `code`/`status_code` via `toArray()`, is a public API change and
  is the owner's call. Ratchet at ZERO over `app/Http/Controllers` + a test proving the ratchet can still SEE a
  violation. `AdminWalletRequestController` deliberately untouched - it does not use this service and hardcodes 422.
  **REMAINING (a):** the SPL `\DomainException` throwers in `ComplaintService` (1), `EmployeeManagementService` (18)
  and `StaffComplaintService` (8) must migrate together WITH their controllers, or the exception escapes to a
  `catch (\Exception)` and answers 500. The 61+ `InvalidArgumentException` sites are unchanged and still correctly
  answer 422 with the generic `DOMAIN_RULE_VIOLATION` code.
- Only after those halves: one envelope `{success,data,error{...}}` and the `assertNotEquals` ratchet over the 13
  surveyed occurrences (`ProfileTest` 4, `NotificationTest` 3, `BookingTest` 3, `StaffComplaintControllerTest` 2,
  `ChatTest` 1) rewritten to exact statuses. Correction: writing "the exact status" today would enshrine 500s (`R2 sec 20.2`).

**RV-14 - route/controller mismatches** *(finish/driver-confirm + units = un5)*
- `/rides/{id}/finish` and `/driver-confirm` either do the documented thing or return 410 - they must not return a
  success body for work they do not do; the `RoutesIntegrityTest` ratchet keeps the unrouted-method class closed.
- `rides.price_per_seat` is bounded by one config value at both write and read, with the widened `decimal(15,2)`
  (done by `RV-40` in `2026_10_02_000001_widen_rides_price_per_seat_to_money_precision.php`) and a MySQL guard so
  SQLite never sees a shrink.
- `rides.distance` / `rides.duration` have one documented unit; the fixtures that insert `320.5` and `320500` for the
  same ride are normalized to it.
- `create-with-route` reuses `CreateRideRequest` and the duplicate validator is deleted, so the two create paths cannot
  drift again.

**RV-16 - OTP and mail flows** *(blocked by 4 and 8)*
- The phone-OTP endpoints and the `sleep(5)` in the TextMeBot worker are both deleted or both kept, per decision 4 -
  no half-removed flow.
- Mail leaves the signup DB transaction (after-commit) so a mail failure cannot roll back an account, and the account
  enumeration answer (decision 8) is applied uniformly to forgot/signup.
  **DONE (`R2 sec 69`)** for the transaction half. `SignupController` PATH B used to send the verification mail from
  INSIDE `DB::beginTransaction()` and roll the account back on send failure. That is wrong when SMTP delivers and
  then times out: the user holds a real code for an account that no longer exists, and must restart under a different
  address. The transaction now covers only the `users` row and commits FIRST; the OTP send happens after the commit,
  where nothing may roll the account back. PATH A already re-sends the code for an unverified account without touching
  the password, so the failure is now recoverable. **The mail-failure MESSAGE changed** (the old "Registration
  failed" is now false); status is still 500 and the JSON keys are unchanged. Needle both ways, byte-identical
  restore; bisect 178 tests / 412 assertions OK at HEAD vs 183 / 429 OK after - zero failures either side.
  **DONE (`R2 sec 72`)** - it was NOT verified, and decision 8's "already-correct" note was wrong.
  Four unauthenticated endpoints told an attacker whether an address had an account: `password/forgot`
  404 "No account found", `password/verify-otp` 422 (a validator `exists` rule, so not even a branch),
  `password/reset` 404 "Account not found.", and `email-verification/resend` 404 **and 409**. The `resend`
  endpoint needed TWO edits - removing only the 404 left 200-vs-409 separating "exists and unverified"
  from "exists and verified", so the oracle survived the obvious fix; all three states now answer
  identically, giving up the "already verified" hint (decision 8 rules it out as an oracle). The pinned
  property is byte-identical status/success/message for registered vs unregistered, not "no not-found
  string". **Two defects were caught by the new tests during the task:** (1) my first fix still leaked,
  because the unknown-account branch said 'Invalid or expired code.' while the real failed-code branch
  passed through 'Invalid or expired **verification** code.'; (2) making resend uniform dropped the
  dev-only `otp_code` and broke the verify-after-resend flow - caught only because the whole Auth floor
  was run. A boundary edge I created (`use App\Models\User` in a controller, 21 -> 22) was removed by
  moving the lookup to the injected `UserRepositoryInterface`; **baseline not raised**. Needle both ways;
  controlled bisect 458/1658/4F at HEAD vs 466/1674/4F after, failure-set diff EMPTY. Four tests flipped
  (each pinned the leak by name), none weakened.
  **Still open:** only the `APP_ENV=production` `.env` deploy action, which is the owner's machine, not code.
- No path returns `otp_code` outside local/testing regardless of provider state (shipped: `OtpDisclosure::sanitize()`
  on all three services including the send-failure path), and OTP storage stays keyed-HMAC (`d776c1f`).
- The `APP_ENV=production` `.env` that tripped the guard is fixed as a deploy action; no test may weaken the guard.

**RV-17 - load-test validity** *(blocked by un12)*
- The k6 harness logs in through `setup()` and seeds valid rides/bookings, so every scenario hits a real resource;
  the hard-coded/expired tokens are gone and the new `real_4xx_errors` threshold therefore passes instead of correctly
  failing.
- Scenarios use `constant-arrival-rate`, split READ and WRITE, and carry per-endpoint `2xx rate >= 95%` plus
  `http_req_duration{expected_response:true}` thresholds.
- Runs are 3x with a 5-minute steady window and the result JSON records the git SHA; README numbers are regenerated
  only from those runs.
- Corrections kept: the `A/B/C` JSONs are a 2-endpoint closed-model run (its rps 518/538/531 cannot show capacity,
  only p95 415/323/337 is meaningful), and `V11` found GET+POST both registered, so the k6 GET produced 422, not the 404
  `R2 sec 1.2(1)` predicted - the accounting fix (`56c989a`) stands either way.

**RV-19 - fake or derived numbers in admin** *(blocked by 10 and un7)*
- No admin metric is a literal or a recomputation: `pending_complaints` is queried (done), driver "earnings" are read
  from the ledger rather than derived as `seats x price x 0.95`, so cash rides and cancellation payouts report truthfully.
- The 5% platform fee lives in `config/fees.php` and one money helper, replacing the four hard-codings
  (`WalletTransactionService`, `AdminDriverService`, `SyrideSeeder`, docs).
  **DONE (`R2 sec 71`)**, and it uncovered a live crash on the way. Two of the sites rounded each share
  independently - `round($total*0.95,2)` and `round($total*0.05,2)` - which do NOT always sum to `$total`; they
  diverge by a cent for every total ending in an odd tenth (0.10, 99.90, 1000.50...). `postTransfer` refuses
  unbalanced legs, so `releaseEarningsToDriver` and `processPassengerNoShow` **threw**, and because
  `checkAndCompleteRide` is one transaction the ride could never finish and every later confirm threw again -
  a concrete instance of RV-10's escrow-liveness criterion. Every fixture and the seeder use whole numbers,
  which is why it survived. Owner ruled: driver gets `round(total x 0.95)`, platform gets the remainder (the form
  `releaseEscrowToDriver` already used). Now `config/fees.php` + `App\Support\FeeSplit`, whose subtraction is
  exact in integer minor units via the existing `Money` value object; the `AdminDriverService` SQL literal is now
  a bound parameter. Needle both ways - the old form reproduces `Refusing to post an unbalanced ledger transfer:
  legs sum to 0.01` verbatim; byte-identical restore; bisect 614/1613/16F at HEAD vs 642/1657/16F after with an
  EMPTY failure-set diff. Boundary baseline untouched (`models_to_enums` fails only on the known
  Complaint/Employee/Wallet trio, row 106).
  **R2 sec 76: item 5 DONE** - 	otal_rides counted a relation eager-loaded ->limit(5) (a driver with 7 rides reported 5); suspended_drivers was hard-coded 0 while the same screen's table filter listed them; an unknown period got a week window but echoed the REQUESTED value, so period and period_label contradicted each other. All three needles fire; the three bug-pinning tests FLIPPED to corrected behaviour, none weakened. **Still open here:** item 2 (admin earnings still derived, not read from the ledger - report-semantics question) and item 4 (config/system_admin.php is ABSENT, so VerificationRepository:69's config('system_admin.email') is null and the new-driver rating seed NEVER runs - proven, but every fix is a product decision)
  (`AdminDriverServiceTest` still pins the bugs).
- `config/system_admin.php` exists (the `V8` finding) or the read sites are deleted, so revenue is not silently 0.
- `AdminDriverServiceTest` stops pinning the bugs (`total_rides` capped by an eager-load limit, `suspended_drivers`
  always 0, unknown `period` silently treated as week) and is flipped to assert the corrected behavior.

**RV-20 - payment strategy wiring** *(blocked by 2 and 3; coupled to RV-02 L2)*
- `charge` (`BookingService:112/:172`) and `refund` (`RideService:184`) go through `PaymentStrategyFactory`, so there is
  one place that moves money per provider.
- Behavior is proven identical for the existing e-pay path first (characterization test), then the dead/unwired
  strategies are deleted or implemented - no third fake success.
- Correction: "one-third wired" is stale; `R2 sec 26.14` measured ~20% remaining after earlier waves took the
  charge/refund halves.
- Lands with RV-02 L2, not before it, so the escrow model is not written twice.

**RV-21 - wallet identity and money creation** *(blocked by un3 and un10)*
- `wallets.kind` distinguishes user / escrow / platform / treasury, so an ordinary user wallet can never be read as the
  platform balance; the reserved-phone boundary is already closed (`70d1f36`).
- Top-ups are double-entry (a contra account moves too), so `SUM(balances)` is a meaningful invariant, and
  `ledger:reconcile` (AF-6) can assert it.
- Admin wallet adjustments get maker-checker plus a daily limit, and the `wallet_requests.wallet_id NOT NULL` schema
  question from `R2 sec 19.3` is settled as part of this (it currently blocks 26 tests).
- Correction: `ensureSystemWallet()` failing loudly is shipped, not pending; do not re-add a print-and-continue path.

**RV-23 - complaints** *(blocked by un6)*
- Exactly the three items `R2 sec 28.8` records after slice 2: (a) `GET /staff/complaints/{id}` no longer mutates
  state (the auto-transition) - a behaviour change to decide with the owner, not to slip in under a test fix;
  (b) the conflict double-notification (both parties currently get the same text, `L473-490`) becomes one
  correctly-worded notification per party; (c) whether the public store endpoint may accept the internal
  `no_show` type at all (currently a 422, and "likely intentional gate" per `sec 28.8`).
- Slices 1 and 2 stay proven as the rest lands: a no-show conflict complaint keeps its `ride_id` + `complained_id`
  (`c59fed6`, additive nullable migration) and assignment goes to the least-loaded agent instead of one agent
  forever (`bfc6fd1`) - the latter was the SLA/availability failure `sec 28.3` names.
- The racy transition, when it moves, is guarded conditionally (`WHERE status = ...` or an equivalent lock),
  matching the `T3-15`/`RV-09` discipline rather than an unguarded read-then-write.
- `StaffComplaintControllerTest` stops being order- and side-effect-dependent once (a) lands; today its baseline-red
  member (`test_store_accepts_all_valid_complaint_types`, the `no_show` 422) is pinned as a pre-existing item, not
  edited to pass.

**RV-29 - auth hardening batch** *(blocked by 5 and un11)*
- `auth.user.{id}` caches a DTO with no credential material - the password hash stops leaving the row (the leak was
  PROVEN in `sec 28.9`; the fix is gated because `JwtAuthMiddleware` auto-lifts and a careless `update()` there can lose
  data, which must be handled in the same change).
- Refresh-token reuse detection revokes the whole family for both user and staff (shipped, `bc6acaa`) and is proven by
  a test that replays a rotated token and sees both tokens dead.
- `communication_number` in `RideResource` / `BookingResource` is gated per decision 5; because gating subtracts a key
  the live Flutter client reads, acceptance includes an agreed client-side answer, not just a server flag.
- The privileged-`$fillable` item is **not** part of this task's remaining work: `sec 34` proved the vector is not open and
  pinned a ratchet instead (`1d68c07`), and narrowing was declined twice on owner instruction (`T4-5`).

**RV-37 - test determinism and hermeticity** *(Blocked by = none; every decision-free criterion CLOSED `R2 sec 39` + `39.1` + `39.2`)*
- `Http::preventStrayRequests()` is on in `TestCase::setUp` and the suite passes, after the tests that legitimately call
  a provider are given a container-level fake (`ROUTING_DRIVER=fake`, faked FCM/geocoding bindings) - acceptance is
  "no test can reach the network", not "the number of strays is small". **DONE (R2 sec 39.1): guard armed, zero strays
  across the full suite; the Guzzle-direct seams (WhatsApp/TextMeBot OTP, GoogleController) that the facade cannot see
  are closed by neutralising their credentials in the test bootstrap; both needle-proven.**
- Order independence demonstrated across at least three different `--order-by=random` seeds with the seed printed in
  the CI log, plus the CI job running the suite twice; `V14`'s single seed is not proof, and the corrected count is
  68 default failures vs 85 under the `V14` seed (`R2 sec 23.6`). **DONE (R2 sec 39 + 39.2): default + seeds
  20260929/424242/777001/758619 (4 random + default) give a byte-identical 120-test red set with zero committed
  residue; the CI job now runs the suite twice (default + random, seed printed) in `sonar.yml`.**
- No `putenv()` in tests (already 0 - pinned) and no test writes a tracked file; the ratchet keeps both closed.
  **BOTH PINNED (R2 sec 39 tracked-file ratchet + existing putenv ratchet).**
- The remaining order dependence is root-caused (not just reduced): the failure set under random order equals the
  default-order failure set. **DONE (R2 sec 39): the source was 5 test classes writing rows with no transactional trait
  (7 committed users per suite pass); sec 23.6's "0 classes without a trait" ruling was wrong - now measured, fixed,
  and pinned by `TestDeterminismRatchetTest::every_test_class_that_writes_to_the_database_is_transactional`.**

**RV-38 - Eloquent strictness outside production** *(blocked by un9)*
- The two data-integrity flags stay on outside production (`preventSilentlyDiscardingAttributes`,
  `preventAccessingMissingAttributes`, `61896df`) with the ratchet test pinning it.
- The lazy-loading flag is armed only through `GuardsLazyLoading` (`c92e1e2`) - the boot-listener arming was proven not
  to survive Laravel's test dispatcher reset, which is why the first enable was rolled back (`7ec0047`).
- Enabling requires the measured residuals to reach zero under the `sec 37` scoped harness; the recorded numbers are
  flag ON = 1 error / 18 new failures on the same 267 tests, so as written option A does not reach 0 and the flag stays
  OFF until the owner decides.
- Corrections that must not be re-introduced: the "9 `User::profile` N+1s" premise is NOT reproducible (`sec 35` VERIFIED
  ROLLBACK; the earlier run was corrupted by a concurrent `artisan migrate`), and `sec 37`'s "the 3 sites do not address
  these" is an unverified inference, not a finding.

**RV-39 - seeders** *(VERIFIED FIX 2026-10-02 - all four criteria below are satisfied; see R2 section 38)*
- `RefusesProduction` exists and is applied to every seeder/command that can destroy data - verified on disk today: no
  such trait exists anywhere, and `SyrideSeeder`, `BulkRideSeeder`, `Atarikaktestseeder`, `UserRealFlowSeeder` still run
  under `--force`.
- `Syrideseeder.php` is renamed so the declared class matches (`SyrideSeeder`), and the namespace==path test covers
  `database/seeders` (the `RV-35` test only covers `tests/`).
- `SyrideSeeder::truncateTables()` truncates every table the app writes - at minimum `noshow_reports`,
  `refresh_tokens`, `otps` are missing today.
- The seeder stops hand-writing the third ledger-type vocabulary (`ride_creation_fee_received`, `ride_payment`,
  `escrow_hold`, `ride_earning` at `Syrideseeder.php:694/785/794/816`) and uses the shared enum, and `DriverSeeder:101`
  / `PassengerSeeder:94` stop giving every wallet the same `self::COMM_NUMBER`.

**AF-5 - shared object storage** *(blocked by un1; re-scoped by RV-01)*
- Object storage is reachable from more than one app replica and survives a redeploy, with MinIO in dev and a real
  bucket in prod behind the same interface.
- Acceptance **includes** the code move: the hard-coded `'public'` disk at the >=8 upload call sites goes through a
  config-driven private disk - without it, `FILESYSTEM_DISK=s3` changes nothing (`R2 sec 1.1` / `R1 sec 2` A3.1: "AF-5 must
  be re-scoped").
- KYC/complaint privacy story lands with it or before it (this row cannot close before RV-01's storage half, and must
  not silently satisfy it).
- Signed or streamed URLs replace public URLs; a test proves an unauthenticated guess of a storage path is useless.

**AF-6 - money module** *(RE-MEASURED at `R2 sec 112`; the row is now `PARTIAL` and the Wave 3 pause note is historical)*
- `Money` value object replaces the raw decimal math across the 7 services / 66 sites, with rounding decided in one
  place (no float, no per-call `round()`). **NOT MET.** `Money` is used in 5 files and ~20 of ~29 sites are
  `formatted()` presentation. The GOAL half is met where it matters - the 95/5 split goes through `FeeSplit` in
  integer minor units (`sec 71`) - but the coverage is not. **CORRECTED: this cannot be done by substitution.**
  `Money` refuses negatives (`Money.php:29-37`, `:111-116`) and the money paths are built on negatives. The
  acceptance is therefore a decision on signed money (widen `Money`, or add a signed type), which `AGENTS.md`
  reserves for the owner. **ANSWERED 2026-10-11 - WIDEN `Money` to signed (do not add a second money type) - and
  BOTH STEPS ARE NOW DONE (`R2 sec 116` signed the type, `R2 sec 117` converted the sites).**
  **MET.** `Money` is signed (ctor accepts negatives, `subtract()` returns them, plus `isNegative()`
  `negated()` `absolute()` `assertNotNegative()` `assertPositive()`), and the two callers that depended on
  the old throw BY ACCIDENT now state the rule explicitly (`FeeSplit::driverAndPlatform`,
  `AdminWalletService::chargeWallet`) so the protection moved instead of vanishing; NEEDLED both ways.
  **The site census was re-derived, not recalled, and the "66 sites" in this criterion is STALE** - it counted
  the `round($x * 0.95, 2)` sites `sec 71` folded into `FeeSplit`. What remained was **14 sites in 7 files**,
  and they were classified before any was touched. Converted: `LedgerService` `:52-58` (the balance invariant,
  was float + `round()` + `!== 0.0`, now an exact sum of integer minor units), `:81`, `:107`, `:143`,
  `:147-148` (raw unary minus), `:176`; `CashRideFeeService:343` `:388`; `WalletTransactionService:1229`;
  `BackfillBookingMoneySnapshot:92`; `PassengerProfileController:543`; `Testfullrideflow:211-212`; and the Tier-2
  JSON `round()`s at `AdminDriverService:417,419,421` and `PassengerProfileController:479,481,513`.
  **DELIBERATELY NOT CONVERTED: `PassengerProfileController:480` is `round($avgRating, 1)`, a RATING at 1dp.**
  Converting it would be wrong; it is pinned by a test so a later reader does not "fix" it. **This fixed NO
  live bug and is recorded as such:** a controlled probe of the old float form against the new one over seven
  realistic leg sets reports **0 of 7 disagreements**. The old check was correct only because of a trailing
  `round()`; what changed is the MECHANISM - rounding is decided once inside `Money::from()` instead of at 14
  call sites, and the invariant is exact rather than rescued. My first probe dropped that trailing `round()`
  and produced a dramatic table; it was wrong about the code it described and was rewritten before anything
  was concluded from it. Held in place by new `tests/Feature/Review/MoneyRoundingIsDecidedInOnePlaceTest.php`
  (5 tests / 24 assertions): a structural ratchet that no converted file may `round()` a money token - with
  comments stripped by a quote-aware pass, because the conversion left comments NAMING the old `round()`
  calls - plus its **negative control** (every converted file must still use `Money::`, or a file that simply
  deleted its arithmetic would satisfy the ratchet and prove nothing) and a test that non-money rounding
  SURVIVED. **NEEDLED three ways:** removing the balance check fails exactly
  `an_unbalanced_transfer_is_refused_rather_than_recorded`; restoring a per-call money `round()` fails the
  ratchet and it reports `LedgerService.php:87` with the line; reverting `BackfillBookingMoneySnapshot` to raw
  floats fails the ratchet AND the negative control. All restores SHA256-identical. **Controlled bisect:
  986 tests / 2729 assertions / 31 failures - IDENTICAL at HEAD with all 7 app files reverted. Zero
  behavioural change.** A duplicated `reserves for the owner.` fragment left by the `sec 116` close-out, which
  sat inside these criteria for two commits, was found and removed.
- One `LedgerEvent` enum is the only vocabulary for `wallet_transactions.type` - the two-dialect problem (`D2`) is
  gone, and the seeder's third dialect (`RV-39`) cannot reappear because the column rejects unknown values.
  **CORRECTED (`R2 sec 112`): the vocabulary is `App\Enums\LedgerType`, not `LedgerEvent`, and this is MET** -
  pinned repo-wide by `RV39SeederHygieneTest::test_every_wallet_transaction_type_written_anywhere_is_a_shared_ledger_type`.
  **The clause "the column rejects unknown values" is REFUTED**: `2026_10_03_233000` made the column varchar
  (owner decision 13, `sec 54`). The RATCHET holds the vocabulary, not the schema. Do not re-assert the schema
  as the guard.
- `ledger:reconcile` runs as a scheduled job and reports a real mismatch when one is injected, proving
  `SUM(balances)` and `SyCash == SUM(bookings.escrow_held)`. **PARTLY MET, two clauses corrected (`R2 sec 112`).**
  Scheduled: yes (`Kernel:84`, daily 04:30). **"proving `SUM(balances)`" is a REFUTED premise** - the command
  compares `SUM(wallet_transactions.amount)` against `SUM(ledger_entries.amount)` per wallet and never reads
  `wallets.balance`. The escrow invariant is pinned elsewhere, in the corrected e-pay form, by
   **"Reports a real mismatch when one is injected" is now MET (`R2 sec 114`)** - new
   `tests/Feature/Review/LedgerReconcileDetectsDriftTest.php`, 8 tests / 25 assertions, injects the only fault shape a
   real bug can take (`LedgerService::postTransfer` refuses legs that do not sum to zero, so the realistic fault is a
   MISSING leg, not a bad one) and proves the per-wallet report fires, names the wallet and quantifies the drift
   with its sign. **NEEDLED:** making drift unreportable (an always-true clause added to the tolerance check) fails
   **6 of the 8** tests and leaves only the two NEGATIVE controls green, which is what a causality test should do;
   `ReconcileLedgerCommand.php` restored SHA256-identical. Two of my own assumptions were refuted by measurement and
   are pinned instead: `--threshold 0.0` CANNOT surface sub-cent drift (the drift is rounded to 2dp at
   `ReconcileLedgerCommand:76` BEFORE the comparison - correct for `decimal(15,2)`, RV-40), so the tolerance is pinned
   at the CENT scale; and two `expectsOutputToContain` values on the SAME output line can never both match, because
   `PendingCommand:423-431` registers one Mockery expectation per `BufferedOutput::doWrite` and Mockery attributes
   one call to one expectation - proven by a probe where all five candidate strings matched alone and only the
   chained pair failed. `Artisan::output()` returns an empty string in this harness, so the console reporting path is
   used and is order-independent (the clean-data test passes in isolation and in the full file). **THE EXIT CODE IS NOW
   ASSERTED too (`R2 sec 115`).** Owner call (a) was ANSWERED - **FAIL**. The command detected drift correctly all along;
   what it did not do was exit non-zero, so the 04:30 scheduler logged a full alarm report and reported SUCCESS. That was
   a defect in the RESPONSE, not the detection, and the detection is what `sec 114` shipped and pinned.
   `ReconcileLedgerCommand` now returns `FAILURE` when any wallet is unexplained. NEEDLED by reverting it, which fails
   **7 of 9** of the new file and leaves only the two tests that assert exit 0 either way. The `--threshold` pair above
   is now load-bearing: a tolerance raised above the real drift returns exit 0, which is exactly how the new alarm would
   be silenced - still a legitimate operator choice, now documented as one. The `DoubleEntryLedgerTest` success test is
   still green and was deliberately NOT edited: clean data must exit 0.
- Aliases are the acceptance set: it is done when RV-02 L2, RV-09, RV-10, RV-11, RV-15, RV-20, RV-21 (Wave 3) are.
  **6 of 7 are `VERIFIED FIX`; RV-10 is `BLOCKED` on the owner window W.**

**AF-7 - controller extraction / Larastan** *(blocked by AF-6 and un8)*
- 21 of the 38 controllers that touch models inline and the ~2,500 LOC of business logic in admin/staff controllers sit
  behind services, so the `controllers_to_models` baseline (21 today) moves toward 0 and never rises.
- Larastan is installed and analyzed with `--generate-baseline` at level 4-5 in CI, where CI fails only on NEW errors
  (`R2 sec 1.3 RV-33`; the sandbox has no composer network - the acceptance check is the CI run).
- The boundary ratchet moves from file-counting to edges (Deptrac/phpat/Arkitect with a baseline file), since a
  fully-qualified class without a `use` is invisible to today's `grep`-style check.
- Blocked honestly: the extraction target shrinks as AF-6 moves reads behind services, so AF-7 lands after AF-6.

**T3-4 - `wallet_requests.processed_by` references users, but admin actors are employees** *(OPEN; blocked by un10 + the settled T2-1 decision)*
- A wallet request records which **employee** approved it: either a nullable `processed_by_employee_id` or a polymorphic
  `processed_by_type`/`processed_by_id` pair, added by a MySQL-guarded migration in the T3-2/T3-5 pattern.
- The existing `processed_by` (users) column is not silently repurposed: either it is deprecated with a recorded note or
  the two actor kinds are separated, so `R2 sec 19.3`'s 26-test family resolves one way and is not left half-migrated.
- Every admin/staff write path that sets an audit field sets the employee one (`T2-1` class), proven by a test that
  approves a request through the admin token and reads back the employee id.
- Blocked honestly: `T2-1`'s VERIFIED FIX chose the `users` reference on owner instruction, so this row cannot move
  without the owner reversing that decision - it is not an agent call.

**T3-10 - Deployment resets the server to a feature branch and persists a token in the remote URL** *(OPEN; blocked by T1-3 and decision 7)*
- The deploy target checks out a released/integration ref, never a personal feature branch, so a deploy cannot ship
  unreviewed work or fail because the branch moved.
- No credential is written into `.git/config` or any remote URL: the remote is `https://...` with the token supplied by
  the deploy surface's secret store (`RV-07` class - a token in a remote URL is a committed secret by another name).
- Verified: no deploy script does `git reset --hard origin/<feature>` against the production tree, and the post-deploy
  step runs migrations with the same loud-failure discipline as `T3-11` (no `|| true`).
- Blocked honestly: the remote URL and the branch policy live in the same deploy surface as `T1-3`'s history purge and
  depend on decision 7 (which target exists at all), so the shape of the fix is not yet knowable.

**RV-26 - GATED (no work until the owner reopens it)**
- Do not "fix" this: `R2 sec 28.1` records that its own fix text refutes the headline, because `T2-2` already changed every
  `/api/admin/*` gate. Acceptance is only "the owner decides whether a role x endpoint matrix is still wanted".
- If reopened, start from the `sycash` role question (decision 1) - `R2 sec 1.2(3)`: `SpecialAccountSeeder`'s docblock and
  `T2-2` contradict each other, and that contradiction is explicitly not to be resolved in code.

**T1-3 / T2-8 / T4-5 / T3-3 / T3-17 - BLOCKED, DEFERRED and owner-action rows (unblock conditions; the OPEN rows `T3-4` and `T3-10` have their own blocks above)**
- `T1-3`: rotate `config/firebase-credentials.json` + `dump.sql` material, then `git filter-repo` + force-push with all
  clones coordinated; acceptance is `git log --all -- <path>` returning no blob. No code change can close it.
- `T2-8`: owner rotates the Pusher credential whose committed literal was byte-identical to the live value, then the
  literal default is deleted. Note `STATE.md`'s settled "burned, do not rotate" decision covers the Aiven DB password
  and the OpenRouteService key only - Pusher is not in it.
- `T4-5`: acceptance is the owner explicitly choosing a per-context allowlist migration (DTO `updateProfile()` +
  explicit admin scopes); `sec 34` proved no site currently passes raw request input into `User`, so the vector is not
  open and the ratchet (`1d68c07`) is the shipped control.
- `T3-4`: needs the owner to change the `T2-1` decision; then `processed_by` gains an employee reference (or a
  polymorphic actor pair) and the `R2 sec 19.3` 26-test family is resolved with it.
- `T3-10`: unblocks with `T1-3`/decision 7; then the deploy target is not a feature branch and no token persists in a
  remote URL.
- `T3-17`: unblocks when the owner un-defers; then liveness is cheap and readiness is separate, authenticated and
  throttled.

## 5. Recorded remainders on closed rows (no code task, kept visible)

- **RV-07**: owner rotation + history purge (see `T1-3`). Agent side is `VERIFIED FIX` in `0c0ea3c`.
- **RV-22 slice 3**: `LOG_LEVEL`/stderr/rotation are deploy configuration; the owner's `.env` already sets
  `LOG_CHANNEL=stderr` and `LOG_LEVEL=error`, so the `config/logging.php` `debug` default applies only where
  `LOG_LEVEL` is unset (`R2 sec 30` item 2). No decision-free app-code change exists.
- **RV-27**: production FCM keys and real delivery verification (`R2 sec 28.5`) - deploy surface.
- **RV-28**: MySQL least-privilege user in compose, log rotation, `TRUSTED_PROXIES` value, replica lag - deploy surface;
  `V7` still has never been run, so no replica-lag claim is supported.
- **RV-30**: timezone policy (`APP_TIMEZONE=Asia/Damascus` vs store-UTC), `user_ratings` uniqueness vs one-rating-per-ride
  (`V9`), duplicated `profiles.*_pic` / legacy columns, `schema:dump` workflow (`R2 sec 32`).
- **RV-31**: stub notification classes and their tests are live-or-owner-gated - decision 12 (`R2 sec 33`).
- **RV-36 / RV-35 / RV-34 / RV-18**: nothing owed; `RV-18` deliberately does not own whole-suite green (`sec 24.1`), which
  is `RV-35` inventory + product decisions.
- **`S` T-series**: the 6 open `T-` rows above are the whole remaining bug-audit state - `33 of 39 closed`
  (`S sec Overall audit completion status`, line 63, and both progress tables).

## 6. Conflicts between the documents, and how each was settled

| # | Conflict | Settlement |
|---|---|---|
| 1 | `RV-02` is "NOT STARTED" in `R2 sec 12`/`sec 18.4` but `VERIFIED FIX` in `sec 16` | Git settled it: `ba45e4b` exists and is the L1 fix. Newest section wins -> `PARTIAL` (L1 closed, L2 0% and Wave 3 PAUSED per `sec 26.14`). |
| 2 | `RV-18` "PENDING" in `sec 18.4` vs `VERIFIED FIX` in `sec 24` | `51b6ca7` exists -> `sec 24` wins. |
| 3 | `RV-22`, `RV-38` "PENDING" in `sec 12` vs `sec 26.15`/`sec 26.16`, `sec 29.2`, `sec 30`, `sec 36`, `sec 37` | Newest-section rule: `RV-22` = VERIFIED FIX (2/3, slice 3 deploy-surface), `RV-38` = PARTIAL with one verified rollback. |
| 4 | `RV-25`/`RV-17`: k6 "404, `rideId='search'`" premise (`R2 sec 1.2(1)`) | `V11` (`c1bb4c7`) recorded GET **and** POST both registered, so the k6 GET produced 422. Recorded as a refuted premise; `RV-17`'s contract fix (`56c989a`) is unaffected. |
| 5 | `RV-38`: "9 `User::profile` N+1s" and "3 sites cover it" | `sec 35` re-test: NOT reproducible -> `VERIFIED ROLLBACK (premise)`; the real blocker was arming fragility, solved by `GuardsLazyLoading` (`sec 36`, `c92e1e2`); `sec 37` measured flag ON = 1 error / 18 failures, so the flag stays OFF. `sec 37`'s own "the 3 sites do not address these" is kept as an unverified inference. |
| 6 | `AF-5`: "`FILESYSTEM_DISK=s3` closes the scaling break" (`ROADMAP sec F.1`, `A sec I`) | `R1 sec 2` A3.1 + `R2 sec 1.1`: the fix as written is wrong (disk hard-coded `'public'` at >=8 sites). `AF-5` is re-scoped by `RV-01` and stays OPEN. |
| 7 | `AF-4a`: "old fixture was 397 km off, so it was transposed" | `R2 sec 1.2(2)`: 397 km is the distance between a point and its own transpose, axis-order-agnostic, so it proves nothing. `V1` (258 km vs 309 km) is the decisive evidence, and `RV-25` (`52108b7`) landed the lat-first fix. |
| 8 | `RV-26`: "staff/admin authorization matrix is broken" | Refuted by its own fix text (`T2-2` changed every `/api/admin/*` gate) -> `GATED`, no code change. |
| 9 | `RV-36`: "notification endpoints are no-ops because `auth()->id()` is null" | `V12` refuted the premise; the real defects (existence oracle + silent no-op) were found and fixed (`2d6a55e`) -> `VERIFIED FIX`. |
| 10 | `wallet_requests.wallet_id NOT NULL` vs 26 tests | Verified on disk: the column is `NOT NULL` and the tests do not supply it. `R2 sec 19.3` calls it a schema design question -> **OWNER DECISION** (rolled into `RV-21` / `T3-4`). |
| 11 | `S` says "33 of 39 closed, audit NOT finished" vs `R2 sec 18.2` counting 40 `RV-` tasks | Not a conflict: different audits. `S` covers `T-` only; `RV-` is the addendum. Both are represented, and the `S` totals match its own progress tables. |
| 12 | `RV-39`: `STATE.md` says OPEN while `R2 sec 26.11`/`sec 30` suggest the escrow-seeder half may be covered | Settled from code, re-verified 2026-10-03: `sec 26.11` fixed `SystemWalletSeeder` only. No `RefusesProduction` trait exists, `Syrideseeder.php` still declares `class SyrideSeeder`, `truncateTables()` omits `noshow_reports`/`refresh_tokens`/`otps`, the third ledger vocabulary is still at `Syrideseeder.php:694/785/794/816`, and `DriverSeeder:101` / `PassengerSeeder:94` share `COMM_NUMBER` -> `OPEN`. |
| 13 | `RV-08`: `R1 sec 7` puts it in Wave 2, `R2 sec 6` drops it, `R2 sec 18.3` "placement: unconfirmed" | **OWNER DECISION** on placement; kept at the Wave-2 tail (Order 34) with `Blocked by 7`, so it is neither silently dropped nor silently scheduled. |
| 14 | `RV-29`'s newest headline (`sec 34` "VERIFIED FIX") vs its still-open items (`sec 28.6`, `sec 28.9`, `sec 30` items 3-4) | `sec 34` only closes the `T4-5` mass-assignment item. Task-level state from `sec 28.9`/`sec 30` -> `PARTIAL` (items 1 and 3 closed, cache-DTO and `communication_number` gated). |
| 15 | The `S` progress tables carry a "**Next**" row (`S` lines 57 and 632) while the brief says replace every Next line | Replaced in place, keeping the table-row shape, so no stale "next" survives anywhere. Line 57 is above the `# Part 1` heading (line 86), so Part 1 stays verbatim. |
| 16 | **Owner-decision numbering collision.** `R2 sec 18.3` says "Decisions 1-10 originate in `R1 sec 6`", but `R1 sec 6` #1 is "sycash role: approves wallet requests? (RV-26)" while `R2 sec 18.3` #1 is "KYC: approve a staff-authenticated document streaming route (RV-01 storage half)". Numbers 2-10 agree; #1 does not, so a bare "decision 1" is ambiguous between the two files. | Not resolved (it is the owner's numbering, not a code fact). Disambiguated: `1a` = the `R1` sycash-role question (gates RV-26), `1b` = the `R2` streaming-route question (gates RV-01 storage). Section 1a is the index. Decision 13 (statuses as string+enum) is on RV-40 as *not blocking*: the schema shipped the DB-ENUM form and `R2 sec 30` never lists 13 as a gate, so RV-40 is VERIFIED FIX with the question still open, not a blocked row. |
| 17 | `RV-12` blocked-by is never numbered. | `R2 sec 27` names the remainder ("dropping the persisted logged-out status=0, migrating all readers to a `BanService`... R1's larger model change, entangled with owner decisions") without a number; `R2 sec 30` item 6 lumps it in "RV-12/RV-19/RV-23/RV-26/RV-27 gated remainders". Recorded as `un13` rather than borrowed onto `1a`. |
| 18 | `AF-2` vs `AF-2'`: the CI-gates task is `AF-2` in `A` line 518 ("Next: AF-2") and `AF-2'` in the delivered section `A` line 520; the `A sec J` snapshot lists only `AF-2'` (prime = U+2032). | Both spellings get a row so a search for either finds the work. `AF-2` = SUPERSEDED (renamed+extended into `AF-2'`, `92f454e`), not two pieces of work. Section 7 covers the ASCII-only pattern consequence. |
| 19 | `RV-31`/`RV-30`/`RV-28`/`RV-22`/`RV-27` are titled `VERIFIED FIX` yet carry items the docs call owner-gated or deploy-surface. | Kept the source label for exactly the scope its own heading bounds ("decision-free core", "app-code/config core", "2/3"), and wrote every gated/deploy item out in section 5, so the label cannot hide work. None of these five rows says `Blocked by = none`, so the STATE.md next-task rule cannot mistake a closed row for open work; section 3 proves every `VERIFIED FIX` row cites a commit that exists. |
| 20 | `T4-4` is VERIFIED FIX in `S P2` while `RV-04` still owes the TTL and `R1 sec 6` #9 says "600 min -> 15-60". | Both true, no conflict: `T4-4` made the TTL *configurable* (`config/jwt.php` reads `ttl`/`staff_ttl`/`refresh_ttl` - re-checked on disk), which is the bug described; the *value* is decision 9, carried by RV-04. T4-4 closed, RV-04 PARTIAL blocked=9. |
| 21 | Several `T-` rows use the audit's word NOT STARTED, which is not a terminal state under `AGENTS.md`. | Mapped to the section-1 vocabulary instead of copied verbatim: T3-4/T3-10 = OPEN (cannot start without an owner call, gate named in Blocked by); T3-3/T3-17 = DEFERRED; T4-5/T1-3 = BLOCKED. Each row's Evidence cell quotes the source wording so nothing is lost. |

## 7. Coverage (reproducible)

```powershell
$docs = 'docs/audit/SYRIDE_COMPREHENSIVE_AUDIT.md','docs/audit/APP_FUTURE_AUDIT.md',
        'docs/audit/APP_FUTURE_SONNET.md','docs/audit/App future audit review r2.md'
$pat  = '\b(T[1-4]-\d+|AF-\d+[a-z]?|RV-\d+|V(?:1[0-6]|[1-9]))\b'
$ids  = $docs | ForEach-Object {
          [regex]::Matches([System.IO.File]::ReadAllText($_,[System.Text.Encoding]::UTF8), $pat) } |
        ForEach-Object { $_.Value } | Sort-Object -Unique
$bl   = [System.IO.File]::ReadAllText('docs/audit/BACKLOG.md',[System.Text.Encoding]::UTF8)
$miss = $ids | Where-Object { $bl -notmatch ('(?<=[|\s])' + [regex]::Escape($_) + '(?=[\s|])') }
"unique IDs in the four audit files: $($ids.Count)"
"missing from the BACKLOG table: $($miss.Count) -> $($miss -join ', ')"
```

Output on this checkout: `unique IDs in the four audit files: 104`, `missing from the BACKLOG
table: 0 -> ` (empty). The same command over the untouched copies (`git show HEAD:docs/audit/...`)
also returns **104**, so the consolidation lost no ID: the sets are identical.

Two encoding traps this command exists to avoid, both hit during preparation:

- `Get-Content -Raw` under Windows PowerShell 5.1 decodes these files as ANSI, not UTF-8. The prime
  in `AF-2'` (U+2032) becomes three bytes, the following character stops being a word boundary, and
  the pattern silently reports **103** - a false "missing ID". Read with
  `[System.IO.File]::ReadAllText($_, [System.Text.Encoding]::UTF8)`, as above.
- `AF-\d+[a-z]?` does not capture the prime, so `AF-2'` is matched as the token `AF-2`. That is why
  section 2 gives **both** `AF-2` and `AF-2'` a row: `AF-2` is what the pattern (and `A` line 518,
  which wrote it bare) produces, `AF-2'` is what the delivered section is named. They are one piece
  of work - see conflict 18 - and neither row double-counts it.

Notes on the pattern: `T[1-4]-\d+` requires the `-`, because bare `T1`-`T4` also occur as *tier
container* labels (`S sec TIER 1`, and `php artisan test --filter=T1`), not as findings. `AF-1's`
and `AF-5's` in the sources are possessives of existing IDs, not extra IDs. `V1`-`V16` are matched
only in the `Vnn` form; prose "V1 or..." style hits are the same IDs.

Section 2 holds **105 rows** for those 104 IDs: one per ID, plus the extra `AF-2'` row explained
above, and `RV-02` is a single row carrying both its L1 (closed) and L2 (open) halves rather than
two rows.
