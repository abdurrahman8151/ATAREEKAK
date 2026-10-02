# STATE - the only file that says "what is next"

Put this file at `docs/audit/STATE.md`. Keep it under about 80 lines, ASCII only, no secrets.
If any other document disagrees with it, this file wins.

Reconciled against `git log` and `git status` in a documentation-only session (no code edits,
no tests, flag left OFF). The audit record is `docs/audit/App future audit review r2.md`.

## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
- CLEARED THIS SESSION. No repository edits were made; this block was reconciled against the
  working tree and git. RV-38 part 3 is NOT being worked on: it is parked, flag OFF, and needs
  an explicit decision before anything is armed.
    - VERIFIED on disk: the `// PROBE` line and the `AppServiceProvider.php.b` backup are GONE
      (`git grep PROBE -- app/` is empty; no `*.b` file exists in the tree).
    - VERIFIED on disk: `app/Http/Controllers/API/RideController.php` still carries its 3
      uncommitted eager-load edits (create, show, driverView; `git diff -stat` = 9 insertions).
      These are owner-owned uncommitted work - do not discard them.
    - VERIFIED on disk: the flag is OFF. `AppServiceProvider::boot()` arms only
      `preventSilentlyDiscardingAttributes()` and `preventAccessingMissingAttributes()`;
      `preventLazyLoading()` appears only inside a comment.
    - Superseded: the old goal "lazy-load violations 13 -> 0" is replaced by the measured
      numbers in R2 section 37. Option A as written does NOT reach 0.

## Done recently (newest first; detail is in the audit record)
- See "App future audit review r2.md" sections 26-37 (latest status lives there, NOT in its
  index tables at section 12 and 18.4, which are stale). Newest: RV-38 flag-off/flag-on scoped
  measurement (37), RV-38 `GuardsLazyLoading` arming mechanism (36), RV-38 lazy flag re-test
  (35), RV-29 / T4-5 ratchet (34), RV-31 (33), RV-30 (32), RV-28 (31).
- RV-32 README / docs corrections - DONE in git `e878f25` (README.md, +23/-10). Removed from Next.
- RV-33 Ratchet additions to `BoundaryDependencyTest` - DONE in git `97792b6`
  (`tests/Feature/Review/RV33BoundaryDependencyTest.php`, +178). Removed from Next.

## Next unblocked tasks (in order; verify each is still open before starting)
1. RV-38 part 3 - arm the lazy-loading flag. Measure before arming: R2 section 37 shows option A
   as written does NOT reach 0 (flag on: 1 error / 28 failures, 18 new; residual `[driver]` on
   Ride x7 at EPayPaymentStrategy:53, `[wallet]` on User x3 at CashRideFeeService:79,
   `[profile]` on User x1 at RideSearchServiceTest:330). This needs an owner decision, not more
   code. Do NOT enable the flag as-is.
2. RV-39 Seeders [P2] - STAYS OPEN. PENDING per R2 section 12. Check R2 sections 26.11 and later
   first; the escrow-seeder half of RV-21 may already cover part of it.
   Per R2 section 30, "no further decision-free work remains" within the old section 18.4 scope,
   so items in this list must be re-confirmed as genuinely open. If none are open: stop and report.

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