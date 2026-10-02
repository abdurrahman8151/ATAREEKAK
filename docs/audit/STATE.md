# STATE - the only file that says "what is next"

Put this file at `docs/audit/STATE.md`. Keep it under about 80 lines, ASCII only, no secrets.
If any other document disagrees with it, this file wins.

Reconciled against `git log` and `git status` in a documentation-only session (no code edits,
no tests, flag left OFF). Status authority is `docs/audit/BACKLOG.md` - every task, one table,
with each status taken from the newest section that mentions it. The per-task logs stay in
`docs/audit/App future audit review r2.md`. Feature plan: `docs/audit/ROADMAP.md`.

## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
- None.

## Done recently (newest first; detail is in the audit record)
- RV-39 seeders - VERIFIED FIX in git `094479a`, `App future audit review r2.md` section 38
  (production guard on the four data-forging seeders via `RefusesProduction`;
  `Syrideseeder.php` -> `SyrideSeeder.php` + directory-wide filename==class sweep;
  `TRUNCATE_TABLES` FK-closure proven against the live schema; shared `App\Enums\LedgerType` with
  a whole-repo drift ratchet; system wallets resolved from `config/admin.php`; per-wallet unique
  driver/passenger phones). Zero regressions: identical 27 red tests at HEAD and after,
  failure-name diff empty.
- See "App future audit review r2.md" sections 26-38 (latest status lives there, NOT in its
  index tables at section 12 and 18.4, which are stale). Newest: RV-39 seeders (38), RV-38 flag-
  off/flag-on scoped measurement (37), RV-38 `GuardsLazyLoading` arming mechanism (36), RV-38 lazy
  flag re-test (35), RV-29 / T4-5 ratchet (34), RV-31 (33), RV-30 (32).
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