## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
# STATE - the only file that says "what is next"

Put this file at `docs/audit/STATE.md`. Keep it under about 80 lines, ASCII only, no secrets.
If any other document disagrees with it, this file wins.

Reconciled against `git log` and `git status` in a documentation-only session (no code edits,
no tests, flag left OFF). Status authority is `docs/audit/BACKLOG.md` - every task, one table,
with each status taken from the newest section that mentions it. The per-task logs stay in
`docs/audit/App future audit review r2.md`. Feature plan: `docs/audit/ROADMAP.md`.

## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
- (empty - no task is mid-flight.)
- Done recently: **RV-04 audience separation PROVEN (`R2 sec 75`)** - the staff->user guard was real but
  untested; neutralising it returns 200 instead of 401, so the test is proven non-vacuous. Found and
  recorded, NOT closed: `users.token_version` defaults to 1 in the migration vs 0 in `UserFactory`.
- Done recently: **RV-11 is now VERIFIED FIX (`R2 sec 74`)** - `ScoreLedger` built as the single score
  write path; `ScoreService` -172 lines and delegates; 4 creation sites collapsed to 1; 9 tests;
  bisect zero new. Its remaining two items are deliberately NOT done: dropping the vestigial
  `user_scores.tier` column and adding `config/score.php` are each a migration / a design decision.
- Done recently: **RV-16 is now VERIFIED FIX** - decision 8 was ANSWERED but never applied; four
  unauthenticated account-enumeration oracles closed (`R2 sec 72`), incl. the `resend` endpoint which
  needed a second edit because 200-vs-409 enumerated on its own.
- Done recently: RV-19 item 3 - the 95/5 split is now defined once, and the ride-level settlement
  that could THROW on any odd-tenth price is a VERIFIED FIX (`R2 sec 71`). Also: RV-16 signup mail leaves the
  DB transaction (`R2 sec 69`); RV-10 escrow liveness BLOCKED (`R2 sec 70`); RV-13(a) masking question raised
  (`R2 sec 69.1`).
- OWNER DECISION NEEDED (RV-10) - "R2 sec 70": a ride that DEPARTED, is `CONFIRMED`, and that neither the
  driver nor the last passenger confirms holds escrow with no expiry. After how long, and then should the escrow
  go to the driver, back to the passenger, or to a staff queue? Decision 2 ruled "do NOT auto-confirm" for PENDING
  bookings, so this is NOT the same thing and must not be inferred. (A read-only stuck-escrow reporter is
  decision-free if you want the numbers first.)
- OWNER DECISION NEEDED (RV-13a) - "R2 sec 69.1": should a domain rule violation's MESSAGE be visible in
  production? `Handler:88` masks `\InvalidArgumentException` messages; `DomainException::toArray()` does not.
  Migrating the 61+ sites would unmask them. Mask all / mask only non-app-authored / always show.
- Done recently: RV-10 escrow-liveness investigated - **BLOCKED** on the decision above (`R2 sec 70`), row
  corrected from "decision-free".
- Done recently: RV-16 signup mail leaves the DB transaction - VERIFIED FIX (`R2 sec 69`).
- IN PROGRESS RV-16 - mail must leave the signup DB transaction. Expect to change:
  `app/Http/Controllers/API/SignupController.php` (PATH B: commit the user BEFORE sending the OTP),
  NEW `tests/Feature/Review/RV16SignupMailAfterCommitTest.php`. No probe/backup left behind.
  `AdminWalletRequestController`, `ProfileController`, `VerificationController` deliberately NOT touched.
  NOTE: the mail-failure MESSAGE changes (the account now exists, so "Registration failed" would be a lie);
  the JSON keys and the 500 status do not. Flagged as a copy change, not a shape change.
- Done recently: RV-13(b) exception-code-as-status - VERIFIED FIX (`R2 sec 68`). Statuses and response
  shape deliberately UNCHANGED; zero-baseline ratchet added; regression proved by controlled bisect.
- OWNER DECISION SLATE 2026-10-02 - ALL 18 DECISIONS DISPATCHED. Branch `Agentic` only, never pushed.
- OWNER DECISION 2026-10-03 - "a completed ride counts ONCE" (RV-11). Applied, `R2 sec 67`.
- Done recently: RV-11 ride double-count + 3 score-policy defects - VERIFIED FIX (`R2 sec 67`).
  DONE + committed: decision record `77e9820`, un10 wallet fixtures `bff1d3e`, un2 score policy
  `de61c7b`, un5 delete finish/driver-confirm `1510663` (+`23457eb` near-miss record),
  un9 lazy loading + flag ARMED `00f7b9d`/`5bbadad`, decision 11 KYC action gate `0c6a1f6`,
  decision 1b staff document streaming `066b1dd` (+`b4885d8` boundary fix), decision 6 staff-cancel
  full refund `364cc0c`, un11 auth cache DTO `8732a0e`, un8 Larastan report-only `67f0113`,
  decision 7 Render deploy `2114308`, un12 k6 setup() harness `ff8e6f0`, un1 MinIO storage `3e9a304`,
  decision 2 booking expiry `5926230` (verified up AND down), decision 13 DB ENUM -> varchar `e93f3c5`
  (18 columns, verified up AND down, fail-loud rollback), un13 account status + BanService `8b939cf`,
  un3 money foundation `ae09981` **PARTIAL - `wallets.kind` shipped, double-entry + thresholds NOT
  built** (measured scope in R2 sec 56: 31 ledger write sites + 27 balance mutations; half a ledger
  rewrite is worse than none, so it is its own task), un3 double-entry ledger `8b8d5ef` **PARTIAL -
  `ledger_entries` + `LedgerService`; then `5df7da5`/`ecba915`/`4bb4b90` converted
  WalletTransactionService 8/8 methods (95/5 split, both refund fan-outs, both no-show flows, escrow
  release, time-based cancellation) and CashRideFeeService 3/3 - every RIDE money movement is now
  covered, each balance-verified and needle-proven.
  **LEDGER REMAINDER - OPEN DESIGN QUESTION.** `AdminWalletService::chargeWallet`,
  `AdminWalletRequestController` and `PassengerProfileController` are NOT converted, deliberately: an
  admin wallet credit is money entering the system from OUTSIDE, with no internal counterparty to
  debit. Posting a synthetic leg to force it to balance would corrupt what the ledger MEANS. The open
  question is whether this ledger models only internal transfers or also external inflows - that
  decides whether those three get legs at all, and how any future reconciliation ("sum of wallets vs
  sum of ledger") is phrased. Not guessed at.
  OPEN QUESTION for the owner (raised in sec 47, deliberately not actioned): RV-03's ROLE-GATE half -
  both staff-cancel endpoints are callable by ANY authenticated employee, so any of them can move real
  money. Tightening it is an auth change that can lock legitimate staff out, and AGENTS.md requires
  owner approval for permission changes.
  RULE for this batch (learned in sec 43.1): never `git commit -- <path>` a file the owner owns
  uncommitted (RideController.php, phpunit.xml, .gitignore, AGENTS.md). If a task needs one, rebuild
  it from HEAD and restore their edits afterwards.
  RULE 2 (learned in 1b): the boundary ratchet (R6 controllers->models, baseline 21) is NEVER
  raised - if a new controller needs a model, add a repository method instead.
  Owner-owned uncommitted work still NOT touched: RideController.php (2 eager-loads), phpunit.xml,
  .gitignore, AGENTS.md, other audit docs, staged scripts/*.ps1, ROADMAP.md.
  FLAGGED-RECONSIDER (for-now choice stands): 3 (cancellation money UNCHANGED - HIGHEST priority
  review), 9 (600min TTL), 10 (no users.phone), 4 (keep phone-OTP), 14 (keep both cancel routes).
  LATER/BLOCKED: un4 (error envelope - until frontend repo), 5 (driver phone - deferred), un7 (revenue
  - verify later), 12 (moot), un6 (no change).

## Done recently (newest first; detail is in the audit record)
- **The `Blocked by` column was stale, and that is WHY the queue looked empty** - VERIFIED FIX (the column);
  RV-11 defects FOUND (`App future audit review r2.md` sec 66). All 27 decisions were answered on 2026-10-02, but
  the gate column predates them, so **17 of the 21 unfinished rows sat behind a question you had already answered**
  and only 4 were really waiting on you (RV-01, RV-14, RV-17, RV-12). The next-task rule asks for `Blocked by = none`,
  so it selected nothing - the queue was blocked by stale text, not by work. All 19 affected cells now record whether
  the gate is cleared. **No row's Status was changed** - a cleared gate is not a verified task.
- **RV-11: four live score defects, re-verified in code** (sec 66.1). RESOLVED since 26.14 was written: the tier
  bands are single-source and owner-pinned, and the dead `resolveTier` / stored `tier` write are gone. STILL LIVE:
  (1) a completed ride increments `total_rides` **twice** - `ScoreService:277` plus `recordRideCompleted:52` - which
  inflates `cancel_rate`'s denominator so the 50% high-cancel gate stops firing when it should (penalties
  UNDER-apply; nobody is over-charged); (2) `applyAction`'s `firstOrCreate` creates `score = 100` where every other
  path uses the 70 you pinned; (3) `applyAction` clamps at 0 with no ceiling, against the max 100 you pinned;
  (4) the `cancel_rate` assignment at `:280` is dead code. Fixes 2-4 only enforce decisions already taken; 1 re-rates
  every existing user's cancel rate, so all four are listed as open questions for the owner rather than assumed.
  `26.14`'s "the owner has no answers yet" now carries a correction banner.
- RV-13 leakage half CLOSED + `BACKLOG.md` corruption fixed - VERIFIED FIX (`App future audit review r2.md` sec 64.5
  and sec 65). The previous session committed the `RideController` sweep (`5fb1a4b`, ratchet 15 -> 0) but died
  before writing the record, so it was verified rather than trusted: ratchet OK at 0; needle proven in BOTH
  directions (one leak reintroduced -> 2 failures; restore byte-identical by SHA256); and the scoped regression run
  is identical swept vs pre-sweep (211 tests / 321 assertions / 4 failures, same 4 names) - **zero regressions**.
  Measured, not assumed: the sweep moved no HTTP status literal (24 status literals, same multiset both ways).
  Also fixed a REAL CI-breaking defect it had committed - `DomainExceptionMappingTest.php` had no final newline, which
  fails `pint --test`. A second Pint complaint was investigated and found to be a **local artifact, not a CI failure**
  (the committed blob is LF; `git hash-object` equals the HEAD blob). Separately, `BACKLOG.md` - this file's status
  authority - had 3 NUL bytes + 1 backspace committed inside commit hashes, which made it binary to every tool; the 4
  bytes were restored (all 5 hashes resolve, subjects match, and the line numbers above corroborate two of them).
  Removing the NULs also lets `.gitattributes`' `eol=lf` apply again, so that file's next commit is whole-file in the
  diff - that is normalisation, not an edit.
- RV-37 CLOSED as VERIFIED FIX (owner instruction) - `App future audit review r2.md` sec 39 / 39.1 /
  39.2 / 39.3; commits c45e05e (order), a087342 (hermeticity), eaa7f14 (tracked-file ratchet + CI
  double-run), plus this record. CI then ran for the first time on `Agentic` (owner authorised the
  push; `main` untouched): gitleaks green, Pint + Architecture both dead at `Install dependencies`
  for PRE-EXISTING reasons - `pint.yml` has no `.env` so the JWT boot guard throws at package
  discovery, `architecture.yml` sets no broadcast driver so Pusher is constructed with a null key;
  the same two failures exist on c92e1e2 and 26fbdb3. Both fixed (CI config only) and 3 pre-existing
  Pint violations in files this audit never touched auto-fixed; `pint --test` now PASS (537 files).
  Recorded, not acted on: the GitHub PAT is embedded in `origin`'s URL and should be rotated.
  The suite workflow still needs a PR into `main` to execute.

## Done recently (newest first; detail is in the audit record)
- RV-37 hermeticity half - VERIFIED FIX in git `a087342` (`App future audit review r2.md` sec 39.1). `Http::preventStrayRequests()`
  armed in `TestCase::setUp` (zero strays across the full suite; the facade only covers OpenRoute/Geocoding/
  ArabicPlaceName, confirmed by grep). The three Guzzle-direct seams (WhatsAppOtp, TextMeBotOtp, GoogleController)
  closed by nulling their credentials in the test-owned `CreatesApplication` (the local .env carries live values for
  exactly those keys) plus `textmebot.enabled=false`; owner phpunit.xml/.env untouched. Caught one real regression
  mid-way (TextMeOtp disabled-branch 400->200) and fixed it. Both needles (remove guard / remove neutralisation)
  fail the ratchet and restore byte-identical; the first ratchet draft was itself needle-caught matching its own
  docblock. Full suite 2067 tests, 52E/68F - name-identical to the pre-change baseline. Task stays PARTIAL only for
  the CI double-run (RV-18's) and the un-ratcheted tracked-file clause.

## Done recently (newest first; detail is in the audit record)
- RV-37 (order half) - VERIFIED FIX for the order-dependence problem in git `c45e05e` (task stays
  PARTIAL: the hermeticity half is open), `App future audit review r2.md` section 39. Sec 23.6's
  "0 classes without a database trait" was wrong: 58 lack one and 5 of those write, committing 7
  users per suite pass - the leak AdminDriverServiceTest/NotificationTest counted. `RefreshDatabase`
  added to the 5 + a third determinism ratchet (every DB-writing TestCase subclass must be
  transactional, needle-tested both directions). Full suite 4 orders (default + seeds
  20260929/424242/777001): byte-identical 120-test red set, zero committed residue; at HEAD the V14
  seed added 2 leak victims + 7 leaked rows. Not touched: owner's RideController eager-loads,
  phpunit.xml, .gitignore, AGENTS.md, staged scripts/*.ps1, ROADMAP.md, other audit files.
- RV-39 seeders - VERIFIED FIX in git `094479a`, `App future audit review r2.md` section 38
  (production guard on the four data-forging seeders via `RefusesProduction`;
  `Syrideseeder.php` -> `SyrideSeeder.php` + directory-wide filename==class sweep;
  `TRUNCATE_TABLES` FK-closure proven against the live schema; shared `App\Enums\LedgerType` with
  a whole-repo drift ratchet; system wallets resolved from `config/admin.php`; per-wallet unique
  driver/passenger phones). Zero regressions: identical 27 red tests at HEAD and after,
  failure-name diff empty.
- See "App future audit review r2.md" sections 26-39 (latest status lives there, NOT in its
  index tables at section 12 and 18.4, which are stale). Newest: RV-37 order half (39), RV-39
  seeders (38), RV-38 flag-off/flag-on scoped measurement (37), RV-38 `GuardsLazyLoading` arming
  mechanism (36), RV-38 lazy flag re-test (35), RV-29 / T4-5 ratchet (34), RV-31 (33), RV-30 (32).
- RV-32 README / docs corrections - DONE in git `e878f25` (README.md, +23/-10). Removed from Next.
- RV-33 Ratchet additions to `BoundaryDependencyTest` - DONE in git `97792b6`
  (`tests/Feature/Review/RV33BoundaryDependencyTest.php`, +178). Removed from Next.

## Next task - one rule, no list

The next task is **the first row in `docs/audit/BACKLOG.md` (section 2, the table) whose Status
is `OPEN` and whose Blocked by is `none`, at the lowest `Order`**. `Order` is the wave order of
`APP_FUTURE_SONNET.md` section 7 as amended by `App future audit review r2.md` section 6: Wave 0
verify checks, then Waves 1-6, then the `AF-` plan, then the `T-` bug audit.

Re-verify the row is still open before starting it. If the rule yields no row, stop and report -
do not pick a task off a stale wave table or an old progress table. `PARTIAL` rows that carry
decision-free remainders are named in `BACKLOG.md` section 4; this rule does not select them,
because their Status is not `OPEN`. No answer to this rule is cached here on purpose: a written-down
"next task" is exactly what went stale in the four audit files.

## Blocked on owner (do not guess; each needs a decision)
- Wave 3 money/lifecycle is PAUSED (R2 section 26.14): RV-11 tier bands + ride double-count,
  RV-10 auto-confirm hours + driver-cancel window, RV-02 L2 (95/5 split, derived SyCash),
  RV-20, RV-09 remainder, RV-21 remainder. AF-6 (money module) overlaps this, so it is gated too.
- RV-03 (decision 6), RV-01 storage half (staff streaming route), RV-04 token unification
    + TTL (decision 9), RV-08 deploy target (decision 7; placement also unconfirmed).
- R2 section 30 items 1-8: RV-16 remainders, RV-22 slice 3 (deploy-surface), RV-29 cache DTO,
  RV-29 communication_number exposure, User::$fillable (= T4-5, rolled back twice; do not
  retry without an explicit decision), RV-12/19/23/26/27 remainders, RV-17 setup()
  seeding, RV-38 flag prerequisites.
- Bug audit T-series (SYRIDE_COMPREHENSIVE_AUDIT.md Part 2): 33 of 39 closed. T1-3 blocked
  (rotation + history purge), T3-3 and T3-17 deferred, T3-4 and T3-10 not started, T4-5 rolled back.
- AF series (APP_FUTURE_AUDIT.md section J): AF-5 needs an infra decision, AF-7 not started.

## Owner decisions (settled - do not re-litigate)
- The Aiven test database password and the OpenRouteService key are BURNED and testing-only.
  Owner decision: do NOT rotate them and do NOT flag them. No credential-rotation or
  rotation-tracking work is to be scheduled for these two. (Values are never written here.)

## Owner actions outstanding (not code)
- `phpunit.xml` in the working tree points at the Aiven database (R2 section 17.5). Never
  commit it. Replace its values with the local scratch database. Its two credential values are
  burned/testing-only per the settled decision above, so rotation is NOT required.
- A failed connection may have written the Aiven password into `storage/logs/laravel.log`.
  Rotation is declined by the settled decision above; clearing the log is housekeeping only.
- T1-3 and T2-8: git-history purge remains open. The two burned test credentials are excluded
  from rotation by the settled decision above.

## Working tree rules
- Owner-owned, uncommitted by choice: `phpunit.xml`, `AGENTS.md` edits, `.gitignore`,
  and the 3 RV-38 eager-load edits in `app/Http/Controllers/API/RideController.php`.
- Local only: `AGENTS.local.md`. Scratch DB and env block: see `AGENTS.local.md`.
