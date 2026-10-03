# STATE - the only file that says "what is next"

Put this file at `docs/audit/STATE.md`. Keep it under about 80 lines, ASCII only, no secrets.
If any other document disagrees with it, this file wins.

Reconciled against `git log` and `git status` in a documentation-only session (no code edits,
no tests, flag left OFF). Status authority is `docs/audit/BACKLOG.md` - every task, one table,
with each status taken from the newest section that mentions it. The per-task logs stay in
`docs/audit/App future audit review r2.md`. Feature plan: `docs/audit/ROADMAP.md`.

## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
- DECISION BATCH (owner 2026-10-02; full slate in `App future audit review r2.md` sec 40).
  DONE + committed: decision record `77e9820`, un10 wallet fixtures `bff1d3e`, un2 score policy
  `de61c7b`, un5 delete finish/driver-confirm `1510663` (+`23457eb` near-miss record),
  un9 lazy loading + flag ARMED `00f7b9d`/`5bbadad`, decision 11 KYC action gate `0c6a1f6`,
  decision 1b staff document streaming `066b1dd` (+`b4885d8` boundary fix), decision 6 staff-cancel
  full refund `364cc0c`, un11 auth cache DTO `8732a0e`, un8 Larastan report-only `67f0113`,
  decision 7 Render deploy `2114308`, un12 k6 setup() harness `ff8e6f0`, un1 MinIO storage `3e9a304`.
  REMAINING: only the migration/money group, each of which AGENTS.md says needs explicit owner
  sign-off before the semantics move - decision 2 (booking expiry), 13 (PHP enums), un13 (account
  status), un3 (money foundation). No routine task is left.
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
