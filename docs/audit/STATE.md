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
- **RESOLVED (`R2 sec 111`): boundary baseline `controllers_to_models` 21 -> 20, owner-approved 2026-10-08.** Measured before changing it - the run printed "only 20 violations remain ... lower the number in BASELINES" and listed the twenty files. At 21 with 20 real violations the ratchet did not bite: a 21st controller reaching for a model would have been added with the suite green. `BoundaryDependencyTest` 9/36, OK.
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
- **OWNER DECISION (BACKLOG row 108 / T4-9): the SAME mojibake in USER-VISIBLE Arabic string
  literals - 6 files, 89 runs** (`R2 sec 90`). **CARRIED OUT (`R2 sec 120`).**
  Chose repair-in-place (2026-10-11), and settled the one thing the bytes could not: the bug
  dropped the hamza from Idlib, so the standard hamza spelling was chosen (2026-10-12). Source
  literals, the stored `users.address` values (new migration), the validator and the city
  report were repaired TOGETHER, because the garbled strings were stored data and validation
  rules, not only display text. **The Flutter client was NOT verified** - there is no pubspec.yaml
  in this repo; a client still sending garbled address values will now be rejected by the
  validator, which is correct but client-visible.
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

## Done recently
- **RV-52 cancellation window - VERIFIED FIX (`R2 sec 144`).** Owner ruled 1 hour before departure. Re-enabled the commented-out check in `validateCanCancelRide`. Through the real route: inside the window 422 and the ride stays active; 3 hours out 200 and cancelled. RideValidationServiceTest 17 OK; Rides 90, Bookings 11, Domain 122 OK.
- **Selection check (`R2 sec 143`): RV-47 (row 122) corrected OPEN to PARTIAL.** It still read OPEN with Blocked by `none`, so the selection rule would have re-picked it, but its remaining items are all owner decisions (RV-46, RV-49, RV-50, RV-51, RV-52). **No row qualifies for code work;** the owner decisions above unlock the next tasks.
- **RV-47 misc triaged (`R2 sec 142`); RV-52 (row 127) filed, P1.** `validateCanCancelRide()` has its 1-hour check commented out, so a driver can cancel after departure. The sibling booking rule still enforces 2 hours. Already in APP_FUTURE_SONNET.md:172 but not in the backlog. Owner decision: restore the rule and its window, or retire the test. RV-47 now has no code work left without an owner decision.
- **ImageMessageType (4) triaged - stale test contract, not a production defect; RV-51 (row 126) filed (`R2 sec 141`).** Production uploads go through `ChatMessageHandler::sendImageMessage` to `FileUploadService`; `ImageMessageType::process()` is not on that path. The tests pass a stored path as `content`, which `validate()` correctly rejects. Owner decides whether `process()` stays for image messages.
- **RV-47 AdminWalletService seed gap - VERIFIED FIX (`R2 sec 140`).** `AdminWalletServiceTest` never seeded the External Capital wallet, which `chargeWallet()` requires (decision un3). Fixed in the test setup only, by reusing the shared `SeedsSystemWallets` helper: 17 tests and 67 assertions green, up from 4 errors. Service unchanged. Wallet-referencing files 41 tests green.
- **RV-47 StaffComplaintService signature drift - VERIFIED FIX (`R2 sec 138`).** `listAll()` and `listEscalated()` filter parameters made optional (`null` defaults). Staff floor 233 tests green; unit file 20 errors to 1 failure. The remaining failure is split out as RV-49 (row 124), owner decision on the audit prefix. Row 122 stays OPEN for the other groups.
- **RV-47 `AdminDriverService` 14 triaged - NOT a defect; and the real finding filed as RV-48 (row 123) (`R2 sec 137`).** Section 136 called these 14 *"the shape of a real reporting defect"* on the failure text. **That read was wrong, and one probe refuted it.** Two causes, both later intentional changes the tests were never updated for: (1) `getStats()` counts `User::bannedNow()` = `status = -1` since RV-42a, because `status = 0` is LOGGED_OUT and `unban()` writes it back - the tests still create `status => 0` and expect "suspended"; (2) **every new user gets a platform-assigned 3.0 rating from `UserObserver::created` (`:27-35`), so a driver rated 5.0 reads 4.0** - measured: (3.0+4.0+5.0)/3 = 4.0, the probe reproduced the exact value the test saw. **RV-48 filed for that: the dashboard average is anchored by synthetic signup ratings and nobody recorded why the seed row exists.** Tests NOT edited - one batch at a time by the owner.
- **V15 SUPERSEDED - delivered, not asserted (`R2 sec 136`). RV-47 (row 122) NEW, P1, OPEN.** V15 asked for suite errors grouped by message. Done on real data, and the finding's own prediction is wrong: it expected **2** groups, there are **6**. The suspicion that green floors were hiding red directories was correct - `tests/Unit/Services`, which **no floor in this sweep ever ran**, holds **67 problems (39 errors + 28 failures) across 360 tests**. Groups: `StaffComplaintService` signature drift 20 (`ArgumentCountError`, tests call an older signature), `AdminDriverService` aggregates 14, `GeocodingServiceTest` 13 (incl. `TypeError: PendingRequest::throw()` API misuse), `CashRideFeeService` 5 (= RV-46), `AdminWalletService` 4 (missing External Capital wallet seed), `ImageMessageType` 4 (`Undefined array key "image"`), misc 7. **Triage these first:** the `AdminDriverService` failures are aggregation correctness (`suspended 0 vs expected 2`, `rating 4.0 vs 5.0`, `average 4.0 vs 4.5`) and are the shape of a real reporting defect rather than stale expectations.
- **RV-46 (row 121) NEW, P0, DEFERRED - cash-ride creation-fee refund tiers (`R2 sec 135`).** `CashRideFeeService.php:342-343` returns 100% unless (elapsed >= 30% AND hadActiveBookings) - a binary 100/0 rule - while `CashRideFeeServiceTest` asserts elapsed tiers 100/70/50/0, leaving 3 of 4 red (the first passes only by coincidence). Two different product designs; the docblock says binary, the audit pins four tiers. **Owner 2026-10-12: mark to be considered, nothing changed.** Direction of the error recorded: at 40% elapsed a 5.00 fee refunds 5.00 where the tier policy says 3.50.
- **RideTest stale fee expectation FIXED (`R2 sec 135`) - `tests/Feature/Rides` is now fully green.** `test_ride_creation_does_not_charge_any_fee` asserted no fee, citing tests that were SKIPPED not deleted; the 5% creation fee was then fully implemented (RideService:41-44, `calculateRideCreationFee`, CashRideFeeService charge/refund/debt, ledger type, columns + migration, dashboard). The 2,000 debit is 5% of 40,000 and is NOT the deferral path - `canCreateCashRide` checks balance FIRST (:137). Replaced with `test_ride_creation_charges_the_5_percent_creation_fee`, which pins driver debited 2,000, platform credited 2,000, `cash_creation_fee = 2000` / `cash_fee_deferred = false`, and a `-2000` ledger row. **The new test strictly dominates: the old one would have passed if the fee were charged at the wrong rate or credited to the wrong wallet.** Floor now 90 tests / 182 assertions / 0 failures.
- **V6, V13, V14 all SUPERSEDED (`R2 sec 134`); RV-37 ratchet regression REPAIRED.** V6 resolved by RV-18 (`sonar.yml` provisions mysql:8.0 and sets `DB_CONNECTION=mysql` at the PROCESS level, which the committed phpunit.xml `<env>sqlite</env>` without `force` cannot override; `CI_REQUIRE_MYSQL=1` arms `CiMySqlDriverTest` so a wrong driver is a red job, not a silent skip). V13 environment half resolved - all five `TestDeterminismRatchetTest` invariants green. V14 resolved by RV-37 across FIVE orders, not three. **THE FINDING THAT MATTERS: the ratchet was red and the cause was this agent's own `V2SridAlterSafetyTest` from the V2 commit - it called `DB::statement` with no transactional trait, exempted only by a docblock argument the ratchet cannot verify. Repaired with `DatabaseTransactions`; 14 tests / 35 assertions green, probe table confirmed absent. Lesson recorded: an exemption asserted in a comment is not an exemption the project can hold you to.** Also recorded: a `tests\**\*.php` glob reported "0 putenv leaks" - a FALSE ZERO, since PowerShell does not recurse that pattern; the real figure is 27 sites across 9 files.
- **RV-44 (row 119) NEW, P0, VERIFIED FIX (`R2 sec 133`)** - `POST /bookings/{id}/passenger-confirm` answered **500 for every refusal**: the controller catch-all swallowed the `InvalidArgumentException` that `BookingService` throws for "not yours / not departed / wrong state", so a passenger confirming twenty minutes early was told the server was broken. Fixed by one branch ahead of the catch-all that re-throws domain errors so RV-13 maps them to 422 with the `errors` bag; genuine internal faults still log and return 500. Owner ruling 2026-10-12: 422, this endpoint only. **Five tests in `PassengerConfirmCompletionTest` had pinned the 500 as the contract** (4th occurrence of that pattern in this project); `test_passenger_confirm_fails_for_active_ride` had a false premise (ACTIVE is deliberately confirmable) and was replaced. **41 controllers return a hard-coded 5xx from a catch - 40 left untouched by ruling, a real systemic finding for its own row.** Found via the last 4 red tests in the project; floor 223 tests / 1 pre-existing money failure.
- **V8, V9, V10, V11, V12, V16 all SUPERSEDED (`R2 sec 132`)** - batch-verified together, six different resolutions: V8 answered (no `config/system_admin.php`; `config/admin.php` is wallet-routing only, credentials live in `employees`); V9 satisfied (composite `UNIQUE(rater_id, rated_user_id)` present, pinned by RV-30); V10 fixed (`POST api/rides` pointed at a method that does not exist, 500 for every caller); V11 resolved (both GET and POST registered); V16 equivalent (money and score match on both payment branches, 2 tests / 7 assertions). **V12 was REFUTED, not repaired** - `auth()->id()` was never null, and `AuthFacadeRatchetTest` enforces the house rule rather than a bug. **Running tally: only V3 (live 500) and V2 (no spatial index) were real unfixed defects** - treat the remaining RECORDED rows as resolved until verified.
- **V5 (row 5) SUPERSEDED (`R2 sec 131`)** - fixed by RV-13 (row 27). The catch-all `renderable(Throwable)` matched `ValidationException`, mapped it to 422 and then REBUILT the body, discarding `$e->errors()` - correct status, no information about which field failed. `Handler.php` now registers a dedicated `ValidationException` renderable ahead of the catch-all, restoring `message` + `errors` keyed by field while leaving status mapping, headers and generic 500s untouched. Verified 25 tests / 62 assertions. **Fourth duplicate row** after V1, RV-43 and V4.
- **V4 (row 4) SUPERSEDED (`R2 sec 130`)** - resolved by RV-05 (row 21). Evidence `"collapse CONFIRMED, spoof REFUTED"` is a finding *and* its own refutation: only the collapse was a defect, and the spoof escalation was investigated and does not hold. `ClientIpBehindProxyTest` (8 tests / 14 assertions) re-run green, covering both halves - two clients get separate IP keys, and an untrusted source still cannot forge its IP. **Residual surfaced:** `TRUSTED_PROXIES` defaults to trusting nobody, so a deployment that never sets it still collapses rate limiting into one bucket; it appeared in STATE.md only in prose, never as an actionable item. Now listed under Owner actions outstanding.
- **V2 VERIFIED FIX (`R2 sec 129`)** - `rides` has one SPATIAL index per geometry column again, and the columns now declare the SRID they actually store. Root cause was two migrations apart: the create-table added `spatialIndex()` on both columns and the very next migration dropped and recreated them as plain `geometry()`, taking the indexes silently. **Two findings worth keeping:** (a) the first needle did NOT fail - injecting a coordinate swap into the migration left all 5 tests green, because `RefreshDatabase` migrates an EMPTY schema, so a test suite cannot catch a migration that damages pre-existing rows; the real guard (`V2SridAlterSafetyTest`, no `RefreshDatabase`) runs the ALTER against populated rows and was needled successfully. (b) **this does NOT speed up the current search** - measured on 20k rows, `ST_Distance_Sphere` has an empty `possible_keys`, so the index is not a candidate for the pattern the app uses. Schema debt closed; search cost unchanged pending an MBR prefilter.
- **V1 (row 1) SUPERSEDED (`R2 sec 128`)** - the oldest P0 in the table was closed by RV-25 (row 42), which shipped the axis-order fix. Re-verified instead of assumed: MySQL 8.2.0 gives **309.00 km** for `POINT(lat lng)` and **257.93 km** for the transposed reading - exactly the pair this row recorded as the failure. No code change. **Second case of one row duplicating another** (the first was RV-43); both were a round-1 finding fixed in a later round while the original row was never revisited.
- **V3 VERIFIED FIX (`R2 sec 127`)** - ride search no longer 500s. `ST_Buffer` on a LINESTRING is unimplemented in a geographic SRS (MySQL 3618) and `ST_GeomFromGeoJSON` defaults to SRID 4326, so the route-matching `orWhere` raised on every search that met a routed ride - reachable in production because clients can set `route_geometry` via POST /rides. Fixed by relabelling the parsed route to Cartesian with `ST_SRID(...,0)`, keeping the radius in degrees. Also corrected a fixture in `WaveZeroVerificationTest` that accepted "raises a QueryException" as a pass and stored `route_geometry` double-encoded.
- **RV-43 VERIFIED FIX (`R2 sec 126`)** - the 9 red admin-login tests were FOUR defects, and in three of them **the app had been fixed while the test still asserted the old broken behaviour**: the wallet endpoints now return 200 instead of 500, and permission denials now return 403 instead of 401. A FIFTH problem surfaced with it - 3 tests in `StaffJwtMiddlewareTest` had the same stale fixture but were hidden behind `markTestSkipped`, so the suite was green for code it never ran. The RV-35 ratchet also had a hole (required a variable, missed literals) and is now widened.
- **Two owner decisions 2026-10-12 (RV-43), both taken after the diagnosis was proven and both escalated rather than assumed:** (a) fix the stale tests AND close the ratchet hole; (b) sycash IS admitted on staff routes - `StaffRole::isAdminRole()` covers `SYSTEM_ADMIN` and `SYCASH`, the route is the caller own `/api/staff/me`, and staff routes have no role gate. If that is wrong, the fix is in `StaffJwtMiddleware::handleAdminToken()`.
- **RV-04b VERIFIED FIX (`R2 sec 123`)** - the 12 red identity tests were SIX distinct causes, not the two the row claimed, and in all six the app is correct and the test was stale. Controlled bisect on a 490-test selection: HEAD 5 errors + 16 failures -> **0 new failures**, 12 fixed. Needle: removing `loadMissing()` alone reproduces 10 errors.
- **RV-42a VERIFIED FIX (`R2 sec 124`)** - "suspended" now means BANNED everywhere, via a new `User::scopeBannedNow()` that is the SQL twin of `isBannedNow()` so a count cannot drift from a single row. 8 new tests, 22 assertions. Two separate needles (query side, label side). **Numbers will drop sharply on live admin endpoints** - `suspended_users` was counting every logged-out and unbanned account.
- **RV-43 filed as row 118, NOT fixed (`R2 sec 125`)** - 9 tests in `tests/Feature/Admin/AdminDashboardControllerTest.php` fail at HEAD with a 401 where `/api/admin/login` should return 200. Pre-existing, not caused by either task. **Not yet known whether the app or the test is wrong** - that must be settled before editing the test.

- **Six `PARTIAL` rows re-statused; PARTIAL is now 0 (`R2 sec 122`).** The owner asked to "verify them fixed and just remove them from partial". **Only one of the six could honestly be closed**, because four need something that is not in this repo - a number only the owner can produce, a deferred decision, or a production-shaped host. Marking them VERIFIED FIX anyway would have written six false verifications into the file the next session reads to choose work, so they were re-statused to name their real gate: **AF-5 -> VERIFIED FIX** (re-verified on disk: `config/filesystems.php:47,84,148`, four call sites, and the only surviving `disk('public')` literals are a documented READ-ONLY legacy fallback at `StaffDocumentController:99,102`); **RV-10, RV-17, RV-29, AF-6 -> BLOCKED**. `R2 sec 121` separately fixed row 34 (RV-08), which said BLOCKED while its own Evidence said the work was finished. Census 85 -> 86 VERIFIED FIX, BLOCKED 2 -> 7, **116 rows, 0 malformed**.
- **A real defect found and filed as row 117 (RV-42a), NOT fixed (`R2 sec 122`).** Admin "suspended" means **two different populations**: `AdminUserService:99` and `AdminDriverService:99` count `status = 0`, which is `AccountStatus::LOGGED_OUT` and also what `BanService::unban()` writes - so every logged-out and every unbanned account is counted as suspended - while `StaffOperationsController:174` labels anything that is not 1 as suspended, which includes every **BANNED** account. `User.php:214` says banned is -1. `7ab1eff` **widened** this: before it, `createUser` hardcoded `status => 1` and no self-registered account was ever counted. Not fixed because it is a live public reporting field plus an auth-semantics call, both reserved by `AGENTS.md`.
- **RV-12 is NOT merely unstarted work - it is TWO SAME-DAY RULINGS IN CONFLICT.** Decision `D7=A` (2026-10-04: stop persisting `status = 0`, migrate 0 rows to 1) was **never built**, and commit `7ab1eff` (`R2 sec 61.2`, **the same day**) shipped the opposite on purpose. D7=A also cannot be applied literally: it would break four tests that pin `status = 0` as correct (`SignupPasswordOverwriteTest:184`, `AccountStatusBanServiceTest:152`, `AdminBanControllerTest:249,308`). **OWNER ONLY:** decide which 2026-10-04 ruling governs, then build it or withdraw it.
  
- **The Arabic the API actually returns to users was double-encoded, and the fix had to cover the stored data too (`R2 sec 120`) - T4-9 is VERIFIED FIX.** The owner chose repair in place, and settled the one thing the bytes could not: the bug **dropped the hamza from Idlib**, so the source reverses to the bare alef (ادلب) rather than the standard hamza form, and the owner chose the hamza. 41 distinct literals were rewritten across six files; all recovered cleanly in one or two inverse passes, none unrecoverable. **The reason this was not a text fix is the part worth keeping:** `ProfileController:121` validates `address` with `in:` against the garbled list and `AdminReportService:168-181` uses the garbled names as the keys matching `User.address` rows, so repairing the source alone would have rejected the values existing users already have AND dropped every affected user out of the city report. Three things therefore have to agree, and a new migration rewrites only the 14 exact stored garbled values, 1:1 and reversible, matched **BINARY** so a collation cannot fold two distinct garbled strings together, with the map written as `\u{...}` escapes so the file is ASCII-only and cannot itself be re-corrupted. A subtlety that would have shipped a bug: the hamza override must apply to the **literal rewrite** as well as the migration, or the source keeps the bare alef while the migration writes the hamza and the two disagree about what a valid city is - a test caught exactly that. Verification: clean `php -l` and Pint on all 8 files; Review floor 501 tests / 1756 assertions / no failures; a controlled bisect over 8 directories with the repair vs at HEAD gives 957 tests both ways and **19 failures identical either way, none new**; needle 1 (revert the validator to the garbled HEAD list) catches 2 tests and needle 2 (empty the migration map) catches 6 of 8, restore SHA256-identical each time. **Two fake tests the needles caught, recorded because both looked green:** one built its own `in:` rule from the migration map instead of reading the rule that ships in ProfileController, so it passed against a broken validator; another asserted the absence of the literal `Unknown` when the real fallback is `?? $row->address`, so the assertion could never fail. Both now read the real source. **Not claimed: the Flutter client** - there is no pubspec.yaml in this repo, so if it still sends garbled address values the validator will now reject them, which is correct but client-visible.
- **`UserFactory` no longer creates users the database never produces (`R2 sec 119`) - RV-04c is VERIFIED FIX.** The owner ruled the factory wrong, not the migration: `token_version` 0 -> 1, no migration. Every claim was re-verified ON DISK rather than taken from the backlog row, and the row turned out to be right on the important point: `users.token_version` defaults 1, `employees.token_version` defaults 0, `JwtService:87` fails closed on a missing claim, `:91` casts both sides and `:270` mints `ver` FROM the row, and `StaffJwtService:88` uses `?? -1` - so **any** starting value is self-consistent and this was a FIDELITY gap, not a security hole. Every factory user had existed in a state no production row is ever in, so the suite was exercising a value the database never produces. Blast radius measured over all 72 `token_version` occurrences in `tests/`: nearly every `=> 0` is an EMPLOYEE fixture, the user fixtures that matter already pass 1, and every assertion on the column is RELATIVE. **The one judgement call is stated plainly because AGENTS.md forbids editing an existing test:** `RV04TokenAudienceTest` asserted a factory user and a fresh employee start on the SAME value, then `markTestIncomplete`d to record a divergence it could not close - it sat in the suite as `Incomplete` on every run. The ruling is that they must NOT agree, so the factory change would turn that Incomplete into a real FAILURE, and the only correct move is the one the row already named: assert each actor against its OWN schema default. **That is stricter, not weaker** - before, nothing held the factory to the schema at all; now both tables are pinned against live `information_schema` defaults. The `markTestIncomplete` is gone because the divergence is genuinely closed, and the Review floor's standing `Incomplete: 1` is now **0**. A second test asserts a factory user's token round-trips at whatever version its row holds, read from the real row so it survives a future default change. **Controlled bisect over 14 directories (identity floor + every area referencing `token_version`): 1678 tests / 4565 assertions / 45 errors / 47 failures with the change, 1677 / 4561 / 45 / 47 + 1 incomplete at HEAD - the identical 92 failing NAMES either way, NONE new, NONE fixed. NEEDLED:** factory back to 0 fails with `Failed asserting that 0 is identical to 1`; restore SHA256-identical. Review floor 493 tests / 1742 assertions / no failures. Incidental: Pint's `php_unit_method_casing` silently rewrote `..._its_OWN_schema_default` into `..._its_ow_n_schema_default`, splitting `OWN` - renamed to `each_table_matches_its_own_schema_default`.
- **RV-08's known bug is fixed: the deploy workflow named a compose service that does not exist (`R2 sec 118`).** `deploy-to-vps.yml:94,97` ran `docker compose exec -T app`, and `docker-compose.yml` defines no `app` service - they are `app1`-`app5`, `nginx`, `redis`, `mysql`, `mysql_replica`, `minio`, `queue`, `scheduler` - so the step could never have completed. Both now target **`app1`**, and that choice is deliberate: it is the ONLY service carrying a `build:` block, so `docker compose up -d --build` guarantees it exists and holds the image just built; `app2`-`app5`/`queue`/`scheduler` name the built image with no build section and exist only because `app1` produced that tag, so exec-ing into one would tie the migration step to a consumer of the build rather than its owner. **The interesting part is why it survived and why the ratchet, not the edit, is the deliverable.** The workflow was disarmed in 2026-10-03 when production moved to Render, so nothing runs it and nothing noticed - a deprecated path is exactly where a latent defect hides, and the owner ruled to KEEP the file, so something has to hold the line. New `tests/Feature/Review/DeployWorkflowServiceRatchetTest.php` (3 tests / 10 assertions, 0.031s, no DB) parses the compose file with `symfony/yaml` and asserts every `docker compose exec|run` target across all six workflows is a real service; its **negative control** requires the scanner to find `app1` and NOT find `app`, or it could pass on a parse that found nothing; and a **premise guard** keeps the workflow `workflow_dispatch`-only with no `push`/`schedule` trigger, because if a push trigger returns, "hygiene on a dead path that cannot affect production" stops being true. **NEEDLED:** reintroducing `exec -T app` fails 2 of 3 (the trigger test correctly stays green); restore SHA256-identical. Review floor 492 tests / 1738 assertions / no failures; all 6 workflows still parse as YAML. **Deliberately NOT claimed: that the VPS deploy path works** - there is no VPS, production is Render, and the sandbox has no docker daemon. **Row stays `BLOCKED`** - the owner decision resolved the only question it carried, but the row's `Blocked by` column names the superseded deploy target, which this task did not change. **Zero decision-free code remainder remains on RV-08.**
- **The raw decimal math is gone: AF-6 criterion 1 is MET (`R2 sec 117`).** 14 sites in 7 files converted - but the first honest finding is that **the census was re-derived, not recalled, and the criterion's own "66 sites" is stale**: it counted the `round($x * 0.95, 2)` sites `sec 71` folded into `FeeSplit`. What remained was 14 sites, and they were not interchangeable, so they were classified before any was touched. The one that mattered: **`LedgerService::postTransfer` is the reason the ledger is trustworthy and its balance check was a float running sum compared to `0.0` with `!==`, correct only because of a trailing `round()`.** It is now an exact sum of integer minor units, and the per-leg normalisation at write time went the same way, so what is stored is what was checked. Signs are derived with `negated()`, so the second leg cannot disagree with the first about a sign. **The part worth repeating is the second honest finding: this fixed NO live bug.** A controlled probe of the old float form against the new one over seven realistic leg sets reports **0 of 7 disagreements**. My first probe dropped the old trailing `round()` and produced a dramatic-looking table; it was wrong about the code it described, so it was rewritten before anything was concluded from it, and that is recorded rather than quietly replaced. What changed is the MECHANISM - rounding decided once inside `Money::from()` instead of at 14 call sites, and the invariant exact rather than rescued. **`PassengerProfileController:480` was deliberately NOT converted: `round($avgRating, 1)` is a RATING, and a test now pins that so nobody "fixes" it.** Held by new `tests/Feature/Review/MoneyRoundingIsDecidedInOnePlaceTest.php` (5/24): a structural ratchet that no converted file may round a money token - with comments stripped by a quote-aware pass, because the conversion left comments NAMING the old `round()` calls and a ratchet matching inside a comment would forbid documenting the change it enforces - its **negative control** (every file must still use `Money::`, or a file that deleted its arithmetic would pass and prove nothing), and a test that non-money rounding SURVIVED. **NEEDLED three ways:** removing the balance check fails exactly `an_unbalanced_transfer_is_refused_rather_than_recorded`; restoring a per-call money `round()` fails the ratchet and it reports `LedgerService.php:87` with the line; reverting `BackfillBookingMoneySnapshot` to raw floats fails the ratchet AND the control. **Controlled bisect: 986 tests / 2729 assertions / 31 failures, IDENTICAL at HEAD with all 7 app files reverted - zero behavioural change.**
  **AF-6 is now 3 of 4 criteria met and stays `PARTIAL` for one reason: criterion 4's seventh alias, RV-10, is `BLOCKED` on owner window W - an ACTION, not a decision. No code task remains on the row.**
  **A defect in my own previous task was found and fixed:** the `sec 116` close-out left a duplicated `reserves for the owner.` fragment inside the AF-6 acceptance criteria, which sat there for two commits.
- **`Money` is SIGNED (`R2 sec 116`) - owner decision 2026-10-11, widen rather than add a second money type. AF-6 criterion 1 step 1 of 2.** The constructor no longer rejects negatives and `subtract()` no longer refuses a negative result, so a debit is finally expressible in the type: `postTwoPartyTransfer` emits `-amount`, `postExternalTransfer` emits `-amount` on the external leg, `FeeSplit::releaseLegs` emits the negated escrow leg, and a money VO that cannot hold a negative cannot describe any of them. **The interesting part is that the protection MOVED rather than vanished.** Two callers depended on the old throw by accident, never having asked for the rule: `FeeSplit::driverAndPlatform` (the platform share is a REMAINDER, so a negative release would split into two negative shares that still add up exactly - correct arithmetic, nonsense money, invisible downstream) and `AdminWalletService::chargeWallet` (a negative "charge" posts `type = admin_credit` while moving the wallet DOWN). Both now state the rule explicitly via `assertNotNegative` / `assertPositive`, where it is named and testable instead of a side effect of construction. Added `isNegative()`, `negated()`, `absolute()`, `assertNotNegative()`, `assertPositive()`; **operand guards on `multiply`/`divide`/`percentage` are KEPT** because a negative multiplier is a caller mistake whatever the receiver sign - only the RESULT sign was widened. **TWO NEEDLES:** restoring the old throw fails **12** of the new tests; deleting the moved `FeeSplit` guard fails **exactly one**, `a_negative_escrow_release_is_rejected` - the proof the rule was moved and not deleted. Money floor + `RV19FeeSplitTest` + `BoundaryDependencyTest` 221 tests / 321 assertions / 3 failures (the same pre-existing OTP items). The 4 `AdminWalletServiceTest` `charge_wallet` errors are **pre-existing at HEAD** (missing `SystemWalletSeeder`), proven by reverting all 5 changed files: identical 4 errors, 69 tests / 123 assertions. `MoneyTest` two tests pinned the OLD contract and were REPLACED by tests of the new contract with docblocks naming the ruling, not deleted and not edited to pass. **AF-6 stays `PARTIAL`:** criterion 1 remainder is converting the 7 services / ~66 raw-decimal sites to `Money`.
  **That remainder is now POSSIBLE and UNGATED - the obvious next task, but the rule will not select it (the row is `PARTIAL`), so ask for it by name.**
- **AF-6 owner call (a) ANSWERED and APPLIED: `ledger:reconcile` now FAILS the daily job on per-wallet drift (`R2 sec 115`).** The detection was never broken - `sec 114` proved that - but the RESPONSE was: the command printed a correct, actionable report naming the wallet and the amount, then **exited 0**. So at 04:30 every morning the scheduler logged "unexpected on ANY wallet: a converted money path moved a balance without posting legs" and reported success. The report was a logfile line, not an alarm. Three edits in the command only: `return self::FAILURE` in the unexplained branch, the description, and the docblock paragraph that documented the old permissiveness. The report itself is unchanged - diagnosis first, then the alarm. **NEEDLED:** reverting the decision fails **7 of the 9** `LedgerReconcileDetectsDriftTest` tests and leaves only the two that assert exit 0 either way; command restored SHA256-identical; money floor identical under the needle. The `DoubleEntryLedgerTest` success test is **still green and deliberately NOT edited** - clean data must exit 0, and had it failed that would have been a finding rather than something to patch. One consequence named rather than buried: the threshold test is now load-bearing, because a tolerance raised above the real drift returns exit 0 and is precisely how the new alarm would be silenced. Money floor 172 tests / 216 assertions / 3 failures - the same pre-existing `WalletTest` OTP items, **zero new**. **AF-6 stays `PARTIAL` for one reason now: criterion 1, the signed-money sweep, approved in principle but NOT implemented - too large to fold into an alerting change.**
  **One task per session. The other three answers are NOT yet implemented - see Owner decisions.**
- **AF-6 criterion 3 is no longer an unproven claim: `ledger:reconcile` had never been shown to DETECT anything (`R2 sec 114`).** The command runs DAILY at 04:30 (`Kernel:84`) and its whole purpose is to catch a money path that moved a balance without posting the legs that explain it - yet the only test touching it asserted a clean run exits 0. New `tests/Feature/Review/LedgerReconcileDetectsDriftTest.php` injects an **unledgered** `wallet_transactions` row, the only fault shape a real bug can take here (`LedgerService::postTransfer` refuses legs that do not sum to zero, so the realistic fault is a MISSING leg, not a bad one), and proves the per-wallet report fires, names the wallet and quantifies the drift with its sign. 8 tests / 25 assertions. **NEEDLED:** making drift unreportable fails **6 of the 8** tests and leaves only the two negative controls green, which is the shape a causality test should produce; `ReconcileLedgerCommand.php` restored SHA256-identical. **Three of my own assumptions were refuted by measurement instead of being shipped:** `--threshold 0.0` cannot surface sub-cent drift (the drift is rounded to 2dp at `ReconcileLedgerCommand:76` BEFORE the comparison - correct for `decimal(15,2)`, RV-40 - so the tolerance is now pinned at the cent scale, the scale money exists at); two `expectsOutputToContain` values on the SAME output line can never both match, because `PendingCommand:423-431` registers one Mockery expectation per `BufferedOutput::doWrite` and Mockery attributes one call to one expectation (a probe showed all five candidate strings matching alone, and only the chained pair failing), so wallet and amount are asserted one test each; and `Artisan::output()` returns an empty string in this harness, so the console reporting path is used and is order-independent - the clean-data test passes in isolation and in the full file. Money floor 199 tests / 318 assertions / 3 failures: the same three pre-existing `WalletTest` OTP baseline items, **zero new failures**. **AF-6 stays `PARTIAL`** - criterion 1 is still the signed-money design call, and the exit code on drift is still owner call (a); this task supplies the evidence for it rather than pre-empting it.
  **The rule still returns nothing. The four OPEN rows - T3-10, T4-9, RV-04b, RV-04c - are all owner-gated.**
- **The next-task rule was never empty: `BACKLOG.md` reported `OPEN` on a row whose own Evidence cell said "Row closed" (`R2 sec 113`).** Row 110 (AF-7b) was the only `OPEN` row with `Blocked by = none` - the one row the rule can legally select - and its `Status` read `OPEN` only because the row carried a NINTH cell against an 8-column header, so every reader read one column left of the truth. Three sessions in a row reported "none unblocked" and were wrong. Closed on evidence, not on its own say-so: `UserRideStatsService` exists and both former duplication sites delegate to it, `BoundaryDependencyTest` 9/36 OK at baseline 20, and both `sec 95` regex dismissals re-checked and still correct. **The corruption was wider than the scan found:** orders 35, 44, 45 and 64 had `Evidence` split by stray delimiters (64 had three), 110/111/112 had `ID` and `Title` transposed, 109 carried a literal pipe mid-sentence - and order 112 had **swallowed the entire section-3 heading into its last cell**, so `## 3. Commit verification` did not exist and rows 113-115 sat below a heading that should have ended the table. All repaired; sections now read 1-7 in order; rescan **116 rows, 0 mismatched**. **Pinned** by new `tests/Feature/Review/BacklogTableShapeTest.php` (6 tests / 133 assertions), scoped to the section-2 table after the first run fired on two other tables in the file, with a self-test proving the detector reports a 9- and a 10-cell row. **THREE NEEDLES, all caught by the right test, BACKLOG.md SHA256-identical after each.** Review floor 467 -> 473 tests (+6, mine), 1540 -> 1673 assertions, same 2 skipped / 1 incomplete: **zero regressions**. **The ratchet caught my own new row twice** - a literal pipe, then a literal heading pattern - and both times the fix was to reword the prose, not loosen the detector. I also reused `Order 113`, which AF-13 already had; renumbered to 116 (the second time I have made the mistake `sec 106` recorded).
  **WHY THIS MATTERS MORE THAN ITS SIZE:** the document that decides what work happens next was
  unreadable in the one place it is read from. Repairing the shape was not housekeeping.
- **AF-6 re-measured against its OWN acceptance criteria (`R2 sec 112`): the row is `PARTIAL`, and the lead handed over was a misreading.** No money code changed. Criterion 1 (`Money` everywhere): **NOT MET** - 5 files, ~20 of ~29 sites are `formatted()` - but the GOAL half is already met where it matters, since the 95/5 split routes through `FeeSplit` in integer minor units (`sec 71`). Criterion 2 (one vocabulary): **MET, as `LedgerType` not `LedgerEvent`**, pinned repo-wide by `RV39SeederHygieneTest`; its stated MECHANISM is REFUTED because `2026_10_03_233000` made the column varchar, so the ratchet holds the vocabulary and not the schema. Criterion 3 (`ledger:reconcile`): scheduled, but "proving `SUM(balances)`" is a refuted premise (it compares transactions to ledger legs, never reads `wallets.balance`) and "reports a real mismatch when injected" is **NOT PROVEN** - no test injects drift. Criterion 4: 6 of 7, RV-10 still `BLOCKED`. **Why the remainder is yours, specifically:** `Money` refuses negative amounts and the money paths are built on negatives (`postTwoPartyTransfer` emits `-amount`, `FeeSplit::releaseLegs` emits the negated escrow leg), so this is a signed-money DESIGN call, not a substitution. **The handoff claim that the 36 direct `WalletTransaction::create` sites are the work is wrong:** `LedgerService:19-26` says a converted path IS "write the single-sided row, then `postTransfer()`". **One real defect fixed:** `ReconcileLedgerCommand` told the operator the admin wallet paths were unconverted and that unexplained movement there was "EXPECTED" - false since `sec 57-60.1`; it would have had someone dismissing a real money-path defect as designed. Money floor 182 tests / 257 assertions / 3 failures, **identical by controlled bisect at the HEAD blob**, restore SHA256-identical, `pint --test` PASS.
  Backlog row 65 and the section 4 acceptance text are both corrected.
- **RV-20 CLOSED (`R2 sec 110`, VERIFIED FIX).** The refund half was not merely unwired: `EPayPaymentStrategy::processRefund` wrapped ONE booking in a one-element collection and called the **driver-cancellation** path, which refunds 100% - so a staff cancellation through it would have refunded one passenger in full instead of the policy amount. No production caller, which is the only reason it never cost money. Re-shaped to `(Ride, Collection, $reason)` per the owner choice; the reason selects between the two real set-level paths and an UNRECOGNISED reason **throws rather than defaulting** (defaulting to the driver path would over-refund on a drifted reason string, and RV-39 shows this vocabulary drifts). New `RV20RefundReasonRoutingTest` 10/17. NEEDLE restores the old behaviour and kills exactly the 5 reason-sensitive tests.
- **RV-19 CLOSED (`R2 sec 109`, VERIFIED FIX).** Admin `total_earnings` was `SUM(seats * rides.price_per_seat * 0.95)` re-derived from CURRENT ride rows, so editing a ride price after settlement rewrote what the driver appears to have earned. Now `ledger_earnings` (authoritative), `estimated_gross` (the same arithmetic, honestly relabelled a projection) and `total_earnings` as an ALIAS. **Owner said "ledger"; the source is `wallet_transactions`, because the 95/5 `postTransfer` legs carry no type discriminator, so `ledger_entries` structurally cannot tell earnings from top-ups - deviation recorded, not taken quietly.** Also: `RIDE_EARNING` is written only by the seeder (the app writes `ride_earnings`), so summing one spelling would return 0 in production; both are summed and pinned. Admin floor 23 failing -> 23 failing, zero new, 269 -> 273 tests. NEEDLE kills 7 of 8.
- **RV-42 (`R2 sec 107`): `tests/Feature/Review` is now fully GREEN - 457 tests, 0 failures** (was 5 at the start of this pass). `bookRide` refused with "The request could not be completed. Please try again." even when the domain refused on purpose, so a caller could not tell which rule stopped them (owner decision 11). Fixed with a dedicated `\InvalidArgumentException` branch; every writer in the ride/booking domain emits a curated user-facing sentence, and the technical ones live in unrelated services. **RV-13 still holds** - QueryException keeps the generic message. NEEDLED: 5 -> 4, zero new. **The controller is deliberately left UNCOMMITTED**: it is owner-owned and holds the owner's RV-38 eager-load work (confirmed intact at L72, L250, L305, L496), so landing it is the owner's call, not this task's.
- **RV-34 stale pin corrected + a duplicate ID of mine found and fixed (`R2 sec 106`).** `SharedTestSupportTest` asserted 2 system wallets while `SeedsSystemWallets` has seeded 3 since decision un3 added the EXTERNAL capital account (without it `AdminWalletService::chargeWallet` fails loudly). Stale pin, not a product bug - now derived from the trait's return value and asserting the external wallet's phone, which is stronger than before. **Sec 102 had reused `RV-34`, already in use at row 24; renamed to `RV-41` and the table now has no duplicate IDs.** Review floor 5 -> 4 -> 2 -> 1. The last one needs `RideController.php` (owner-owned, uncommitted RV-38 edits) - reported, not applied.
- **RV-39 ledger vocabulary resolved (`R2 sec 105`).** Owner decision: the four already-written `wallet_transactions.type` values (`external_inbound`, `external_outbound`, `staff_cancellation_refunds`/`_refund`) became first-class `LedgerType` cases rather than renames of existing ones - so **no data migration is needed** and every historical row stays valid. Kept distinct from ADMIN_CREDIT/ADMIN_CHARGE on purpose (external cash movement vs an operator adjustment - the same conflation T1-2 found in production), and the staff singular/plural pair mirrors the driver pair so reader logic applies unchanged. NEEDLED. Money floor 186 tests, 0 new. 2 red remain in Review.
- **RV-39 seeder orphaning fixed (`R2 sec 104`).** `SyrideSeeder::TRUNCATE_TABLES` truncated `wallets` and `wallet_transactions` but not `ledger_entries`, which has FKs into both - so every seeded run deleted the parents and left orphaned ledger rows plus stale balances behind. Added, children first. NEEDLED. **STOP-AND-ASK raised, not fixed:** the same file's other red test says five live call sites write `wallet_transactions.type` values (`external_inbound`, `external_outbound`, `staff_cancellation_refunds`/`_refund`) that are **not** `LedgerType` cases. That is ledger vocabulary and AGENTS.md reserves it for the owner.
- **RV-37 determinism ratchet fixed (`R2 sec 103`).** `TestDeterminismRatchetTest` was RED against RV-08's own test: `RV08ReplicaPortTest` called `putenv()`, which mutates the whole PHP process (the V14 order dependence: 53/55 failures). A probe proved the two channels the test already wrote (`$_SERVER`, `$_ENV`) are sufficient - but dropping the calls alone would let an unset var fall through to the ambient shell via Laravel's PutenvAdapter, trading a contamination bug for a non-determinism bug. So the adapter is disabled for the test with `Env::disablePutenv()`/`enablePutenv()` and nothing process-wide is mutated. NEEDLED. Review floor 5 -> 4 failures, zero regressions. 4 red remain there, none owner-gated.
- **RV-01 CLOSED + RV-41 P0 fixed (`R2 sec 102`).** `documents_disk` now DEFAULTS to the private `local` disk, so identity documents cannot leak on a fresh deploy, and `docker/start.sh` runs `kyc:migrate-disk --from=public --force` on every boot (idempotent, non-fatal) so the already-published files retire automatically. `StaffDocumentController` falls back to the legacy public disk READ-ONLY so nothing 404s; `uploads_disk` stays `public` on purpose. Also fixed: the migrator's `--from` default would have become a silent no-op after that change. **P0 found on the way (RV-34, new row 114 (RV-41)):** `database.connections.mysql.read.port` was an ARRAY - `host` may be one, `port` may not, and Laravel interpolates it into the DSN, so EVERY read failed with "Array to string conversion ... connection refused". Invisible to the suite because `RefreshDatabase` migrates first and `sticky => true` pins every query to the write PDO; `RV08ReplicaPortTest` had codified it by asserting `port[0]`. Bisect: 914 -> 926 tests, **zero new and 2 pre-existing errors fixed**. (newest first; detail is in the audit record)

- **AF-13 VERIFIED FIX (`R2 sec 101`) - `RideFactory` was unusable.** `DB::raw("ST_GeomFromText(...)")` is a `Query\Expression` but `Ride::setPickupLocationAttribute(array $coords)` type-hints `array`, so **every** `Ride::factory()->create()` died with a `TypeError`. Latent because nothing called it - `RV11RideCountTest:350` worked around it *in a comment*, so the cost was being paid invisibly. Fix passes named `['lat' =>, 'lng' =>]` arrays so the write goes through the mutator and `GeoPoint::fromLatLng()`. **Geometry unchanged**: `GeoPoint::wkt()` is `POINT(lat lng)` and the old literals were already in that order - the tests assert axis order and non-transposition, because RV-25 was caused by exactly the hand-rolled WKT rewrite this fix deliberately avoids. Bonus: `pickup_lat`/`pickup_lng` now populate, which the raw write skipped and which needed migration backfill. 6 tests / 17 assertions; NEEDLE restoring `DB::raw` reproduces the original `TypeError` verbatim, SHA256 restore; bisect 659 -> **665** tests with the same 2 errors / 10 failures, zero new. **And my first bisect was meaningless**: it deleted the new test and restored only the factory, so both runs showed 659. Tell: identical test counts. Rewritten, and bisects now self-check that the count actually moved.

- **I corrupted the BACKLOG column layout and repaired it - VERIFIED FIX, with two false starts of my own (`R2 sec 100`).** My row-edit scripts assumed columns `Status | Decisions | Blocked by | Evidence`, but the header is `Status | Blocked by | Aliases | Evidence` - **there is no Decisions column, so I was off by one and overwrote the real `Aliases` text of rows 19, 37, 64 and 69** with gate commentary, destroying the cross-references between tasks (the exact mechanism I use to find stale gates). Row 113 was written with 7 cells, missing `Aliases`, so it was invisible to positional reads. **Found by running the next-task rule and getting "none" while an obviously-qualifying `OPEN`/`none` row existed** - a rule returning none when one exists is a data fault, not a scheduling fact. Originals recovered from `git show e3493b4`, restored verbatim, never reconstructed. Two repair attempts were wrong before the third worked: one asserted every row had 8 columns and hit row 64 (pre-existing, 10), the next put row 64's aliases into `Blocked by`. **Lesson: never address a cell by a remembered index - read the header, and assert cell count before writing. `sec 65` already said this.**

- **RV-10: D2's first half was never built; the read-only stuck-escrow reporter now exists - VERIFIED FIX (`R2 sec 99`).** Gate read `AF-4e; T1-1`, both already closed - stale again. But the DECISION said "build the READ-ONLY stuck-escrow reporter first... window W unnamed - take it from the reporter data", and **no such command existed**, so W could not be answered. Added `escrow:stuck-report`. **The design decision: correlate escrow by MONEY MOVED (net `new_balance - previous_balance` per `reference = booking:{id}` on the SyCash wallet), never by settlement type name** - escrow leaves via payout/refund/time-based/no-show/cash-ride, and `LedgerType` documents that vocabulary already drifted three times; a type list would report every refunded booking as stuck forever. **NEEDLE:** a type-name correlation fails 5 tests including the refund case (the first needle was malformed - PowerShell quoting - so it was reported inconclusive and redone behind a `php -l` gate). Bisect 307 tests/13 failures -> 317/same 13, zero new. 10 tests / 32 assertions, pint PASS. Deleted a NULL-balance path the NOT NULL schema makes impossible and pinned that constraint instead. Age is measured from ride departure, not the receipt. **W is now a one-run owner decision.** Filed **row 113 / AF-13**: `RideFactory` is unusable (DB::raw geometry vs an array mutator) - latent, nothing calls it, and `RV11RideCountTest:350` works around it in a comment.

- **T1-3 reconnaissance: P0 FOUND - `JWT_SECRET` and `PUSHER_APP_SECRET` are BOTH in git history AND still the live values (`R2 sec 98`).** `.env` was tracked `6091b15`->`df9304c` and is now correctly untracked, so it is historical but permanently recoverable; `docker-compose.yml` carries literals across 13 revisions. Compared every historical literal against the live config: **`JWT_SECRET` signs every access token, so repo read access = token minting for any user**, and the `isset(ver)` guard does not stop it (the users migration defaults `token_version` to 1, a guessable value). `APP_KEY`/`MAIL_PASSWORD`/`DB_*` do NOT match - already rotated. **No production DB credential was leaked** (every historical `DB_HOST` is local/dev). A **PAT is also live in `origin`'s URL** right now - in `.git/config`, so history rewriting does not touch it. `filter-repo` scope: **244 commits, 4 refs**, force-push required = owner-only. Rotate FIRST, rewrite second.

- **RV-01 storage half: move tool built and verified; CORRECTION to my own pending list (`R2 sec 97`).** I told the owner decision 1b needed approving - **it was already implemented** (`routes/api.php:419-425`, shipped `066b1dd`). The real remainder was the action gate, and building it surfaced a hazard: `config/filesystems.php:114` recommends setting `DOCUMENTS_DISK=minio` as a one-line change, but `StaffDocumentController:85` reads through the same key, so **flipping it without moving the files 404s every staff KYC view.** Added `kyc:migrate-disk` (copy -> verify size -> delete source; idempotent; `--dry-run`; a row on neither disk is reported, not touched). 8 tests / 29 assertions, **NEEDLED twice** (source delete removed -> 2 tests fail; size mismatch forced -> 3 fail), SHA256 restores; floor bisect 131 tests / 10 failures -> 139 / same 10, zero new. Remaining steps are all owner actions: create the bucket, dry-run, run, set the env var.
- **STATE.md WORKING COPY WAS STALE, AND IT IS HEAD THAT IS CORRECT (`R2 sec 97`).** Four committed entries (sec 93-96) were missing from the working tree because my own STATE-editing scripts anchored on a task-name string instead of a section header, and a later "clear the in-progress block" step deleted whatever sat between the two headers. Restored with `git checkout HEAD -- docs/audit/STATE.md` (379 lines, all 40 bullets verified present). **Known, separate, NOT fixed here: the `In progress` block at L11-220 is malformed** - it holds ~209 lines of finished-task history that belong in `Done recently`. Attempting to relocate it dropped 24 real audit bullets on the first try, so it was reverted rather than half-done. That cleanup is its own task, not this one's.
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
- **RV-50 (row 125) is BLOCKED, not started (`R2 sec 154`).** The recorded ruling ("rename to `geocodeAddress()`, expect `[]` on no match, Syrian addresses") does not match the code. The test calls `geocode()`; the method is `geocodeAddress()`, so 15 of 17 tests fail. On a no match `geocodeEnglish` THROWS `Exception("No location found")` (`GeocodingService.php:112`) instead of returning `[]`, and `RideRepository:61,91` + `RideController:433,437` consume the result as an array. **No code changed.** Needs one owner decision: (1) change the service to return `[]` on a miss, which also requires the two callers to handle an empty array - behaviour change, needs its own explained plan; or (2) keep the throw and change the test to expect it, which contradicts the ruling as written. Recommend (2) if the throw is intended, (1) if a miss should be an empty result.
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
- AF series (APP_FUTURE_AUDIT.md section J): AF-5 needs an infra decision; **AF-7 is `VERIFIED FIX`** (`R2 sec 111`); **AF-7b is `VERIFIED FIX`** (`R2 sec 113`, was misfiled as `OPEN`); **AF-7c is `VERIFIED FIX`** (`R2 sec 113`); **AF-6 is `PARTIAL`** on a signed-money DESIGN call (`R2 sec 112`); T4-9 is an owner behaviour decision.

- **NEW `R2 sec 122` - four rows are BLOCKED and each needs one thing that is not code:**
  - **RV-10** - run `php artisan escrow:stuck-report` against PRODUCTION and name window W.
  - **RV-17** - a production-shaped host for the corrected load-test run.
  - **RV-29** - decision 5 (driver phone visibility), deferred by the owner.
  - **AF-6** - nothing of its own; it closes only when RV-10 does.
  - **RV-12** - **TWO SAME-DAY RULINGS CONFLICT (`R2 sec 122`):** `D7=A` (drop `status=0`, never built) vs `7ab1eff` (keep it, shipped, pinned by 4 tests). The owner must pick which governs.
  - **RV-42a (new row 117)** - what "suspended" is MEANT to mean: `status = 0` (logged out / unbanned) is counted as suspended in two admin services, while a third screen labels banned accounts suspended.

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

## Owner decisions 2026-10-12 (settled - do not re-litigate)
- **The six `PARTIAL` rows: "close what is done, BLOCK the rest."** The owner was told plainly that four cannot be VERIFIED FIX without an owner action or a production-shaped environment, and chose the honest re-status over marking them done (`R2 sec 122`).
- **Row 34 (RV-08): correct the stale `BLOCKED` status** (`R2 sec 121`).
- **RV-04b: in all five clusters THE APP IS CORRECT and the TEST is stale** - decisions (a)-(e) recorded in the In-progress block above.

## Owner decisions (settled - do not re-litigate)
- **Owner ruling 2026-10-12 (RV-46, settled - do not re-litigate):** the cash-ride creation-fee refund divergence is to be **marked for consideration and left unchanged**. `CashRideFeeServiceTest` stays red at 5 on purpose until the tier policy is decided; it is not a regression and must not be "fixed" by editing either side.
- **Owner ruling 2026-10-12 (RV-44, settled - do not re-litigate):** (1) refusals on `passenger-confirm` return **422**, following RV-13, rather than preserving the 400 the tests asserted; (2) scope is **this endpoint only** - the other 40 hard-coded 5xx catch-alls are not to be swept as part of this fix.

**2026-10-11 - four more answered. Settled, do not re-litigate.**
- **AF-6a - `ledger:reconcile` on per-wallet drift: FAIL the job.** APPLIED at `R2 sec 115`. Done, not pending.
- **AF-6 criterion 1 - signed money: WIDEN `Money` to signed** (do not add a second money type). **DONE - `R2 sec 116`
  (type) + `R2 sec 117` (sites). Criterion 1 is MET.** No longer a pending task; kept as the record of the ruling.
  The ctor throw moved to the two callers that actually had the rule (`FeeSplit::driverAndPlatform`,
  `AdminWalletService::chargeWallet`), and the raw decimal math at the 14 remaining sites is gone. AF-6 is
  now 3 of 4 criteria met; the last is RV-10, which is an owner ACTION (window W), not a decision.
- **RV-08 - the superseded VPS/compose files: KEEP them, do not delete; fix the known bug.** **DONE - `R2 sec 118`.**
  Both `exec -T app` calls now target the real service `app1`, and a ratchet pins that every compose target
  a workflow names is a service `docker-compose.yml` defines. The row still reads `BLOCKED` because its
  `Blocked by` column names the superseded deploy TARGET, not a code task. **The only approved, unstarted
  decision-free item left is RV-04c (`UserFactory` `token_version` 0 -> 1).**
- **RV-04c - `users.token_version`: the FACTORY is wrong, not the migration.** **DONE - `R2 sec 119`.**
  The factory now sets 1 to match the column default, and the parity test pins each table to its OWN
  schema default instead of asserting they match. **This was the last approved, unstarted, decision-free
  item of the 2026-10-11 batch; all four of those owner decisions are now fully carried out.**
  rotation-tracking work is to be scheduled for these two. (Values are never written here.)

## Owner decisions 2026-10-13 (settled - do not re-litigate)

- **RV-45 (row 120):** sweep ALL 36 service-wrapping `catch (\Throwable)` blocks in controllers. Rethrow domain errors as 422. Each affected test gets a justified update.
- **RV-46 (row 121):** refund tiers are GRADUATED 100/70/50/0, as the tests state. Changes money behaviour; implement with the money floor.
- **RV-48 (row 123):** keep the synthetic 3.0 signup ratings in the dashboard average. Update test expectations only.
- **RV-49 (row 124):** keep the `[RESOLVED by ...]` audit prefix. Update the test.
- **RV-50 (row 125):** keep the service and its Syria scope. Rename tests to `geocodeAddress()`, expect `[]` on no match, and use Syrian addresses.
- **RV-51 (row 126):** remove `ImageMessageType::process()` and its 4 tests. Deletion approved here.
- **T3-10 (row 91):** deploy target is Render. The RV-08 deletion approval is NOT given in this ruling and is still pending.

## Owner actions outstanding (not code)
- **Set `TRUSTED_PROXIES` in every real deployment (`R2 sec 130`, surfaced by V4).** `TrustProxies` reads it from `config/trustedproxy.php` and deliberately defaults to trusting NOBODY when unset - correct, because it cannot widen trust by accident. But "trust nobody" is the pre-fix behaviour: behind nginx every request then resolves to the proxy container, so every `ip:`-keyed rate-limit bucket collapses into ONE bucket shared by all users. The app works, rate limiting is globally shared, and nothing warns you. Set it to the nginx container/subnet CIDR (comma-separated). It reads through config, so `config:cache` cannot silently blank it.
- `phpunit.xml` - NEVER COMMIT IT (this rule still stands). **CORRECTED `R2 sec 108`:** the
  reason recorded here was wrong. It does NOT point at the Aiven database any more - it resolves to
  DB_HOST 127.0.0.1 with the local scratch password, i.e. the scratch database. The rule is kept
  anyway, because a config file carrying credentials does not belong in history regardless of how
  low-value those credentials are. Verified by inspection, not trusted; no value was printed.
- A failed connection may have written the Aiven password into `storage/logs/laravel.log`.
  Rotation is declined by the settled decision above; clearing the log is housekeeping only.
- T1-3 and T2-8: git-history purge remains open. The two burned test credentials are excluded
  from rotation by the settled decision above.

## Working tree rules
- Owner-owned, uncommitted by choice: `phpunit.xml`, `AGENTS.md` edits, `.gitignore`,
  and the 3 RV-38 eager-load edits in `app/Http/Controllers/API/RideController.php`.
- Local only: `AGENTS.local.md`. Scratch DB and env block: see `AGENTS.local.md`.
