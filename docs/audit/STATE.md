# STATE - the only file that says "what is next"

Put this file at `docs/audit/STATE.md`. Keep it under about 80 lines, ASCII only, no secrets.
If any other document disagrees with it, this file wins.

Reconciled against `git log` and `git status` in a documentation-only session (no code edits,
no tests, flag left OFF). Status authority is `docs/audit/BACKLOG.md` - every task, one table,
with each status taken from the newest section that mentions it. The per-task logs stay in
`docs/audit/App future audit review r2.md`. Feature plan: `docs/audit/ROADMAP.md`.

## In progress (write-ahead block: written before the first edit, cleared at a terminal state)
- **RV-14 `??`-inside-the-guard quirk - the last code item on that row.** Fix in
  `RideController::createRideWithRoute` + new `tests/Feature/Review/RV14DegenerateRouteFieldsTest.php`.
  **NOTE for the next task touching this file:** it carries the OWNER's uncommitted RV-38 edits, so
  `git add` would commit their work too - build a patch from HEAD and `git apply --cached` instead
  (method recorded in `R2 sec 92`).
- (empty - no other task is mid-flight.)
- **T4-10 VERIFIED FIX (`R2 sec 91`) - the `Blocked by` column was STALE AGAIN.** 8 of the 13 gates
  named by the 5 OPEN rows were ALREADY DISCHARGED, so the rule selected nothing and every session
  ended at "none unblocked" - the same failure `sec 66` found for `PARTIAL` rows, recurring because
  the column was never re-derived after the repairs landed. **AF-7's ENTIRE gate list (RV-33,
  RV-24) had been dead from the day the row was written; it was the one row the rule could have
  selected and a dead cell was hiding it.** Each gate was re-derived from the owning row's own
  `Status` or the cited code line, never from memory. RV-08 <- V10 (`routes/api.php:203` now routes
  to `create`); AF-6 <- RV-02 L2/RV-09/RV-11/RV-15/RV-21; T3-10 <- decision 7 (shipped `2114308`).
  **TRAP recorded: never address a BACKLOG cell by `split(' | ')[7]`** - a Decisions cell ending
  `sec 66` splits identically to a column boundary, so a positional split wrote 482 chars into the
  WRONG column. Use the header. No code, no test - docs only.
- **RV-19: V8 DISCHARGED, item 4 DONE, the rest is ONE owner sentence (`R2 sec 94`).** V8's `config/system_admin.php` never existed but the key moved to `config/admin.php` `system_admin.phone` with a NON-EMPTY default, so "revenue reads 0" cannot happen. Item 4 is done at `UserObserver:33`. **Ask:** should `ledger_earnings` read from `ledger_entries` (RV-21 already reconciles it), with today's derived `SUM(seats * price * 0.95)` relabelled `estimated_gross`, and `total_earnings` kept as an alias? Money semantics + public response shape, so it is the owner's.
- **RV-08: gate was WRONG, and the chosen target had a real defect - FIXED (`R2 sec 96`).** The gate said "OWNER: deploy TARGET" but its own Decisions cell said decision 7 was ANSWERED (= B: Render, `2114308`) - verified: `deploy-to-vps.yml` is `workflow_dispatch`-only. **Real defect found and fixed: `DB_REPLICA_PORT` was set in 3 places (`render.yaml` as a required secret, `phpunit.xml`, `scratch-env.ps1`) and read in NONE** - reads hit the replica HOST on the PRIMARY port. Fixed at `config/database.php` `read.port`, mirroring the host guard (production only, falls back to DB_PORT). `RV08ReplicaPortTest` 5/13, NEEDLED 4-of-5 fail reverted, SHA256 restore; config floor 68 tests, only failure is the pre-existing boundary proposal. Render path otherwise verified coherent (`/start.sh` CMD + migrate + `/up` health check). **Remaining: approval to delete the superseded VPS/compose files (ask-first) - row is BLOCKED on that one question.**
- **AF-7 VERIFIED FIX on the real defect; the row was mostly NOT a defect (`R2 sec 95`).** The rides-as-driver / bookings-as-passenger rollup was duplicated VERBATIM in `ProfileController:413/426` and `StaffOperationsController:132/144` and is now one `UserRideStatsService`. Reshaping stays in each controller (different public shapes = ask-first). Bisect over 478 tests, method level: 11 named failures at HEAD -> 12 after, the only difference being `BoundaryDependencyTest`. **The other ~38 sites are `findOrFail` lookups that are correct Laravel** - `RideController:232` is SEATS and `PassengerProfileController:688` is COMPLAINTS, both regex false positives - so they are dismissed with reasons rather than wrapped.
- **OWNER APPROVAL PENDING: boundary baseline `controllers_to_models` 21 -> 20** (`BoundaryDependencyTest:48`). `ProfileController` no longer imports `App\Models\*`. `AGENTS.md` says report and propose, and separately says ask before changing BASELINES, so it is NOT applied and that one test fails by design.
- **RV-04 VERIFIED FIX (`R2 sec 93`) - the row was STALE, not open.** All three items were already done and proven: staff->user replay closed by the `sub_type` guard (`RV04TokenAudienceTest`, `85643e9`, 10 tests pass), empty JWT secret refused by the boot guard (`AppServiceProvider:372`), TTL shipped at `config/jwt.php:39` (decision 9 = B). Its blocker "AF-4b (Sanctum already gone)" was never a gate. Identity floor re-run to justify flipping a P0: 419 tests.
- **NEW OWNER DECISIONS (`R2 sec 93`), 3 rows filed so nothing is silently dropped:** (1) **row 111** - 13 tests are red because they encode a PRE-TIGHTENING auth model (`/api/employees` is `staff:system_admin`; the tests use an ADMIN employee), and `TOKEN_TYPE_INVALID` is unreachable BY DESIGN because refresh tokens are opaque `Str::random(64)`, not JWTs - both endpoints still correctly return 401. App-or-test is an auth/permission call. (2) **row 112** - `users.token_version` defaults to 1 in the migration but `UserFactory` forces 0; **NOT a security hole** (verified: the `isset` guard already fails closed), it is a fidelity gap, and aligning it means either a migration or editing an existing test. (3) **row 110** - AF-7 re-measured at **18 controllers / 42 sites** (the map's "21" is stale); Larastan half already shipped; NOT started here because it spans money paths and could not be finished and verified.
- **A HYPOTHESIS RETRACTED, recorded so it is not re-derived (`R2 sec 93`):** `JwtService:91` read as a bare `(int) $payload["ver"]` cast looked like a fail-open for users on `token_version = 0`. Line 87 already has `if (!isset($payload["ver"])) return false;`. There is no fail-open.
- **RV-14 VERIFIED FIX (`R2 sec 92`) - the row's last code item is DONE.** The guard that calls the
  routing service tested `empty()` but the three fills inside it used `??`, which only replaces
  null/absent. A client sending `distance=0`, `duration=0` or `route_geometry=[]` (all three pass
  `CreateRideRequest`) tripped the guard and then KEPT its degenerate value, so the ride could be
  stored with a real server geometry beside a distance of 0 - and `distance` drives the fare.
  Latent because the Flutter client sends none of the three (`R2 sec 85`), but the endpoint is
  public. Fix = make the fill test the same thing the guard tests. Needle proved the old code stored
  0.0; a complement test pins that complete client data is still kept and the routing service is
  still NOT called (so "always overwrite" cannot pass). Bisect 647 tests 10F -> 8F, ZERO new.
  **Do NOT `git add` `RideController.php`** - it holds the owner's uncommitted RV-38 edits; the
  commit was staged as a HEAD-derived patch so only the RV-14 hunk went in.
- **STILL OWNER-GATED after T4-10 - 5 decisions, 5 tasks, none guessed:** (1) deploy TARGET (Render
  vs VPS) unblocks RV-08 + T3-10; (2) RV-10 W window from reporter data; (3) RV-20 refund-half
  interface design (`R2 sec 86`/87); (4) T1-3 credential rotation + `filter-repo`; (5) T4-9 repair
  Arabic literals in place, or normalize at the API boundary, or leave as-is.
- **T4-8 - VERIFIED FIX (`R2 sec 90`).** The mojibake repair CONVERGES; `sec 88`'s "fixed point"
  was `ISO-8859-1`, which cannot represent U+20AC and therefore returns its input unchanged - the
  same thing, visually, as "already converged". **Windows-1252** inverts it, and repeating the
  inverse unwinds multi-pass corruption. 250 runs across the same 9 files, every one an exact
  inverse: no character guessed, no ASCII stand-in. Scope was held to COMMENT TOKENS by PHP
  tokenizer byte spans, so no string literal was reachable. Token-level proof (9 files, 0
  failures) with needles that fail on a 1-character string edit. New zero-baseline gate
  `T48CommentEncodingTest`, 26 tests. Bisected 848 tests 19F -> 18F, the only difference being the
  new gate going red -> green. **Correction carried forward: the byte dump in `sec 88` was itself
  transcribed through a re-encoding terminal and is not reliable evidence - compare code points.**
- **NEW OWNER DECISION (BACKLOG row 108 / T4-9): the SAME mojibake in USER-VISIBLE Arabic string
  literals - 6 files, 89 runs** (`R2 sec 90`). Found while scoping T4-8 and deliberately left
  alone: repairing it changes text the Flutter client renders. **Choose: repair in place, or
  normalize at the API boundary, or leave as-is.** This is more user-visible than T4-8 was.
- OWNER DECISION NEEDED (RV-20 REFUND HALF) - `R2 sec 86`: both real refund paths are SET-LEVEL
  `(Ride, Collection)` - one aggregate SyCash sufficiency check, `PostingKey::buildForSet`, one
  `debitEscrowForSet`, and ONE combined SyCash ledger row - but `PaymentStrategy::processRefund(
  Booking, Ride, User)` is per-booking and matches NEITHER real flow. Calling it per booking would
  weaken the guard, change the idempotency scope and multiply ledger rows, so it was NOT done.
  **Choose: (a) split into `processDriverCancellationRefund` / `processStaffCancellationRefund`, or
  (b) re-shape to `(Ride, Collection, reason)` with a reason enum.** Either is an interface design
  change, not a wiring change. Note `processRefund` has never had a production caller.
- **RV-09 IS NOW FULLY CLOSED (`R2 sec 88`)** - item (b) "events carrying ids not models" is closed as
  NOT-A-DEFECT, on evidence.** The codebase has exactly ONE listener (single `$listen` entry,
  `shouldDiscoverEvents()` = false); it reads only `$event->user->id` and a string; the other five
  events have NO listeners and are broadcast-only. So no listener can act on stale model state, while
  "fixing" it would change SIX public WebSocket payloads the Flutter client consumes. **Do not
  re-open (b)** unless a listener is added that MUTATES a carried model or reads an attribute another
  writer may have changed - that trigger is recorded in `sec 88`.
- **NEW UNBLOCKED TASK - BACKLOG row 107 (T4-8): source comments are DOUBLE-ENCODED UTF-8 in 9 files
  (`R2 sec 88`).** Symptom `â€` / `Ã`; e.g. `RideService:190`. This is the documented cause of the
  "non-ASCII anchors fail to match" rule in AGENTS.md. **Blocked by: none - the rule selects it.**
  Comments only; `RideController.php` is NOT one of the 9, so the owner's edits stay untouched.
- Done recently: **RV-20 CHARGE HALF VERIFIED FIX (`R2 sec 86`)** - `bookRide` + `acceptBooking` now
  dispatch through `PaymentStrategyFactory`; the `E_PAY` branch is gone from `BookingService` (the
  actual point: a new payment method no longer means editing that service). 8 new tests, with a
  NON-VACUOUS routing proof - the E-PAY money outcome is identical before and after, so the proof
  rides on the CASH path, where only `CashPaymentStrategy` produces the log line. Needle 2 fail; bisect
  641/11F -> 641/11F IDENTICAL. 2 disclosed behaviour changes: cash bookings now log, and an
  out-of-range `payment_method` now throws instead of silently not charging.
- (empty - no other task is mid-flight.)
- **CORRECTION: D6 IS NOT BLOCKED.** Earlier notes said D6 was "genuinely blocked on the owner". That
  was STALE. `D6 = B (metres/seconds)` has been answered since 2026-10-04 (STATE.md, BACKLOG row 28) and
  is now independently CORROBORATED from the Flutter client (`R2 sec 85`): routing APIs return metres +
  seconds, the client divides by 1000/60 only for its own fare and "كم" display, and every wire
  `distance` is METRES. **Do not ask about D6 again.** Remaining RV-14 work is the `??`-inside-the-guard
  quirk in `RideController:699` - latent only, and that file has owner edits.
- Done recently: **RV-09(a) VERIFIED FIX (`R2 sec 84`)** - `EPayPaymentStrategy` no longer catches
  anything: 3 try/catch blocks removed, so D1's posting-key and escrow guards now PROPAGATE instead of
  becoming an ignorable `PaymentResult`. **2 existing tests inverted** (they pinned the swallow on
  purpose) - disclosed, and the bisect shows them failing at HEAD and passing after. `attempts: 3`
  added to the 13 `sec 77` money entrypoints + `LedgerService:69`, which needed a `$written` reset
  first or the retry would hand the caller phantom entries. Bisect 534/9F -> 534/7F: 2 fixed, ZERO new.
  **`sec 83`'s RV-20 landmine is now defused.** RV-09 stays PARTIAL - only (b) "events carry ids not
  models" remains. **TWO PROCESS TRAPS RECORDED IN `sec 84`, both of which bite the next session:**
  (1) `WriteAllLines` silently converts LF files to CRLF here and `core.autocrlf` HIDES it from
  `git diff` - only Pint catches it; (2) `git stash push` with an explicit path list silently created
  NO stash when the files were already reverted, so a bisect can "prove" identity while measuring
  nothing and the change is gone. Back files up to disk and verify with SHA256.
- Done recently: **D1 DISCHARGED the recorded blocker on RV-09 and RV-20 - and armed a landmine
  (`R2 sec 83`, documentation only, no code).** Both rows said "remainder coupled to RV-02 L2"; that
  is now done, so by the letter of the gate they are the next work. **RV-20 must NOT be taken.**
  `EPayPaymentStrategy` wraps every wallet call in `catch (\Exception) { return PaymentResult::failure(); }`,
  so routing `BookingService:118/:181` (charge) and `RideService:186` (refund) through the factory
  would **swallow D1's new posting-key and escrow guards** and turn a hard abort into an ignorable
  `PaymentResult`. **RV-09(a) (stop the strategies swallowing exceptions) is now the PRECONDITION for
  RV-20, not a parallel improvement.** That reordering changes money-path error semantics, so it is
  the owner's call.
- Done recently: **T3-4 VERIFIED FIX (`R2 sec 82`)** - the wallet-request audit trail recorded the
  employee's SHADOW `users` row, and `ensureShadowUser()` returns whatever user it finds by EMAIL, so
  a customer holding the admin's email became the recorded approver of a financial request. Added
  `processed_by_employee_id` -> `employees(id)` ON DELETE SET NULL at both admin sites;
  `processed_by` kept for history. 8 new tests incl. one that DEMONSTRATES the collision. Needle both
  directions; family + money floor diffed by name set, both IDENTICAL to baseline.
  **NEW FOLLOW-UP, not yet a row: `users.banned_by` and `wallet_transactions.user_id` are still
  shadow-based and still wrong in that same scenario - the fix pattern is known.**
- Done recently: **D1 / RV-02 L2 is now VERIFIED FIX (`R2 sec 81`)** - `bookings.escrow_held` is finally
  written and read: guarded decrements (`WHERE escrow_held >= :amount`, abort unless 1 row) at all 8
  escrow sites, plus a deterministic UNIQUE `wallet_transactions.posting_key`, so a replayed settlement
  moves nothing. 2 migrations (verified up AND down AND re-runnable), shared `app/Support/PostingKey`.
  Owner constraint honoured and PROVEN by needle: 95/5 exact, all four elapsed refund tiers exact.
  Controlled bisect 623 tests / 11 failures at HEAD vs 623 / 11 after - failure name set IDENTICAL.
  Two corrections to the `sec 77` map are recorded in `sec 81`: the map over-counted `CashRideFeeService`
  (no SyCash, no booking), and its reason for the "invariant is false" note was imprecise. 16 test
  fixtures in 2 files stopped booking-and-settling-without-paying-for; NO assertion was changed.
  `autoClearDebt` is deliberately left unkeyed (a REPEATABLE posting).
- Done recently: **D3 (RV-13) VERIFIED FIX (`R2 sec 79`)** - `DomainException::toArray()` shipped the raw
  message in production while `\InvalidArgumentException` masked; the newer exception was the LEAKIER.
  Now masked + logged, `code` and shape unchanged. **This unblocks the 61-site migration as
  behaviour-preserving work.**
- Done recently: **D10 (RV-11-B) and D4 (RV-19 item 4) both VERIFIED FIX (`R2 sec 78`)** - the
  `models_to_enums` baseline went 2 -> **3** (the ratchet counts FILES, so the owner's 4 enum PAIRS are
  3 edges; a 4 would have been a green hole); and the dead `config('system_admin.email')` rating seed is
  removed with rating 3 now PROVEN at SIGNUP.
- Done recently: **RV-19 item 5 VERIFIED FIX (`R2 sec 76`)** - three admin derived numbers fixed and the
  bug-pinning tests flipped. Item 4 proven but OWNER-GATED: `config/system_admin.php` is absent, so the
  new-driver rating seed silently never runs.
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
- RESOLVED (RV-13a) - was listed here as "OWNER DECISION NEEDED: should a domain rule violation's MESSAGE be
  visible in production?". **D3 = A ANSWERED and APPLIED (`R2 sec 79`): DomainException messages were the
  LEAKER and are now masked and logged, `code` and shape unchanged.** This UNBLOCKS the 61-site
  migration as behaviour-preserving work. Do not re-ask.
- Done recently: RV-10 escrow-liveness investigated - **BLOCKED** on the decision above (`R2 sec 70`), row
  corrected from "decision-free".
- Done recently: RV-16 signup mail leaves the DB transaction - VERIFIED FIX (`R2 sec 69`).
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

## In progress

(none - AF-7 reached a terminal state)

## Done recently (newest first; detail is in the audit record)
- **AF-5 (P0) PARTIAL** (`R2 sec 89`, `16ba613`) - all 14 hard-coded `'public'` disk literals replaced by
  config (`uploads_disk` / `documents_disk`); while scoping it I found a **half-wired switch**:
  `DocumentController` wrote to `documents_disk` but `StaffDocumentController` read from `'public'`, so the
  documented `DOCUMENTS_DISK=minio` deploy would have **404'd every document** while the record claimed KYC
  was closed. Behaviour UNCHANGED (all defaults stay `public`). 7 tests/13 assertions; needle `404 != 200`;
  regression 804 tests / same 14 failures, zero new. **Remainder is a DEPLOY action, not code: a MinIO bucket
  and credentials must exist before either disk is flipped.**
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

## Owner decisions 2026-10-04 - ALL 12 ANSWERED (D1-D12). Settled, do not re-litigate.
- **D1 = A** (RV-02 L2). Unique `wallet_transactions.posting_key` + per-booking `escrow_held` with
  GUARDED decrements (`WHERE escrow_held >= :amount`, abort unless exactly 1 row changed); SyCash
  becomes derived/reconciled. **HARD CONSTRAINT from the owner: keep the 95/5 split and ALL
  cancellation refund tiers EXACTLY as they are today - bookkeeping only, no percentage changes.**
- **D2 = D + C** (RV-10). Build the READ-ONLY stuck-escrow reporter first (age + amount, moves no
  money), then the staff-queue escalation. Window W still unnamed - take it from the reporter's data.
- **D3 = A** (RV-13a). Mask `DomainException` messages in production; log the detail.
- **D4 = B** (RV-19 item 4). Remove the new-driver rating seed so it stops pretending. **Owner intent:
  the user should have rating 3 at SIGNUP - implement at signup, not in verification approval.**
- **D5 = C** (RV-19 item 2). Add TWO clearly-named fields (ledger earnings + estimated gross) and keep
  `total_earnings` as an ALIAS so the admin front-end does not break.
- **D6 = B** (RV-14). `distance` in METRES, `duration` in SECONDS. Fix the `320.5` / `12345` /
  `320500` fixtures to match. One documented unit each.
- **D7 = A** (RV-12). Stop persisting `status = 0`; migrate existing `0` rows to `1`. Keep numeric
  status in API responses (1 active, -1 banned). Admin "suspended" must mean BANNED.
- **D8 = A** (T3-4 + RV-21). Add nullable `processed_by_employee_id` with an FK to `employees`; keep
  `processed_by` for history. KEEP `wallet_requests.wallet_id NOT NULL` and fix the 26 test fixtures
  to supply `wallet_id` - do NOT change the schema for it.
- **D9 = C** (T3-10 + RV-08). Target-agnostic hygiene ONLY for now: no token in the remote URL, no
  hard `reset --hard` to a feature branch. The deploy target is chosen later.
- **D10 = A** (RV-11-B), conditional on the edge list being published first. **That list, measured:
  `models_to_enums` baseline is 2 and the ACTUAL edges are exactly 4 -** `Complaint` -> `ComplaintStatus`
  and -> `ComplaintType`, `Employee` -> `StaffRole`, `Wallet` -> `WalletKind`. All four are legitimate
  domain enums, not violations; the baseline predates them. Raise 2 -> 4 with these four named.
- **D11 = A** (RV-29 item 5). `communication_number` gating stays DEFERRED.
- **D12** acknowledged as owner-only actions, not scheduled for the agent: T1-3 (rotate
  firebase-credentials + `dump.sql`, purge history), T2-8 (rotate Pusher), RV-16
  (`APP_ENV=production` in the deployed `.env`), RV-27 / RV-28 (prod FCM keys, real delivery,
  least-privilege DB user, `TRUSTED_PROXIES`), RV-17 (production-shaped k6 run).

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
