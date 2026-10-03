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


| Order | ID | Title | Pri | Status | Blocked by (owner decision #) | Aliases | Evidence (file section / commit) |
|---|---|---|---|---|---|---|---|
| 1 | V1 | Axis order: Damascus-Aleppo distance in MySQL | P0 | RECORDED | n/a | feeds AF-4a, RV-24, RV-25 | R1 sec 3, sec 9; R2 sec 9; 97cd3f1 - FAIL: 258 km not 309 km, transposed confirmed |
| 2 | V2 | Spatial index on `rides` | P0 | RECORDED | n/a | feeds RV-25 | R1 sec 3, sec 9; R2 sec 1.1; 97cd3f1 - none exist (2025_05_20 migration dropped them) |
| 3 | V3 | Route-buffer units (`ST_Buffer`) | P0 | RECORDED | n/a | feeds RV-25 | R1 sec 3, sec 9; 97cd3f1 - error 3618 on LINESTRING; strategy B 500s |
| 4 | V4 | Client IP behind nginx | P0 | RECORDED | n/a | feeds RV-05 | R1 sec 3, sec 9; 97cd3f1 - collapse CONFIRMED, spoof REFUTED |
| 5 | V5 | Validation `errors` bag stripped | P1 | RECORDED | n/a | feeds RV-13 | R1 sec 3, sec 9; R2 sec 1.1; 97cd3f1 - CONFIRMED |
| 6 | V6 | CI driver + skipped tests | P1 | RECORDED | n/a | feeds AF-2', RV-18 | R1 sec 3, sec 9; 97cd3f1 - CI pinned to sqlite |
| 7 | V7 | `SHOW REPLICA STATUS` under load | P2 | RECORDED | owner: no replica in this environment | feeds RV-28 | R1 sec 3, sec 9; R2 sec 31 - NOT RUN, the only unrecorded V |
| 8 | V8 | `config/system_admin.php` exists? | P2 | RECORDED | n/a | feeds RV-19 | R1 sec 3, sec 9; 97cd3f1 - file missing, so revenue reads 0 |
| 9 | V9 | `user_ratings` unique key | P3 | RECORDED | n/a | feeds RV-30 | R1 sec 3, sec 9; 97cd3f1 - `unique(rater_id, rated_user_id)` still present |
| 10 | V10 | `POST /api/rides` method mismatch | P1 | RECORDED | n/a | feeds RV-08, RV-14 | R1 sec 3, sec 9; 97cd3f1 - 500 `BadMethodCallException`, `createRide` missing |
| 11 | V11 | GET vs POST on `rides/search` | P1 | RECORDED | n/a | feeds RV-17, refines R2 sec 1.2(1) | R2 sec 4, sec 9; c1bb4c7 - GET **and** POST exist, so k6 got 422 not 404 |
| 12 | V12 | `auth()->id()` null under JWT | P1 | RECORDED | n/a | feeds RV-36 (refutes its headline) | R2 sec 4, sec 9; c1bb4c7 - premise REFUTED |
| 13 | V13 | Suite with network blocked | P2 | RECORDED | n/a | feeds RV-07, RV-37 | R2 sec 4, sec 9; c1bb4c7 - no live-provider calls; 7 `putenv()` leaks |
| 14 | V14 | `--order-by=random` x3 | P2 | RECORDED | n/a | feeds RV-37 | R2 sec 4, sec 9; c1bb4c7 - order-dependent (55F vs 53F) |
| 15 | V15 | Group suite errors by message | P1 | RECORDED | n/a | feeds RV-34 | R2 sec 4, sec 9; c1bb4c7 - 320/427 in the two predicted groups |
| 16 | V16 | All-seats cancel vs `cancelBooking` | P1 | RECORDED | n/a | feeds RV-02 L1 (refuted), RV-09, decision 14 | R2 sec 4, sec 9; c1bb4c7 - ledger+score equivalent; whole-booking route absent |
| 17 | RV-07 | Committed secrets and stale credentials | P0 | VERIFIED FIX | n/a | RV-37 (V13 leaks), T1-3/T2-8 (same class) | R2 sec 10, sec 12; 0c0ea3c - agent-side only; rotation+history purge recorded sec 5 |
| 18 | RV-06 | Horizon dashboard unauthenticated; nginx upstream leak | P0 | VERIFIED FIX | n/a | - | R2 sec 11, sec 12; 896c331 |
| 19 | RV-01 | KYC documents exposed (IDOR + public storage + unsafe names) | P0 | PARTIAL | 1b, 11 | supersedes AF-5's scope; feeds RV-31 | R2 sec 13, sec 1.1; 95ad30d - authz+names fixed, storage half open |
| 20 | RV-04 | JWT integrity (staff->user replay, empty secret, TTL) | P0 | PARTIAL | 9 | AF-4b (Sanctum already gone) | R2 sec 14, sec 12; d5043b5 - replay+boot guard fixed |
| 21 | RV-05 | Client IP wrong behind nginx | P0 | VERIFIED FIX | n/a | V4 | R2 sec 15, sec 12; 09e86c5 - both halves; `TRUSTED_PROXIES` value is ops (sec 31) |
| 22 | RV-02 | No-show settlement can pay twice / mispay (L1 + L2) | P0 | PARTIAL | 2, 3 (L2) | L1 refuted by V16; L2 = AF-6/Wave 3; consumes `void` enum (RV-40) | R2 sec 16 (L1 VF), sec 26.14 item 3 (L2 0%, Wave 3 PAUSED); ba45e4b |
| 23 | RV-03 | Staff cancel endpoints strand money, no role gate | P0 | BLOCKED | 6 | RV-02, RV-09 | R1 sec 4 RV-03; R2 sec 12 `BLOCKED - the only Wave-1 blocker` |
| 24 | RV-34 | Shared test-support layer (both red root causes) | P1 | VERIFIED FIX | n/a | V15; T4-1 harness | R2 sec 17, sec 17.4; 5414344 - 443 -> 71 errors |
| 25 | RV-37 | Test determinism and hermeticity | P2 | VERIFIED FIX | n/a | V13, V14; RV-18 (CI order) | R2 sec 23, sec 23.6 correction, sec 39 VF (order) + 39.1 VF (hermeticity) + 39.2 (tracked-file ratchet + CI double-run) + 39.3 (CI executed; pre-existing CI install breaks fixed); cd6a49a + c45e05e + a087342 + eaa7f14 + the closure commit. Closed by owner instruction 2026-10-03 |
| 26 | RV-18 | CI signal (V6 driver leak, migration guards) | P1 | VERIFIED FIX | n/a | V6, AF-2' | R2 sec 24, sec 24.2 VF; 51b6ca7 - whole-suite red is separate debt (sec 24.1) |
| 27 | RV-13 | Error model (envelope, domain exceptions, leakage) | P1 | PARTIAL | un4 (halves 1-2 are unblocked) | V5; RV-33 ratchet | R2 sec 20, sec 20.1, sec 20.3 PARTIAL; 980741c |
| 28 | RV-14 | Route/controller mismatches, lying endpoints | P1 | PARTIAL | un5 (the price-width half is closed) | V10, V8; RV-40 did the price width | R2 sec 21, sec 21.1, sec 21.2 PARTIAL; 1c18c07 |
| 29 | RV-16 | OTP and mail flows | P1 | PARTIAL | 4, 8 | RV-31 (mailers), T2-3/T2-4 | R2 sec 22, sec 22.4, sec 22.5, sec 29 (plaintext -> HMAC); cac4084 + d776c1f |
| 30 | RV-22 | TLS and log hygiene leftovers | P1 | VERIFIED FIX | n/a | AF-1 (2/3 landed); V13 | R2 sec 26.15, sec 26.16 VF; 4239087 + b34df62 - slice 3 is deploy-surface (sec 30 item 2) |
| 31 | RV-36 | Notification endpoints no-ops under JWT | P1 | VERIFIED FIX | n/a | V12 refuted the premise | R2 sec 25 VF; 2d6a55e - real defect was existence-oracle + silent no-op |
| 32 | RV-38 | Eloquent strictness outside production | P2 | PARTIAL | un9 | RV-24 (arming), T4-1 | R2 sec 29.2 VF (2 flags), sec 29.5 ROLLBACK, sec 35 ROLLBACK (premise), sec 36 VF (arming), sec 37 OFF; 61896df + 7ec0047 + c92e1e2 |
| 33 | RV-35 | Stale and bug-pinning tests (inventory) | P2 | VERIFIED FIX | n/a | decision 12, sec 19.3 schema question | R2 sec 19, sec 19.5 VF; d381a7a - 71 -> 52 errors; inventory is the deliverable |
| 34 | RV-08 | Deploy pipeline is broken | P1 | OPEN | 7 + placement (conflict 13) | V10 | R1 sec 4 RV-08, sec 7 Wave 2; R2 sec 6 omits it, sec 18.3 "placement: unconfirmed" |
| 35 | RV-40 | Money schema additions (prereq for RV-02 L2, RV-09, RV-15) | P1 | VERIFIED FIX | n/a | decision 13; AF-6 | R2 sec 26.1 VF, sec 26.12 VF (price width), sec 26.13 VF (one report/booking); caebbfa + f4d1df8 + 53fe8d1 |
| 36 | RV-09 | Money concurrency and side-effect ordering | P1 | PARTIAL | 3 (Wave 3 PAUSED) | V16; RV-02 L2; T1-1 lock precedent | R2 sec 26.3 "VERIFIED FIX (safe subset); throughput redesign separate", sec 26.14; caebbfa |
| 37 | RV-10 | Ride lifecycle and escrow liveness | P1 | PARTIAL | 2 | AF-4e (config windows); T1-1 | R2 sec 26.7 (search guard VF, rest owner-gated), sec 26.14; caebbfa |
| 38 | RV-11 | Score subsystem internally inconsistent | P1 | PARTIAL | 3, un2 | T1-1 state machine | R2 sec 26.9 PARTIAL ~35% (dead `applyScore` deleted), sec 26.14; caebbfa |
| 39 | RV-15 | Booking idempotency | P1 | VERIFIED FIX | n/a | RV-02 L2 `posting_key` | R2 sec 26.2 VF; caebbfa |
| 40 | RV-21 | Wallet identity and money creation | P1 | PARTIAL | un3, un10 | RV-39 (seeder half), AF-6 | R2 sec 26.5 PARTIAL, sec 26.11 VF (seeder half), sec 26.14; caebbfa + 70d1f36 |
| 41 | RV-20 | Payment strategy one-third wired | P2 | PARTIAL | 2, 3 (coupled to RV-02 L2) | AF-6; T2-1 | R2 sec 26.4 "grounded but NOT changed"; sec 26.14 item 4 (~20% done, credited to the RV-09/RV-15 work in caebbfa) - nothing committed under this ID |
| 42 | RV-25 | Search correctness and cost (geometry axis order) | P1 | VERIFIED FIX | n/a | **AF-4a**; V1, V2, V3 | R2 sec 29.6, sec 29.7 VF, sec 29.8 VF (GeoPoint), sec 29.9 VF; 52108b7 + 69c032f + 1af0440 |
| 43 | RV-24 | Ride payload and N+1 (coordinate reads) | P1 | VERIFIED FIX | n/a | RV-38 arming; V2 | R2 sec 29.4 VF; b84cea0 |
| 44 | RV-17 | Load-test validity | P1 | PARTIAL | un12 | V11; RV-18 (CI) | R2 sec 1.2(1) correction, sec 29.3 "VERIFIED FIX (contract half)"; 56c989a |
| 45 | RV-12 | Account status model (temporary ban lock-out) | P1 | PARTIAL | un13 | RV-31, RV-29 | R2 sec 27 "VERIFIED FIX (decision-free core); R1's model refactor stays PARTIAL"; 1173a69 |
| 46 | RV-26 | Staff/admin authorization matrix | P2 | GATED | 1a | **headline refuted by T2-2's own fix text** | R2 sec 28.1 GATED; no commit |
| 47 | RV-27 | Push pipeline cannot deliver | P1 | VERIFIED FIX | n/a | T3-14, T2-8 | R2 sec 28.2, sec 28.5 VF (decision-free core); 364c3db - FCM keys = ops, sec 5 |
| 48 | RV-29 | Auth hardening batch | P2 | PARTIAL | 5, un11 | **T4-5** item 4, AF-4b | R2 sec 28.6 slice1 VF, sec 28.9 slice2 proven/gated, sec 29.10 item3 VF, sec 34 VF (ratchet); bc6acaa + 4185af3 + 1d68c07 |
| 49 | RV-19 | Fake or derived numbers in admin | P2 | PARTIAL | 10, un7 | V8 | R2 sec 28.4, sec 28.7 VF (slice 1), sec 30 item 6; bc49fc6 |
| 50 | RV-23 | Complaints (context, routing, notifications) | P2 | PARTIAL | un6 | RV-31 | R2 sec 28.3 slice1 VF, sec 28.8 slice2 VF; c59fed6 + bfc6fd1 |
| 51 | RV-28 | Infrastructure hardening | P2 | VERIFIED FIX | n/a | V7 (never run); RV-05 | R2 sec 31 VF (app/config core); 8ece5af - infra halves are deploy-surface, sec 5 |
| 52 | RV-30 | Data model hygiene | P3 | VERIFIED FIX | n/a | V9 | R2 sec 32 VF (index + `down()` correctness); c785f73 - recorded items sec 5 |
| 53 | RV-31 | Dead code wave (grep-confirm each before deleting) | P3 | VERIFIED FIX | n/a | RV-16 (mailers), T4-7, AF-4c | R2 sec 33 VF + recorded classification; 09c5c58 |
| 54 | RV-32 | README / docs corrections | P3 | VERIFIED FIX | n/a | T4-6 | R2 sec 30; e878f25 (+23/-10) |
| 55 | RV-33 | Ratchet additions (`BoundaryDependencyTest`) | P2 | VERIFIED FIX | n/a | AF-2', AF-7 (Larastan baseline) | R2 sec 30; 97792b6 (`RV33BoundaryDependencyTest`, 6 ceilings) |
| 56 | RV-39 | Seeders (prod guard, filename/class, truncate, vocabulary) | P2 | VERIFIED FIX | n/a | AF-4f (trait), RV-21 (escrow seeder), T3-9 | R2 sec 38 VF; 094479a |
| 57 | AF-1 | TLS honesty + Octane upload hygiene | P0 | VERIFIED FIX | n/a | **-> RV-22** (absorbed, 2/3 landed) | A sec J AF-1 VF; b9643f1 - R1 sec 2 A3.3 re-scoped it into RV-22 |
| 58 | AF-2 | CI gates: Pint + Larastan + tests-required | P1 | SUPERSEDED | n/a | renamed/extended as **AF-2'** | A sec D7, line 518 (`Next: AF-2`), sec F.5 |
| 59 | AF-2' | Modularity foundation: context map + ratchet + CI gates | P1 | VERIFIED FIX | n/a | **-> RV-18/V6**, RV-33, AF-7 | A sec J AF-2' VF; 92f454e - `ARCHITECTURE_MAP.md` + `BoundaryDependencyTest` |
| 60 | AF-3 | Hide/delete `Test*` debug commands | P2 | SUPERSEDED | n/a | **= AF-4f** (prod guard supersedes "hide") | A sec J snapshot line 691; R2 sec 3 RV-39 (same guard owed to seeders) |
| 61 | AF-4 | Un-tangle the dead-but-wired set | P1 | VERIFIED FIX | n/a | parent of AF-4a..AF-4f | A sec J AF-4 VF (both owner decisions applied); 2687872 |
| 62 | AF-4a | Search swap + the three bugs it exposed | P1 | VERIFIED FIX | n/a | **<-> RV-25**; V1/V2/V3 | A sec J 4a; 2687872 - its "397 km" proof was axis-order-agnostic (R2 sec 1.2(2)); V1 decided |
| 63 | AF-4f | Production-guard trait on state-forging commands | P2 | VERIFIED FIX | n/a | **= AF-3**; owed onward to RV-39 | A sec J 4f (5 commands); 2687872 |
| 64 | AF-5 | Shared object storage (`FILESYSTEM_DISK=s3`) | P0 | OPEN | un1 | **re-scoped by RV-01**; ROADMAP sec F.1, A sec I truth 1 | A sec J snapshot line 693; R1 sec 2 A3.1 + R2 sec 1.1 - the fix as written is WRONG (disk is hard-coded `'public'`) |
| 65 | AF-6 | Money module (`Money` VO, one `LedgerEvent`, `ledger:reconcile`) | P1 | OPEN | 2, 3, 6 (= Wave 3 pause) | **= Wave 3**: RV-02 L2, RV-09, RV-10, RV-11, RV-15, RV-20, RV-21 | A sec J snapshot line 694 "not started - next", sec F.7-8; R1 sec 7 Wave 3; R2 sec 6 |
| 66 | AF-7 | Controller extraction / Larastan | P1 | OPEN | AF-6, un8 | RV-33 (baseline ratchet), RV-24 (reads) | A sec J snapshot line 695; `ARCHITECTURE_MAP.md` sec 4 (Larastan deferred); R2 sec 1.3 RV-33 (do it in CI) |
| 67 | T1-1 | Ride-completion state machine self-contradictory; E-PAY escrow never released | P0 | VERIFIED FIX | n/a | RV-02, RV-10, RV-11 | S P1 T1-1; S P2 sec T1-1 VF; 3443979 - re-checked: `BookingService.php:510-516` uses `RideStatus::tryFrom` |
| 68 | T1-2 | Ledger writes use a `type` value absent from the DB enum (fatal in strict mode) | P0 | VERIFIED FIX | n/a | RV-40, AF-6 (LedgerEvent) | S P2 sec T1-2 VF; 3443979 |
| 69 | T1-3 | Secrets committed in git history, still recoverable | P0 | BLOCKED | owner action (rotation + `filter-repo`) | RV-07, T2-8 | S P1 T1-3; S P2 sec T1-3 BLOCKED; `gitleaks.yml` + boot guards only |
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
| 85 | T3-4 | `wallet_requests.processed_by` references users; admin actors are employees | P2 | OPEN | un10 + the settled T2-1 decision | T2-1, sec 19.3 (26 tests) | S P2 table `NOT STARTED`; no commit |
| 86 | T3-5 | Conflicting/duplicated indexes; `down()` drops unconditionally | P2 | VERIFIED FIX | n/a | RV-30 | S P2 table VF; 3443979 |
| 87 | T3-6 | MySQL-only raw SQL contradicts the SQLite test config | P2 | VERIFIED FIX | n/a | RV-18, V6 | S P2 table VF (7 migrations guarded); 3443979 |
| 88 | T3-7 | `VerifyOtpMiddleware` a no-op; a test asserts it works | P2 | VERIFIED FIX | n/a | RV-16 | S P2 sec T3-7 VF (stub + misleading test deleted); 3443979 - file absent (re-checked) |
| 89 | T3-8 | `AdminUserSeeder` reads deleted config keys; makes a null-email user | P2 | VERIFIED FIX | n/a | V8, RV-19, RV-26 | S P2 sec T3-8 VF (seeder deleted); 3443979 |
| 90 | T3-9 | Hardcoded credentials in seeders and load-test scripts | P2 | VERIFIED FIX | n/a | RV-39 (`ResolvesSeedCredentials` precedent), RV-07 | S P2 sec T3-9 VF; 3443979 - trait present on disk (re-checked) |
| 91 | T3-10 | Deploy resets the server to a feature branch; token persisted in remote URL | P2 | OPEN | T1-3, 7 | T1-3, RV-08, decision 7 | S P2 table `NOT STARTED (deploy automation)`; no commit |
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
- The booking rule for past-departure rides is decided once and applied consistently; the ~335 settlement fixtures
  that deliberately book past-departure rides are moved to a supported test path instead of relying on the hole.
- Closed already, do not redo: the decision-free search guard (departed rides no longer returned) is `VERIFIED FIX`.

**RV-11 - score subsystem** *(blocked by 3 and un2)*
- One mutation path (`ScoreLedger::apply()`) writes the score, one clamp, one tier table, one start score, all read
  from `config/score.php` (no config exists today).
- The double-count defect is gone: a completed ride increments `total_rides` exactly once (the `applyAction` +
  `incrementRides()` overlap), so `cancel_rate`, whose thresholds gate penalties, is right.
- Tier boundaries are asserted by tests at the exact edge values, not sampled in the middle.
- Correction: the third path is already deleted (97 lines, zero callers) - the ~35% remaining is consolidation,
  and it needs the owner's bands/clamp/start-score numbers first.

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
- Controllers stop catching `Throwable` and stop returning `getMessage()`: 96 catch blocks and 121 `getMessage()`
  returns drop to the ratchet ceiling, so SQL text and table names stop reaching clients.
- `Handler::report`/`render` stop stripping the validation `errors` bag (the `V5` defect, fixed in `980741c`) -
  regression-pinned.
- Only after those two halves: one envelope `{success,data,error{...}}` and the `assertNotEquals` ratchet over the 13
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

**AF-6 - money module** *(blocked by 2, 3 and 6 = the Wave 3 pause)*
- `Money` value object replaces the raw decimal math across the 7 services / 66 sites, with rounding decided in one
  place (no float, no per-call `round()`).
- One `LedgerEvent` enum is the only vocabulary for `wallet_transactions.type` - the two-dialect problem (`D2`) is
  gone, and the seeder's third dialect (`RV-39`) cannot reappear because the column rejects unknown values.
- `ledger:reconcile` runs as a scheduled job and reports a real mismatch when one is injected, proving
  `SUM(balances)` and `SyCash == SUM(bookings.escrow_held)`.
- Aliases are the acceptance set: it is done when RV-02 L2, RV-09, RV-10, RV-11, RV-15, RV-20, RV-21 (Wave 3) are.

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
