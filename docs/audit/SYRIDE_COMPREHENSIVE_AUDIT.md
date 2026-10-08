# Syride — Comprehensive Bug, Security, Reliability & Correctness Audit

> **This file is the persistent source of truth for the Syride audit.**
> It contains two clearly separated parts:
> 1. **Part 1 — Original Audit Snapshot** (historical, preserved verbatim, never rewritten).
> 2. **Part 2 — Current Remediation State** (the repository's state *now*, maintained as tasks complete).
>
> Never reconstruct a missing finding from memory. If a task definition cannot be recovered
> from this file, stop and ask for it. Conversation context is supplemental; this file and Git
> history are authoritative.

---

## Progress Table

| Task | Status |
| --- | --- |
| T1-1 | VERIFIED FIX |
| T1-2 | VERIFIED FIX |
| T1-3 | BLOCKED — owner action required (rotation + history purge) |
| T2-1 | VERIFIED FIX |
| T2-2 | VERIFIED FIX (original premise partly DISPROVED — see section) |
| T2-3 | VERIFIED FIX (defect deeper than the original audit described) |
| T2-4 | VERIFIED FIX |
| T2-5 | VERIFIED FIX (2 audit premises corrected; dev convenience moved to an override file) |
| T2-6 | VERIFIED FIX |
| T2-7 | VERIFIED FIX (original mechanism imprecise; the real defect was the producer channel type) |
| T2-8 | VERIFIED FIX (escalated: committed literals are byte-identical to the live credential — owner rotation required) |
| T2-9 | VERIFIED FIX (confirmed exactly; two parity gates added that the fix newly exposed) |
| T2-10 | VERIFIED FIX (confirmed exactly; identity bucket added alongside IP, not replacing it) |
| T2-11 | VERIFIED FIX (confirmed exactly; legacy rows purged, not re-hashed — deliberate) |
| T2-12 | VERIFIED FIX (http_only restored + credentialed CORS off by default; audit's 'encrypt' premise DISPROVED) |
| T3-1 | VERIFIED FIX (confirmed; UUID id, the collision reproduced live at 500 pre-fix) |
| T3-2 | VERIFIED FIX (money columns standardised to decimal(15,2); defaults preserved) |
| T3-5 | VERIFIED FIX (3 dup indexes collapsed + 2 more found live during T3-6; driver-agnostic) |
| T3-6 | VERIFIED FIX (7 MySQL-only migrations driver-guarded; MySQL-in-CI is the durable half) |
| T3-7 | VERIFIED FIX (dead stub + misleading test deleted, not repaired) |
| T3-8 | VERIFIED FIX (broken seeder deleted; it created a null-email/null-password user) |
| T3-9 | VERIFIED FIX (no committed passwords in seeders/k6; trait + env + random-by-default) |
| T3-11 | VERIFIED FIX (CI fails on test failure; Sonar action pinned to SHA; JWT secret generated) |
| T3-12 | VERIFIED FIX (docs gated by env/IP; generated spec untracked + gitignored) |
| T3-13 | VERIFIED FIX (27 silent catches now Log::warning; 2 cache sites annotated + pinned) |
| T3-14 | VERIFIED FIX (sync-queue boot guard, mirroring the T2-8 pusher guard) |
| T3-15 | VERIFIED FIX (withdraw check moved inside txn + wallet row locked FOR UPDATE) |
| T3-16 | VERIFIED FIX (report re-locked + status re-checked before any money moves) |
| T3-3 | DEFERRED (owner: leave for later) |
| T3-4 | NOT STARTED (contradicts the owner's T2-1 decision; awaiting a change of mind) |
| T3-10 | NOT STARTED (deploy automation; entangled with owner-owned T1-3) |
| T3-17 | DEFERRED (owner: leave for later) |
| T4-1 | VERIFIED FIX (global throttle opt-out removed; coverage blind spots closed; exposed + fixed a real Header bug) |
| T4-2 | VERIFIED FIX (WKT built in PHP and bound; wrong Builder type-hint removed too) |
| T4-3 | VERIFIED FIX (DELETE group parenthesised; SQL unchanged for today's callers) |
| T4-4 | VERIFIED FIX (both token families read config; staff default preserves 3600 s) |
| T4-5 | NOT STARTED — ROLLED BACK (deferred on owner instruction; hazard proven) |
| T4-6 | VERIFIED FIX (dead artifacts deleted, misfiled test relocated, k6 names cleaned) |
| T4-7 | VERIFIED FIX (no-op job and success-reporting upload endpoint removed) |
| **Next** | see docs/audit/STATE.md |

Last updated: after the T4 batch (T4-2/3/4/6/7) reached VERIFIED FIX. T1-3 remains BLOCKED; T4-5 rolled back on owner instruction.

### Overall audit completion status

**33 of 39 findings closed. 6 remain: T1-3 (blocked, owner), T3-3 + T3-17 (deferred by owner), T3-4 + T3-10 (not started), T4-5 (rolled back on owner instruction). The audit is NOT finished.**

| Tier | Total | Closed | Remaining |
| --- | --- | --- | --- |
| T1 | 3 | 2 VERIFIED FIX + 1 BLOCKED (owner action) | 0 |
| T2 | 12 | 12 (T2-1 … T2-12) | 0 — complete |
| T3 | 17 | 13 (batch: T3-1,2,5,6,7,8,9,11,12,13,14,15,16) | 4 — T3-3, T3-4, T3-10, T3-17 |
| T4 | 7 | 6 (T4-1..4, T4-6, T4-7) | 1 — T4-5 (rolled back) |

Closed: T1-1, T1-2 (VERIFIED FIX), T1-3 (BLOCKED — owner action required), T2-1, T2-2, T2-3, T2-4,
T2-5, T2-6, T2-7, T2-8, T2-9, T2-10, T2-11, T2-12 (VERIFIED FIX).
Tier 3: 13 closed by the batch (T3-1,2,5,6,7,8,9,11,12,13,14,15,16 VERIFIED FIX); T3-3/T3-17 DEFERRED by owner request; T3-4/T3-10 NOT STARTED (see Part 2 sections).

T1-3 is **not** closed: it cannot be finished by code changes alone. It needs credential rotation, a
Git-history purge, and revocation of the GitHub PAT embedded in `.git/config`, all of which are owner
actions. See Part 2.

The 39 findings above are the complete original set defined in Part 1. Nothing has been silently
dropped: a finding that was disproved or partially wrong is recorded as such in Part 2 and the original
text is preserved verbatim.

---

# Part 1 — Original Audit Snapshot

*The following is the original audit exactly as delivered. It is a historical snapshot and is
deliberately **not** corrected or rewritten, even where later remediation work disproved parts of
it. Corrections live in Part 2.*

## Syride — Comprehensive Bug, Security, Reliability & Correctness Audit

Read-only. No file was created, modified, deleted, installed, migrated, or deployed. Every finding below was traced through actual code paths; where a path was not traced to its end I say so explicitly.

Token accounting (read at report time): uncached input 1,021,778 · output 138,599 · cache read 15,528,192 · cache write 0 · total ≈ 16,688,569. Live context surfaceTokens 153,605 / 262,144 = 58.6%. Provider free-tier remaining allowance is not observable from inside this harness (no quota endpoint; the API key is an env secret I did not read).

## A. Executive Summary

Syride is a Laravel 10 / PHP 8.2 ride-sharing API (RoadRunner + Octane, MySQL read/write split, Redis, Horizon) with a substantial and largely well-structured codebase. The wallet/escrow domain in particular shows clear intent: explicit refund-policy tiers, row-level locking, idempotency keys on booking, and deliberate comments explaining prior fixes. The test suite is broad and includes genuine IDOR assertions.

That said, this audit found two independent, confirmed defects that each stop the core E-PAY money flow from completing, plus a cluster of authorization, secret-management, and configuration problems that are serious but narrower. The most important conclusion is that the primary revenue path — passenger pays into escrow, driver gets paid on ride completion — cannot complete as written, for two separate reasons that would each have to be fixed:

The ride-completion state machine is mutually exclusive with itself — the driver's "finish" action puts the ride into a state that passengers are then forbidden from confirming, and the passenger's confirmation is only accepted in states the driver's "finish" rejects. Escrow becomes stranded.
Even if that were fixed, every escrow ledger write uses a type value that does not exist in the database enum, and strict mode is on — so the insert throws and the money movement rolls back.
These are not speculation: both are traceable through specific lines, and the second is corroborated by a second, independent migration that defines a different enum for the same column.

Beyond the money path, the highest-value problems are: a genuine admin-identity loss (admin audit fields always null) caused by middleware asymmetry; an OTP brute-force cap that is defined but never enforced; a signup path that lets an unverified account's password be overwritten without proving mailbox ownership; and a deployment configuration that ships literal database credentials and mounts the working tree over the built image.

Several things I checked and found sound, which I want to record so they are not re-litigated: no update($request->all()) / create($request->all()) mass-assignment sinks exist anywhere in app/ (grep: zero matches); no raw-SQL string interpolation of user input exists (all whereRaw/selectRaw use bound parameters or constant SQL); refresh_tokens are correctly SHA-256 hashed; SpecialAccountSeeder correctly hard-stops when its env credentials are missing; the scheduled noshow:resolve command correctly uses onOneServer() + withoutOverlapping(); and BookingService locks the ride row before the seat-availability check, so the seat race is genuinely handled.

## B. Findings

### TIER 1 — CRITICAL

#### T1-1 — Ride-completion state machine is self-contradictory; E-PAY escrow can never be released

Tier: 1
Severity & why: Critical. This is the primary money path. Escrow funds are debited from passengers and held indefinitely with no payout path, and rides can never reach a legitimate terminal state through the documented two-party confirmation flow. It is both a financial-integrity failure and a total failure of a critical workflow.
File / lines:
app/Services/Ride/RideService.php:320 (sets awaiting_confirmation)
app/Services/Ride/RideService.php:352 (finishRide precondition: ACTIVE/FULL)
app/Services/Ride/RideService.php:397 (checkAndCompleteRide requires awaiting_confirmation)
app/Services/Ride/RideService.php:402-418 (requires driver_confirmed_at and every booking's passenger_confirmed_at)
app/Services/Ride/BookingService.php:496-503 (passenger confirm: only ACTIVE/FULL → LAUNCHED, or else LAUNCHED)
app/Models/Booking.php:87 (markPassengerConfirmed() — never called)
app/Enums/RideStatus.php:58-61 (isConfirmable() — defined, never called)
What the code currently does: finishRide() Case B sets the ride to awaiting_confirmation. passengerConfirmCompletion() accepts a confirmation only if the ride is active/full (which it then flips to launched) or already launched — anything else throws. awaiting_confirmation is neither, so it throws "This ride cannot be confirmed". Conversely, if a passenger confirms first, the ride becomes launched, and the driver's finishRide() then fails its ACTIVE/FULL precondition. checkAndCompleteRide() — the only caller of the actual escrow release releaseEarningsToDriver() — sits behind a passenger_confirmed_at gate that no endpoint can ever set.
Why it is a problem: The two entry points guard on disjoint state sets. Whichever party acts first permanently blocks the other. The markPassengerConfirmed() model method and RideStatus::isConfirmable() helper both exist and are never referenced anywhere, which is strong evidence the intended linkage was written and then orphaned.
Real-world impact: Passengers are debited at booking; the driver is never paid; checkAndCompleteRide() takes its early-return at line 415-417 every time. Escrow accumulates in the SyCash wallet with no release. Every multi-party E-PAY ride requires manual intervention.
Trigger: Ordinary use. Driver taps "finish", then any passenger taps "confirm" → 422. Or passenger confirms then driver taps "finish" → 422.
Recommended fix direction (not implemented): Unify on one state machine. Either have finishRide() set LAUNCHED (not awaiting_confirmation) and make passengerConfirmCompletion() set passenger_confirmed_at, letting checkAndCompleteRide() do the single release; or delete the per-booking releaseEscrowToDriver() path so exactly one release site exists. Whichever is chosen, make the precondition sets provably identical. Also delete or wire up markPassengerConfirmed() and isConfirmable().
Confidence: High
Confirmed or potential: Confirmed bug (static trace complete; not executed, since tests would not have caught it — see T4-1).

#### T1-2 — Every escrow/driver-payout ledger write uses a type value absent from the database enum, and strict mode makes it fatal

Tier: 1
Severity & why: Critical. Money movement inserts throw under MySQL strict mode, so each affected operation rolls back mid-transaction. Booking charges, escrow releases, no-show settlements, and cash-ride fee accounting all fail.
File / lines:
Enum definition: database/migrations/2025_07_18_212423_update_type_enum_on_wallet_transactions.php:14-50
Column origin: database/migrations/2025_07_17_153007_create_wallet_transactions_table.php:18
Strict mode: config/database.php:67 ('strict' => true)
Offending writes: app/Services/Payment/WalletTransactionService.php:111 escrow_received; :178 escrow_released; :192 ride_earnings; :206 platform_fee; :492 passenger_no_show_settlement; :505 passenger_no_show_earning; :518 platform_fee; :569/:582 driver_no_show_refund; :742 escrow_release; :756 ride_earning; :770 platform_fee; app/Services/Payment/CashRideFeeService.php:172,208,222,295,327,376,390,462,475; app/Http/Controllers/API/PassengerProfileController.php:341 admin_charge; app/Http/Controllers/API/AdminWalletRequestController.php:133,141 withdrawal/admin_charge
What the code currently does: The migration list contains ride_earnings (plural) but the release path writes ride_earning (singular) — an off-by-one-character mismatch at :756. Beyond that, the enum has no entry at all for escrow_received, escrow_release/escrow_released, platform_fee, passenger_no_show_*, driver_no_show_refund, cash_ride_*, admin_charge, or withdrawal. Migrations on this column are the create (narrow 4-value enum), this widening, 2026_04_30_145538 (only makes user_id nullable), and 2026_08_21_000028 (only indexes).
Why it is a problem: With strict => true, MySQL turns a truncated enum insert into an error rather than a silent coercion, so WalletTransaction::create() throws SQLSTATE[01000] Data truncated for column 'type'. chargePassengerForBooking() writes the passenger debit and the SyCash credit in the same transaction — the second insert uses escrow_received, so the whole booking charge rolls back.
Real-world impact: E-PAY bookings cannot be paid for at all; completion payouts, no-show settlements and cash-ride fees cannot be recorded. Every failure surfaces as a generic 500 to the client.
Trigger: Any E-PAY booking, or any escrow release / no-show / cash-fee operation.
Recommended fix direction (not implemented): Choose one authoritative representation — either replace the enum with VARCHAR plus application-level validation (most robust, and makes future type additions migration-free), or introduce a single migration that widens the enum to the full set actually used and standardise the singular/plural naming. Add an enum-backed PHP constant or enum class so writers and schema cannot drift again, and add a test asserting every emitted type is schema-valid.
Confidence: High
Confirmed or potential: Confirmed bug (schema and writers both read directly; the enum list is exhaustive and the mismatches are literal).

#### T1-3 — Secrets are committed in git history and remain recoverable

Tier: 1
Severity & why: Critical. Live credential material is reachable by anyone with repository read access, and it is not remediated by the pending staged deletion.
File / lines: git ls-tree -r HEAD contains config/firebase-credentials.json and dump.sql. git diff --cached --stat shows them staged for deletion (-13 / -1110 lines). .gitignore correctly lists both.
What the code currently does: Both files were committed historically and remain in HEAD and ancestor commits. dump.sql (≈3.9 MB) is a database dump; the other is a service-account credential file.
Why it is a problem: Staging a deletion removes the files only from future commits. The blobs stay in history and are retrievable with git show. If either has ever been pushed to a shared remote, the secret must be treated as permanently disclosed.
Real-world impact: A database dump exposes user rows and, depending on its contents, password hashes and PII; a service-account file grants programmatic access to the Firebase project. Both are long-lived credentials.
Trigger: Anyone with clone access runs git log --all -- config/firebase-credentials.json or git show <commit>:dump.sql.
Recommended fix direction (not implemented): Rotate both credentials first — history rewriting is worthless until the leaked material is invalidated. Then purge history (e.g. git filter-repo) and force-push, coordinate with all clones, and add a pre-commit secret scanner plus a CI gitleaks/trufflehog gate. Note the standing AGENTS.md carve-out for the Firebase item; I am reporting it because this request explicitly asked for full-configuration coverage.
Confidence: High
Confirmed or potential: Confirmed bug. Secret values were never opened, printed, or referenced by content — only by path.

### TIER 2 — HIGH

#### T2-1 — Admin identity is always null on every admin write, so audit fields are never recorded

Tier: 2
Severity & why: High. Financial and moderation actions lose their actor attribution, which destroys the audit trail for exactly the operations that most need one.
File / lines:
app/Http/Middleware/StaffJwtMiddleware.php:79-82 — sets only $request->attributes->set('staffEmployee', ...); no setUserResolver()
Contrast app/Http/Middleware/AdminJwtMiddleware.php:91 and app/Http/Middleware/JwtAuthMiddleware.php:90,111, which do call setUserResolver()
app/Http/Controllers/API/AdminWalletRequestController.php:165 and :271 — 'processed_by' => $request->user()?->id
app/Http/Controllers/API/PassengerProfileController.php:340 and :353 — 'user_id' => $request->user()?->id, logged as admin_id
What the code currently does: All /api/admin/* routes are guarded by staff:... (T2-2). That middleware never binds a user resolver, and the default guard is web (session, config/auth.php:17), so $request->user() returns null on a token-authenticated API request. The null-safe ?->id and the nullable FK (2026_05_17_012515:20) mean this fails silently rather than erroring.
Why it is a problem: Two independent representations of "the admin" exist — attributes['staffEmployee'] (used correctly by AdminAuthService::getAdminConfigFromRequest()) and $request->user() (null). Every call site that picked the second is silently broken. The ?-> and the nullable column convert a logic error into a data gap.
Real-world impact: wallet_requests.processed_by is permanently null for approved and rejected requests; wallet transactions carry no acting admin. Reconciliation and dispute investigation become guesswork. If a user token were ever accepted on these routes, the same expression would record a user id as the processing admin.
Trigger: Every admin approval, rejection, or manual wallet charge.
Recommended fix direction (not implemented): Make StaffJwtMiddleware call setUserResolver(fn () => $employee) as AdminJwtMiddleware does, and standardise all admin controllers on one accessor. Consider a DB-level NOT NULL on processed_by for terminal states, or at least require it in the service layer, so a missing actor cannot pass unnoticed.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-2 — The admin-panel role gate contradicts the token issuer; the financial-admin role is locked out

Tier: 2
Severity & why: High. A documented admin role cannot use the admin panel at all, and a second role can authenticate but is forbidden by middleware. Breaks the intended separation of duties.
File / lines:
routes/api.php:289 — Route::middleware(['staff:admin,system_admin', 'throttle:admin']) wraps the whole admin surface
app/Http/Middleware/StaffJwtMiddleware.php:75,87-93 — strict membership in ['admin','system_admin']
app/Services/Admin/AdminAuthService.php:44-59 — login succeeds only if $employee->role->isAdminRole()
app/Enums/StaffRole.php:48-51 — isAdminRole() = [SYSTEM_ADMIN, SYCASH]
app/Http/Kernel.php:51 — auth.admin alias registered but referenced by zero routes
What the code currently does: sycash can authenticate (it passes isAdminRole()) but is then 403'd by the route middleware, which does not list it. admin passes the middleware but can never obtain a token, since isAdminRole() excludes it. The two sets {admin, system_admin} and {system_admin, sycash} intersect only at system_admin.
Why it is a problem: Two components independently define "admin" and disagree. Fail-closed, so this is a lockout rather than a privilege escalation — but it silently removes the financial administrator from the financial-approval workflow, and the extensive controller docblocks asserting auth.admin describe a guard that is not used at all.
Real-world impact: Every wallet-request approval, wallet charge, and financial report is restricted to system_admin, defeating the intended sycash financial role. AdminJwtMiddleware is dead code kept alive only by its own class file.
Trigger: Log in as sycash and call any /api/admin/* route → 403 FORBIDDEN.
Recommended fix direction (not implemented): Define the allowed role set once (a StaffRole constant or config) and consume it from both the route definition and isAdminRole(). Since sycash is deliberately financial-only, consider genuinely differentiated route groups (staff:system_admin vs staff:system_admin,sycash) rather than one broad group, and either remove AdminJwtMiddleware or route panelling through it.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-3 — OTP attempt limit is defined but never enforced; codes are brute-forceable for their full 10-minute lifetime

Tier: 2
Severity & why: High. Turns a 6-digit secret into an unthrottled guessable value, enabling account/wallet takeover via the password-reset OTP flow.
File / lines:
app/Models/Otp.php:59-62 — incrementAttempts() defined
app/Models/Otp.php:42 and :79 — validity and active() scope both gate on attempts < 3
app/Services/EmailOtpService.php:80-109 and app/Services/WhatsAppOtpService.php:103-133 — verifyOtp() checks isValid() but never calls incrementAttempts()
app/Repositories/OtpRepository.php:30-39 — lookup via active()
routes/api.php:98-110 — only throttle:auth
What the code currently does: attempts is written as 0 at creation and never incremented anywhere in the codebase (grep for incrementAttempts returns only the definition). The attempts < 3 guard therefore always passes.
Why it is a problem: A per-code guess counter is the only thing making a 6-digit OTP meaningful. Without it, the sole control is the route limiter, which keys on IP and is shared behind NAT.
Real-world impact: An attacker who knows a victim's email can drive /api/password/verify-otp up to the rate limit for ten minutes and, on success, receives a reset_token that is accepted by /api/password/reset — a full account takeover without mailbox access. Note this flow bypasses the email-ownership assumption that the OTP was meant to establish.
Trigger: POST /api/password/forgot then repeated POST /api/password/verify-otp with sequential codes.
Recommended fix direction (not implemented): Call incrementAttempts() on every failed verification, before returning, and invalidate the code once the cap is hit. Prefer a per-identifier attempt counter with a lockout window over a per-row counter, and consider extending the code length or making it single-use-per-session.
Confidence: High
Confirmed or potential: Confirmed bug (dead enforcement). Actual exploitability depends on the limiter's true effective rate — see T2-10.

#### T2-4 — Signup allows an unverified account's password to be overwritten without proof of email ownership

Tier: 2
Severity & why: High. Pre-account-takeover of any user who has registered but not yet verified.
File / lines: app/Http/Controllers/API/SignupController.php:57-97 (specifically :68-71); OTP echo at :93-95 and :151-153
What the code currently does: If the submitted email exists and email_verified_at === null, the controller overwrites that account's password with the attacker-supplied value and emails a fresh OTP — without any proof the requester controls the mailbox. It also returns the account's id, first_name and email. When the OTP service is in non-production mode, otp_code is included in the JSON response.
Why it is a problem: The flow treats "unverified row exists" as licence to change credentials. The attacker does not need to read the OTP email to have already changed the password; and because the response echoes the OTP in non-production mode, an environment misconfiguration converts this directly into takeover.
Real-world impact: Any user who abandoned registration can have their password set by a third party. The victim's own later login attempt fails, and the attacker's chosen password is the live one.
Trigger: POST /api/auth/signup with a known unverified email and a chosen password.
Recommended fix direction (not implemented): Do not treat password mutation as part of "resend OTP". Return a neutral "check your email" response, keep the existing password, and require the OTP-verified reset flow to change it. Never echo OTP codes in API responses; if a dev affordance is needed, gate it on app()->environment('local') and log-only.
Confidence: High
Confirmed or potential: Confirmed bug for the password overwrite; the OTP echo is confirmed code but its exploitability is conditional on EMAIL_OTP_MODE.

#### T2-5 — Production deploy configuration ships literal database credentials, exposes phpMyAdmin, and mounts the source tree over the built image

Tier: 2
Severity & why: High. Weak, known credential combined with an unauthenticated-ish DB console and a deployment that does not actually deploy the artifact it builds.
File / lines: docker-compose.yml:16,51,85,120,155,201,207,222,241 (DB_PASSWORD/MYSQL_ROOT_PASSWORD = "secret", and -psecret in the healthcheck); :22-27,56-61,90-95,124-129,159-164 (bind-mounts of ./app, ./config, ./routes, ./database); :232-241 (phpMyAdmin published on 8081 with the same root password); app/Console/Commands/Getloadtesttokens.php:159 (instructs mysql -uroot -psecret)
What the code currently does: The root password is the literal string secret across the MySQL primary, replica, phpMyAdmin and app services. phpMyAdmin is bound to a host port with that password. Each of the five app replicas bind-mounts the developer working tree over the image's /var/www/html.
Why it is a problem: A root DB password of secret is trivially guessable, and phpMyAdmin gives an interactive SQL console once it is. The bind-mounts mean the multi-stage --no-dev build in Dockerfile is discarded at runtime, so the container runs whatever is on the host — including any untracked file — which makes the build non-reproducible and undermines the security posture of the image entirely.
Real-world impact: Network-adjacent compromise of the database; non-reproducible deployments where the running code differs from the committed code; phpMyAdmin on public IPs exposes the full dataset.
Trigger: Reach port 3306 or 8081 and log in as root with secret.
Recommended fix direction (not implemented): Move all credentials to env/secret injection with no literal defaults; do not publish MySQL or phpMyAdmin ports; remove the application-source bind-mounts from production (keep them only in an override file for local dev) so the image is what runs; require TLS to the DB.
Confidence: High
Confirmed or potential: Confirmed configuration defect.

#### T2-6 — Unauthenticated infrastructure disclosure and exception-message leakage on health/debug endpoints

Tier: 2
Severity & why: High. Unauthenticated reconnaissance of internal topology and database errors.
File / lines: routes/api.php:64-92 (/api/test-db returns DB name, host and table count; the code's own comment at :65 says to remove it before production); routes/api.php:452 (/api/health returns gethostname()); routes/web.php:9-17 (/up returns 'Database unavailable: ' . $e->getMessage()); render.yaml:5 (healthcheck path is /up)
What the code currently does: /api/test-db is registered with no auth and no throttle and returns internal DB coordinates. /up leaks the raw DB exception text on failure and is the platform healthcheck, so it is publicly reachable by design.
Why it is a problem: /api/test-db confirms liveness and names the backing store; /up can disclose driver names, hostnames, and credential-adjacent error strings. Combined with the five-node topology, gethostname() assists cluster enumeration.
Real-world impact: Reduces attacker effort materially — a footing for targeted attacks against a known managed-MySQL provider.
Trigger: Unauthenticated GET /api/test-db, GET /api/health, and GET /up during a DB outage.
Recommended fix direction (not implemented): Delete /api/test-db and /api/health (or restrict to an internal network / authenticated admin). /up should return a bare status code and log the exception server-side; the healthcheck only needs 200 vs 500.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-7 — The public broadcast channel authorizes everyone

Tier: 2
Severity & why: High. Unauthenticated real-time subscription to ride data.
File / lines: routes/channels.php:23-25 — Broadcast::channel('rides', fn () => true); producers at app/Events/RideCreated.php:27 and app/Events/RideCancelled.php:36
What the code currently does: Unlike the three sibling channels (all of which correctly compare ids or check isParticipant), the rides channel returns unconditional true.
Why it is a problem: The authorization callback is the only access control on a private/presence channel; returning true removes it. Any client holding the (also-defaulted — T2-8) Pusher key can subscribe.
Real-world impact: Live leakage of ride creation and cancellation streams, including passenger and driver identity fields carried in the broadcast payload, to unauthenticated third parties.
Trigger: Subscribe to the rides channel with the public app key.
Recommended fix direction (not implemented): Make the channel private and authorize against an actual relationship or role (driver/passenger on the ride, or authenticated user); remove any passenger-identifying data from the payload if the channel must stay broad.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-8 — Pusher credentials are committed as in-code fallback defaults

Tier: 2
Severity & why: High. A real-looking broadcast credential is baked into a tracked config file, so losing env config silently falls back to a fixed key rather than failing.
File / lines: config/broadcasting.php:9-11
What the code currently does: key, secret and app_id are literal strings rather than env() calls with no default.
Why it is a problem: Unlike the rest of the config (which uses env(...)), these are hardcoded, so the app has a working-but-shared credential even when the environment is misconfigured, and the value is in version control.
Real-world impact: Combined with T2-7, an attacker can read the committed key and subscribe to the unauthenticated channel without any reconnaissance.
Trigger: Read the repository; or simply let a misconfigured deploy fall back to the default.
Recommended fix direction (not implemented): Convert to env('PUSHER_APP_KEY') etc. with no default, rotate the exposed credential, and fail fast at boot if broadcasting is enabled without configuration.
Confidence: High
Confirmed or potential: Confirmed bug (values not reproduced here).

#### T2-9 — Google OAuth issues a Sanctum token that no protected route accepts

Tier: 2
Severity & why: High. A documented authentication path is entirely non-functional, and it introduces a second, unused token system.
File / lines: app/Http/Controllers/API/Auth/GoogleController.php:111 ($user->createToken(...)); routes/api.php:143 (all protected routes use jwt); app/Http/Kernel.php (jwt → JwtAuthMiddleware); app/Http/Middleware/JwtAuthMiddleware.php:26-39 (expects a Bearer token decoded by JwtService)
What the code currently does: The OAuth callback returns a Sanctum personal access token. Every protected endpoint is guarded by the custom jwt middleware, which decodes with JwtService and requires type === 'access'. A Sanctum token has none of those claims. User uses HasApiTokens, so the token is created successfully — and then rejected everywhere.
Why it is a problem: Two parallel token systems coexist with no bridge. The failure is silent at login (a token is returned) and only appears on the next request as a 401.
Real-world impact: Users who sign in with Google receive a token that immediately fails; the feature is broken end-to-end. The orphaned personal_access_tokens table also grows unchecked.
Trigger: Complete the Google OAuth flow, then call any /api/* protected route with the returned token → 401 TOKEN_INVALID.
Recommended fix direction (not implemented): Issue a JwtService access/refresh pair from the Google callback (as LoginController does) and drop createToken, or formally adopt Sanctum and migrate all guard definitions. Do not leave both.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-10 — Rate limiters key on user-or-IP, so every public auth endpoint is IP-keyed only — and the config file is duplicated

Tier: 2
Severity & why: High. Weakens brute-force protection on login and OTP (compounding T2-3), and the duplicate config file means edits may silently not take effect.
File / lines: app/Providers/RouteServiceProvider.php:31-32,40 (->by($request->user()?->id ?: $request->ip())); config/rate-limiting.php and config/rate_limiting.php (byte-identical, 37 lines each)
What the code currently does: The limiter key resolves the authenticated user's id, falling back to IP. On the public groups (routes/api.php:98-137: OTP send/verify, login, refresh, password reset, signup) no user is resolved, so every limiter keys purely by IP. Meanwhile the app loads rate-limiting (hyphen) while an identical rate_limiting (underscore) file sits unused.
Why it is a problem: All clients behind one NAT or carrier gateway share a bucket — a denial-of-service vector against legitimate users, and simultaneously weak protection against an attacker rotating IPv6 addresses, since each address gets a fresh allowance. The duplicate file is a trap: an operator editing the underscore version changes nothing.
Real-world impact: Login and OTP brute-force limits are both bypassable by address rotation, and the OTP attempt cap that should compensate is dead (T2-3). Legitimate shared-IP users are throttled out.
Trigger: Rotate source addresses against /api/auth/login or /api/password/verify-otp; or a single NAT egress exhausting throttle:auth (5/min) for a whole network.
Recommended fix direction (not implemented): For unauthenticated endpoints, key on a stable identifier — normalised email/phone plus IP — so per-account and per-address limits both apply. Delete the duplicate config file so exactly one source of truth exists. Consider progressive delays on repeated failures.
Confidence: High
Confirmed or potential: Confirmed configuration/design bug.

#### T2-11 — Staff refresh tokens are stored in plaintext while user refresh tokens are hashed

Tier: 2
Severity & why: High. Any database read yields directly usable admin/staff session credentials.
File / lines:
Hashed: app/Services/JwtService.php:281 — hash('sha256', $tokenString), looked up by hash at :125
Plaintext: app/Services/Staff/StaffJwtService.php:149-153 — stores Str::random(64) raw; :82 looks up StaffRefreshToken::where('token', $refreshToken)
Both sign with the same secret: StaffJwtService.php:163-179 returns the raw jwt.secret
What the code currently does: The staff path stores the refresh token verbatim and compares by equality; the user path stores only a SHA-256 digest.
Why it is a problem: Refresh tokens are bearer credentials that mint new access tokens. Hashed storage means a database disclosure does not directly yield sessions; plaintext storage means it does. The inconsistency is a strong sign this was an oversight rather than a decision.
Real-world impact: A leaked dump, replica read, backup, or phpMyAdmin session (T2-5) grants an attacker long-lived admin access without cracking anything. This materially raises the impact of T1-3.
Trigger: Read staff_refresh_tokens.token and present it to /api/admin/refresh.
Recommended fix direction (not implemented): Hash on write and look up by digest, mirroring JwtService. Add a one-time migration hashing existing rows (invalidating outstanding staff sessions) and consider separate signing secrets per token class so a leak in one system cannot forge the other.
Confidence: High
Confirmed or potential: Confirmed bug.

#### T2-12 — Session cookies are exposed to JavaScript and CORS allows credentials with wildcards

Tier: 2
Severity & why: High. Removes the primary XSS mitigation for session cookies.
File / lines: config/session.php:82-84 ('http_only' => false — comment says it was changed from true "for Flutter web access"; 'secure' defaults false); config/session.php:26 ('encrypt' => false); config/cors.php:15,18,20-26,48 (leftover session-debug path, allowed_methods => ['*'], localhost-only origins, supports_credentials => true); config/auth.php:17 (default guard is web/session)
What the code currently does: Session cookies are readable by client-side script and may travel over plaintext. Credentialed CORS is enabled against an explicitly enumerated localhost origin list, and session routes exist (routes/web.php:22-26).
Why it is a problem: http_only = false means any XSS escalates from script execution to session theft. A localhost origin with supports_credentials is dangerous on developer or shared machines, since any process able to bind that port can make credentialed requests.
Real-world impact: Token/session theft via any injected script; on a compromised localhost, credentialed cross-origin requests against the session guard.
Trigger: Any XSS sink reading document.cookie, or a malicious local service on an allowed origin.
Recommended fix direction (not implemented): Restore http_only = true and force secure = true; solve the Flutter client with a token-based flow rather than by weakening cookie flags. In CORS, drop supports_credentials unless genuinely required, and remove the session-debug path.
Confidence: High
Confirmed or potential: Confirmed configuration defect.

### TIER 3 — MEDIUM

#### T3-1 — chargeWallet mints its transaction id from a timestamp, so two charges in the same second collide

Tier: 3 | File: app/Http/Controllers/API/PassengerProfileController.php:346 ('transaction_id' => 'ADM-' . $user->id . '-' . now()->timestamp), against the UNIQUE index at 2026_..._create_wallet_transactions_table.php:23; the same pattern at AdminWalletRequestController.php:157.
Currently: The id is ADM-{userId}-{unixSecond}. Problem: Uniqueness is assumed rather than guaranteed, and a collision throws mid-transaction, aborting a legitimate charge. Impact: Spurious 500s and partial failures during any bulk or rapid admin charging; arguably a viable self-inflicted denial of service if the endpoint is reachable. Trigger: Two charges for the same user within one second. Fix direction: Use a UUID or a Str::random suffix (as the wallet services do), plus an idempotency key supplied by the client. Confidence: High. Confirmed bug.

#### T3-2 — Financial columns use three different precisions for the same currency

Tier: 3 | Files: create_wallets_table.php:16 (decimal(10,2)), create_wallet_requests_table.php:16 (decimal(12,2)), create_wallet_transactions_table.php:19-21 (decimal(15,2)).
Currently: Balance, request amount and ledger amounts each have different scales. Problem: wallets.balance saturates at 99,999,999.99 while the ledger accepts far more, so a balance can overflow its own column before the ledger does — an inconsistency that will manifest as truncation or an error at an unpredictable threshold. Impact: Money-affecting edge cases at scale. Trigger: Large balances or amounts. Fix direction: Standardise on one precision (with headroom) across all money columns in a single migration. Confidence: High. Confirmed schema inconsistency; impact is latent until limits are approached.

#### T3-3 — Wallet-creation OTP flow is optional; an unaudited create-direct route creates wallets with no verification

Tier: 3 | File: routes/api.php:250-251 (initiate / verify-and-create) vs :258 (create-direct → WalletController.php:213-254), which validates only phone format and uniqueness.
Currently: Two routes create a wallet; one sends and verifies an email OTP, the other does not. Problem: The meaningful control is bypassable by choosing the other endpoint. Impact: Wallets can be created with an unverified phone number, weakening the identity link that KYC/verification depends on. Trigger: POST /api/wallet/create-direct. Fix direction: Either remove the direct route or make phone verification mandatory on both paths. Confidence: High. Confirmed.

#### T3-4 — wallet_requests.processed_by references users, but admin actors are employees

Tier: 3 | Files: create_wallet_requests_table.php:20 (constrained('users')); writers at AdminWalletRequestController.php:165,271.
Currently: The column FKs to users while the acting principal is an Employee. Problem: The schema models the wrong entity, so even after T2-1 is fixed an employee id would be written into a users FK — passing silently whenever the ids overlap, and recording the wrong actor. Impact: Cross-table id confusion in the financial audit trail. Trigger: Any admin decision once T2-1 is fixed. Fix direction: Point processed_by at employees, or store an explicit actor type alongside the id. Confidence: High. Confirmed: latent, currently masked by T2-1.

#### T3-5 — Conflicting and duplicated performance indexes; down() drops unconditionally

Tier: 3 | Files: 2026_08_14_144934_add_performance_indexes.php:15-39 (rides_driver_status_index, bookings_user_status_index, ...) vs 2026_08_21_000028_add_performance_indexes_to_core_tables.php:17-29,39-42 which guards on different names (rides_driver_status), creating functional duplicates on (driver_id,status) and the bookings pairs; idx() helper uses MySQL-only SHOW INDEX FROM; down() drops indexes that may not exist.
Currently: Two migrations create near-identical indexes under different names because the guard checks a name that was never created. Problem: Write amplification and wasted storage on the hottest tables; the MySQL-only guard makes the migration unrunnable on SQLite/CI; the down() will error if names were ever reconciled. Impact: Performance regression, and a migration suite that cannot be exercised in the test environment. Trigger: Fresh migrate on either MySQL (duplicate indexes) or SQLite (failure). Fix direction: Collapse into one migration with a driver-agnostic existence check (Schema::hasIndex on Laravel 10+, or a shared introspection helper) and make down() conditional. Confidence: High. Confirmed.

#### T3-6 — MySQL-only raw SQL in migrations and queries contradicts the SQLite test configuration

Tier: 3 | Files: phpunit.xml:30-31 (SQLite :memory:) vs bare DB::statement("ALTER TABLE ... MODIFY ...") in 2026_04_19_014226:23, 2026_05_18_000000:10, 2026_08_01_000001:9-10; spatial/JSON functions in app/Services/Ride/RideSearchService.php:90-131 and app/Repositories/RideRepository.php:339-356.
Currently: Tests are configured for SQLite while several migrations and the entire ride-search path require MySQL functions (ST_Distance_Sphere, ST_GeomFromGeoJSON, JSON_VALID). Problem: The schema exercised by tests is not the schema that runs in production, so enum and index defects (T1-2, T3-5) cannot be caught by the suite. Impact: The test suite gives false assurance about database behaviour — this is precisely why T1-2 was never detected. Trigger: Running the suite against SQLite. Fix direction: Run a MySQL service container in CI and point tests at it, or guard all dialect-specific statements behind driver checks. Confidence: High. Confirmed (configuration contradiction); whether each migration currently errors was not executed.

#### T3-7 — VerifyOtpMiddleware is a no-op, and a test asserts that it works

Tier: 3 | Files: app/Http/Middleware/VerifyOtpMiddleware.php:16-19 (return $next($request); unconditionally); tests/Unit/Middleware/VerifyOtpMiddlewareTest.php.
Currently: The middleware passes every request through and is referenced by no route, yet has a dedicated passing test. Problem: A green test asserting a stub does nothing creates misplaced confidence and conceals that the intended OTP gate was never implemented. Impact: Maintainers may believe OTP enforcement exists where it does not. Trigger: N/A (latent). Fix direction: Implement the check or delete both files; never test that a stub is a stub. Confidence: High. Confirmed dead code + misleading test.

#### T3-8 — AdminUserSeeder reads config keys that were deleted, creating a null-email, null-password user

Tier: 3 | Files: database/seeders/AdminUserSeeder.php:26-30 reads config('admin.system_admin.email' / '.password' / '.first_name'); config/admin.php:9-19 now contains only phone and wallet_prefix (its header confirms credentials were removed).
Currently: All three keys resolve to null, so the seeder does firstOrCreate(['email' => null], ['password' => Hash::make(null), ...]). Problem: A user row with no email and a hash of an empty string; Atarikaktestseeder and UserRealFlowSeeder also key off that missing email. Impact: Broken/dangerous seed data if run manually; the account is not the intended admin. Trigger: php artisan db:seed --class=AdminUserSeeder. Fix direction: Either delete this seeder (admin is now an Employee, per config/admin.php) or source the identity from env with a hard stop, as SpecialAccountSeeder already does correctly. Confidence: High. Confirmed.

#### T3-9 — Hardcoded credentials in seeders and load-test scripts

Tier: 3 | Files: database/seeders/PassengerSeeder.php:61, DriverSeeder.php:64 (Hash::make('password')); SyrideSeeder.php:344,392,436,476,1434 (the last prints the password); Atarikaktestseeder.php:139,210,278; UserRealFlowSeeder.php:310,348; k6-load/*.js:15-20 (five scripts POST a working admin email/password pair to /api/admin/login).
Currently: Well-known passwords are baked into seeders and, notably, into tracked load-test scripts alongside a real admin username. Problem: A deployable artifact contains working credentials. Impact: If any of these run in a reachable environment, the accounts are trivially compromisable; the k6 files also disclose the admin identifier format. Trigger: Run a seeder, or read the repo and try the credentials. Fix direction: Generate random passwords and print/require them, or read from env as SpecialAccountSeeder does; move credentials out of k6 scripts into env vars. Confidence: High. Confirmed.

#### T3-10 — Deployment resets the server to a feature branch and persists a token in the remote URL

Tier: 3 | Files: .github/workflows/deploy-to-vps.yml:6 (trigger on push to samer, not main), :43 (GITHUB_TOKEN written into the remote URL), :46-47 (git reset --hard origin/samer && git clean -fd on the live host); docker/start.sh:31,67,108 (migrate on every boot; clobbers .rr.yaml).
Currently: Production is force-reset to a non-default branch and untracked state is destroyed; the auth token is embedded in the remote URL inside the server's .git/config, where it persists. Problem: git clean -fd deletes any hand-placed file not under version control (potentially including credentials the bind-mounts rely on), and the token is recoverable from disk after the job. Impact: Accidental destruction of server-side state; credential exposure on the deployment host. Trigger: Every push to samer. Fix direction: Deploy from a release tag on the default branch, use a credential helper or SSH deploy key instead of embedding the token, and narrow clean-up to a known artifact directory. Confidence: High. Confirmed.

#### T3-11 — CI never fails on test failures, and writes a plaintext JWT secret into a generated .env

Tier: 3 | Files: .github/workflows/sonar.yml:70 (php artisan test --coverage-clover=coverage.xml || true), :73 (unpinned SonarSource/sonarqube-scan-action@master), :52 (plaintext JWT secret written into CI-generated .env).
Currently: || true makes the test step unconditionally successful, so the pipeline passes regardless of test outcome. Problem: No build-breaking gate exists; broken code — including defects like T1-2 — merges freely, and Sonar publishes coverage from a run whose failures were discarded. The unpinned action is a supply-chain risk. Impact: The entire CI signal is decorative. Trigger: Push failing code. Fix direction: Remove || true; pin actions to commit SHAs; inject secrets via the runner's secret store rather than writing them to a file. Confidence: High. Confirmed.

#### T3-12 — Unauthenticated API documentation and committed generated spec

Tier: 3 | Files: config/l5-swagger.php:69-74 (all middleware arrays empty), :86 (storage_path('api-docs')); storage/api-docs/api-docs.json present (≈148 KB).
Currently: The docs route is served with no auth, and the generated spec is present on disk. Problem: The complete API surface — including admin and staff routes — is enumerable without credentials. Impact: Reduces attacker reconnaissance effort. Trigger: GET /docs. Fix direction: Gate docs behind auth or an IP allowlist outside local, and ensure generated specs are not distributed. Confidence: High. Confirmed.

#### T3-13 — Silent catch (\Throwable) {} blocks suppress failures, including in notification and cache paths

Tier: 3 | Files: 31 occurrences, including app/Services/NotificationService.php:76, app/Services/Wallet/WalletRequestService.php:168, app/Http/Controllers/API/RideController.php:569,607,641,886,929,956, app/Http/Controllers/API/ComplaintController.php:48, app/Providers/AppServiceProvider.php:198,207.
Currently: Exceptions are swallowed with no log, counter, or metric. Problem: Where these guard genuine side-effects (broadcast, notification persistence, debt clearing) a silent failure is indistinguishable from success, and operations teams have no signal. Impact: Lost notifications and undetected partial failures; slow diagnosis. Trigger: Any downstream failure. Fix direction: Keep the non-fatal intent but at minimum Log::warning with context; add a failure metric where the outcome matters. Confidence: High. Confirmed (pattern); the business impact of each site varies and was not individually traced.

#### T3-14 — Push notifications are dispatched synchronously by default, so a notification failure blocks the request

Tier: 3 | Files: config/queue.php:16 ('default' => env('QUEUE_CONNECTION', 'sync')); app/Services/NotificationService.php:63 dispatches SendPushNotificationJob; app/Jobs/SendPushNotificationJob.php:17-18,34-39 (tries = 3, backoff = 10).
Currently: The job implements ShouldQueue with retries, but if QUEUE_CONNECTION is unset the default sync driver executes it inline in the request. Problem: A slow or failing FCM call (with 3 retries and backoff) runs inside the HTTP request, and the retry configuration is meaningless under sync. Impact: Latency spikes and request failures on notification errors — precisely the failure mode the retries were meant to absorb. Trigger: Unset QUEUE_CONNECTION, or an FCM outage. Fix direction: Require an explicit async queue connection in production and fail fast if it resolves to sync; consider afterCommit() so notifications are not sent for rolled-back transactions. Confidence: Medium-High (depends on deployed env, which I could not read). Confirmed default; production behaviour unverified.

#### T3-15 — WalletRequestService pending-withdraw guard is subject to a race

Tier: 3 | File: app/Services/Wallet/WalletRequestService.php:60-89 — balance and pending-total are read, then the row is inserted, all inside a transaction that locks neither the wallet nor the request set.
Currently: The $pendingTotal + $amount > $balance check is a read-then-write with no lockForUpdate() on the wallet and no exclusion on concurrent requests. Problem: Two simultaneous withdrawal requests can each pass the check and together exceed the balance. Impact: Over-committed withdrawals; at approval time the second is rejected (T3-16 area), producing confusing failures rather than prevention. Trigger: Two concurrent POST /api/wallet/request-withdraw calls. Fix direction: Lock the wallet row (lockForUpdate) for the duration of the check-and-insert, mirroring the pattern already used in WalletTransactionService. Confidence: Medium (pattern is clear; no concurrency test exists). Potential issue.

#### T3-16 — No-show resolution applies penalties without locking the report row

Tier: 3 | File: app/Services/Ride/Noshowservice.php:315-352 — reports are fetched outside the transaction (:317-320); applyPenalty() at :327 runs without re-reading the report under lockForUpdate, and only afterwards sets status at :329-332.
Currently: The row is mutated at the end of the transaction but never locked at the start. Problem: Safety currently depends entirely on the scheduler's withoutOverlapping() + onOneServer() (app/Console/Kernel.php:74-79), not on the code. Any other invocation path — a manual command, a retry, a future API trigger — can double-apply a wallet settlement or score penalty, since applyPenalty moves real money. Impact: Duplicate payouts/refunds; duplicated score penalties. Trigger: Concurrent invocation outside the scheduler guard. Fix direction: Re-select the report with lockForUpdate() inside the transaction and re-check status === 'pending' before applying, making the operation idempotent by construction rather than by deployment topology. Confidence: Medium. Potential issue.

#### T3-17 — /up performs a real database query on every health probe

Tier: 3 | File: routes/web.php:9-17; render.yaml:5.
Currently: The healthcheck issues DB::select('SELECT 1') per probe. Problem: Liveness and readiness are conflated: a transient DB blip marks the instance unhealthy and can trigger restarts or traffic withdrawal, and the probe adds load. Combined with the read/write split and replica lag (config/database.php:50-56), probes may target a lagging reader. Impact: Cascading restarts during a recoverable DB incident. Trigger: DB latency spikes. Fix direction: Separate a cheap liveness endpoint from an authenticated/throttled readiness endpoint. Confidence: High. Confirmed design issue.

### TIER 4 — LOW

#### T4-1 — No test can catch the two critical money bugs

tests/TestCase.php:16 calls withoutMiddleware(ThrottleRequests::class) for every test, so no rate limiting is exercised (leaving T2-3/T2-10 unverified); phpunit.xml:16-26 excludes app/Http/Middleware, app/Console, app/Providers and Kernel.php from coverage — precisely where T2-1 and T3-7 live; and the SQLite configuration (T3-6) means the enum defect (T1-2) cannot manifest. Coverage is broad in volume (112 files, with genuine IDOR assertions in tests/Feature/Wallet/WalletRequestControllerTest.php) but structurally blind to the highest-severity classes. Fix direction: Add a MySQL-backed integration job, stop globally disabling throttling, and cover the middleware layer. Confidence: High. Confirmed.

#### T4-2 — scopeNearLocation builds a WKT literal with unbound placeholders.

app/Models/Ride.php:142-149 passes ST_GeomFromText('POINT(? ?)', 4326) with parameters — the ? are inside a string literal, so no binding occurs and the geometry is malformed. The scope has zero callers (grep returns only the definition), so impact is nil today, but it is a latent trap. Fix direction: Build the WKT in PHP (as RideSearchService.php:140 correctly does) and bind it as one parameter. Confidence: High. Confirmed dead bug.

#### T4-3 — StaffJwtService::cleanupExpiredTokens() relies on accidental operator precedence.

app/Services/Staff/StaffJwtService.php:120-125 uses an unparenthesised orWhere on a delete query. It happens to be correct here only because there are no other clauses; the identical shape is flagged as a hazard elsewhere in the codebase. Fix direction: Parenthesise explicitly. Confidence: High. Confirmed style/latent bug.

#### T4-4 — JWT_TTL default is 600 minutes, contradicting its own documentation; staff tokens ignore the config entirely.

config/jwt.php:28 defaults to 600 while its docblock recommends 15; app/Services/Staff/StaffJwtService.php:18 hardcodes ACCESS_TTL = 3600 seconds, so user and staff tokens have inconsistent, undocumented lifetimes. Fix direction: Align the default with the documented value and drive both services from config. Confidence: High. Confirmed.

#### T4-5 — User::$fillable includes every privileged column.

app/Models/User.php:29-43 permits status, token_version, is_verified_driver/passenger, verification_status, national_id, banned_by, ban_expires_at, email_verified_at. I searched exhaustively for a mass-assignment sink and found none (update($request->all()) and variants: zero matches), so this is not currently exploitable — but the model layer offers no defence-in-depth, and a single permissive controller would escalate to privilege escalation. Fix direction: Move privileged columns out of $fillable and set them explicitly. Confidence: High. Potential issue (not a live vulnerability).

#### T4-6 — Dead/duplicate artifacts in the repository root.

Root fix.php (a blind str_replace script hardcoded to /var/www/html/...), root UserRating.php (a duplicate of app/Models/UserRating.php with the same App\Models namespace), app/Services/Ride/Noshowservice.php (non-standard casing), tests/Unit/Tests/Unit/Services/GeocodingServiceTest.php (doubled path segment), committed cacert.pem and .phpunit.result.cache, resources/js/firebase.js (placeholder keys, never bundled by vite.config.js, uses Laravel-Mix process.env.MIX_* syntax in a Vite project), and two k6-load filenames with a leading space. Also config/app.php:99 defines 'key' twice, and config/rate-limiting.php duplicates rate_limiting.php (T2-10). Impact: Autoloadable duplicate classes and confusing dead code; the doubled-segment test path and casing issues are cosmetic but indicate mis-scaffolding. Fix direction: Delete or relocate. Confidence: High. Confirmed.

#### T4-7 — No-op / stub implementations in shipped code.

app/Jobs/ProcessBulkNotifications.php:26-29 has an empty handle(); AdminDashboardController.php:312-314 uploadAdminPhoto() returns a success message without uploading anything. Both are reachable-looking endpoints/jobs that report success while doing nothing. Fix direction: Remove or implement; do not return success from a no-op. Confidence: High. Confirmed.

## C. Findings Table

| Tier | Finding | File | Lines | Impact | Confidence |
| --- | --- | --- | --- | --- | --- |
| 1 | Ride-completion state machine self-contradictory; escrow never released | app/Services/Ride/RideService.php; app/Services/Ride/BookingService.php | 320, 352, 397, 402-418; 496-503 | E-PAY payouts impossible; funds stranded | High |
| 1 | Ledger type values absent from DB enum + strict mode | 2025_07_18_212423_*.php; WalletTransactionService.php; config/database.php | 14-50; 111,178,192,206,492,505,518,569,742,756,770; 67 | All escrow/cash-fee writes throw and roll back | High |
| 1 | Secrets committed in git history | config/firebase-credentials.json, dump.sql @ HEAD | n/a | Long-lived credential + DB dump disclosure | High |
| 2 | Admin actor always null (setUserResolver missing) | StaffJwtMiddleware.php; AdminWalletRequestController.php; PassengerProfileController.php | 79-82; 165,271; 340,353 | Audit trail destroyed on money/moderation actions | High |
| 2 | Admin role gate contradicts token issuer (sycash locked out) | routes/api.php; StaffJwtMiddleware.php; AdminAuthService.php; StaffRole.php | 289; 87-93; 44-59; 48-51 | Financial admin cannot use admin panel | High |
| 2 | OTP attempt cap defined but never enforced | Otp.php; EmailOtpService.php; OtpRepository.php | 59-62, 42, 79; 80-109; 30-39 | Brute-force → password-reset → account takeover | High |
| 2 | Unverified account password overwritten without ownership proof | SignupController.php | 57-97 (68-71), 93-95, 151-153 | Pre-account-takeover of unverified users | High |
| 2 | Literal DB creds, exposed phpMyAdmin, source bind-mounted over image | docker-compose.yml | 16,51,85,120,155,201,207,222,241; 22-27,56-61,90-95,124-129,159-164; 232-241 | DB compromise; non-reproducible deploy | High |
| 2 | Info disclosure + exception leakage on health/debug endpoints | routes/api.php; routes/web.php | 64-92, 452; 9-17 | Unauthenticated recon; DB error leakage | High |
| 2 | Public broadcast channel authorizes everyone | routes/channels.php | 23-25 | Unauthenticated ride-data subscription | High |
| 2 | Pusher credentials as in-code fallbacks | config/broadcasting.php | 9-11 | Committed credential; silent fallback | High |
| 2 | Google OAuth issues token no route accepts | Auth/GoogleController.php; routes/api.php | 111; 143 | OAuth login broken end-to-end | High |
| 2 | Limiters key on user-or-IP → IP-only on public auth; duplicate config | RouteServiceProvider.php; config/rate-limiting.php vs rate_limiting.php | 31-32,40 | Brute-force bypass by IP rotation; shared-NAT lockout | High |
| 2 | Staff refresh tokens stored plaintext (users hashed) | StaffJwtService.php vs JwtService.php | 149-153, 82, 163-179; 281 | Admin sessions recoverable from any DB read | High |
| 2 | Session cookie not httpOnly/secure; CORS credentials | config/session.php; config/cors.php | 26, 82-84; 15,18,20-26,48 | Session theft via XSS; credentialed CORS | High |
| 3 | Transaction id from timestamp → same-second collision | PassengerProfileController.php; AdminWalletRequestController.php | 346; 157 | Aborted charges; spurious 500s | High |
| 3 | Three decimal precisions for one currency | wallets / wallet_requests / wallet_transactions migrations | 16; 16; 19-21 | Balance overflow before ledger limit | High |
| 3 | create-direct bypasses wallet OTP verification | routes/api.php; WalletController.php | 258; 213-254 | Unverified wallets | High |
| 3 | processed_by FKs users, actors are employees | create_wallet_requests_table.php | 20 | Wrong-entity audit reference | High |
| 3 | Duplicate/overlapping indexes; down() unconditional; MySQL-only guard | 2026_08_14_144934_*.php; 2026_08_21_000028_*.php | 15-39; 17-29,39-42 | Write amplification; migration unrunnable on SQLite | High |
| 3 | MySQL-only raw SQL vs SQLite test config | phpunit.xml; several migrations; RideSearchService.php | 30-31; various; 90-131 | Tests exercise a different schema than production | High |
| 3 | VerifyOtpMiddleware no-op with a passing test | VerifyOtpMiddleware.php; tests/Unit/Middleware/VerifyOtpMiddlewareTest.php | 16-19 | False assurance; missing OTP gate | High |
| 3 | AdminUserSeeder reads deleted config keys | AdminUserSeeder.php; config/admin.php | 26-30; 9-19 | Null-email/password user row | High |
| 3 | Hardcoded credentials in seeders and k6 scripts | PassengerSeeder.php, DriverSeeder.php, SyrideSeeder.php, Atarikaktestseeder.php, UserRealFlowSeeder.php, k6-load/*.js | 61; 64; 344,392,436,476,1434; 139,210,278; 310,348; 15-20 | Working credentials in deployable artifacts | High |
| 3 | Deploy resets to feature branch; token in remote URL | .github/workflows/deploy-to-vps.yml; docker/start.sh | 6, 43, 46-47; 31,67,108 | State destruction; credential persistence | High |
| 3 | CI swallows test failures; unpinned action; plaintext secret | .github/workflows/sonar.yml | 52, 70, 73 | No build gate; supply-chain risk | High |
| 3 | Unauthenticated API docs; committed generated spec | config/l5-swagger.php; storage/api-docs/api-docs.json | 69-74, 86 | Full API-surface enumeration | High |
| 3 | Silent catch (\Throwable) {} (31 sites) | NotificationService.php, RideController.php, WalletRequestService.php, etc. | various | Undetected partial failures | High |
| 3 | Queue defaults to sync, defeating job retries | config/queue.php; NotificationService.php | 16; 63 | Latency/failures from inline FCM dispatch | Medium-High |
| 3 | Withdraw request check-then-insert race | WalletRequestService.php | 60-89 | Withdrawals can exceed balance | Medium |
| 3 | No-show penalty without report row lock | Noshowservice.php | 315-352 | Double settlement if invoked outside scheduler | Medium |
| 3 | /up does a DB query per health probe | routes/web.php | 9-17 | Restart cascades on DB blips | High |
| 4 | No test can catch the critical money bugs | tests/TestCase.php; phpunit.xml | 16; 16-26, 30-31 | Structural blind spots | High |
| 4 | scopeNearLocation unbound WKT placeholders (dead) | app/Models/Ride.php | 142-149 | Latent malformed-geometry bug | High |
| 4 | Unparenthesised orWhere in delete | StaffJwtService.php | 120-125 | Accidental correctness | High |
| 4 | JWT_TTL default contradicts docs; staff TTL hardcoded | config/jwt.php; StaffJwtService.php | 28; 18 | Inconsistent token lifetimes | High |
| 4 | Privileged columns in $fillable (no live sink found) | app/Models/User.php | 29-43 | No defence-in-depth | High |
| 4 | Duplicate/dead root artifacts and configs | fix.php, UserRating.php, Noshowservice.php, config/app.php, etc. | various | Autoloadable duplicates; confusion | High |
| 4 | No-op job and stub endpoint returning success | ProcessBulkNotifications.php; AdminDashboardController.php | 26-29; 312-314 | Silent no-ops reported as success | High |

## D. Top Recurring / Root-Cause Patterns

Two sources of truth for the same concept. "Admin" is defined independently in StaffRole::isAdminRole() and in route middleware (T2-2). "Admin identity" exists as both attributes['staffEmployee'] and $request->user() (T2-1). Transaction types are defined in a migration enum and again as string literals in services (T1-2). Money precision is defined three times. Rate-limit config exists twice. This single pattern accounts for the two most damaging findings and several others.

Guards that exist but are never invoked. Otp::incrementAttempts() (T2-3), Booking::markPassengerConfirmed() and RideStatus::isConfirmable() (T1-1), VerifyOtpMiddleware (T3-7), AdminJwtMiddleware (T2-2). The code reads as though the control is present; nothing calls it. This is the most dangerous pattern in the repository because it manufactures false confidence.

Silent failure by design. ?->id on a guaranteed-null expression, catch (\Throwable) {} in 31 places, nullable audit columns, and || true in CI all convert defects into quiet data gaps. Several findings were only detectable because the intent (a NOT NULL audit field, a retry config, a passing test) contradicted the behaviour.

A test environment that cannot observe production behaviour. SQLite memory tests against MySQL-only migrations and spatial SQL, throttling globally disabled, and middleware excluded from coverage. The suite is large and in places genuinely good, yet structurally incapable of detecting the highest-severity class of bug — which is exactly what happened.

Copy-paste drift with near-miss naming. ride_earnings vs ride_earning; escrow_release vs escrow_released; rides_driver_status vs rides_driver_status_index; rate-limiting vs rate_limiting. Each is a small, invisible divergence with disproportionate consequences.

Development affordances reaching production shape. http_only => false "for Flutter web", DB_PASSWORD: "secret", bind-mounted source trees, OTP codes echoed in responses, debug endpoints left registered, test-mode password overwrite. Individually justified in comments; collectively they define the security posture.

## E. Areas Audited

Authentication / authorization / roles: JwtService, StaffJwtService, AdminAuthService, AdminAuthService request helpers, JwtAuthMiddleware, StaffJwtMiddleware, AdminJwtMiddleware, VerifyOtpMiddleware, CacheStatusHeader, Kernel.php aliases, StaffRole, routes/api.php, routes/web.php, routes/channels.php, config/auth.php, config/sanctum.php, config/admin.php, config/session.php, config/cors.php, LoginController, SignupController, GoogleController, RefreshTokenController path, ResetPasswordController, VerifyPasswordOtpController, ForgotPasswordController.

Money / wallet / escrow: WalletTransactionService (all 789 lines, including both release paths), CashRideFeeService (548 lines), AdminWalletService, WalletRequestService, WalletController, WalletRequestController, AdminWalletRequestController, PassengerProfileController::chargeWallet, AdminDashboardController::chargeWallet, EPayPaymentStrategy, CashPaymentStrategy, PaymentStrategyFactory, PaymentStrategy, Wallet, WalletTransaction, WalletRequest, WalletRequestStatus, all wallet/wallet_requests/wallet_transactions migrations including the enum-widening migration, SpecialAccountSeeder, SystemWalletSeeder.

Rides / bookings / no-show / score: RideService, BookingService, Noshowservice, RideValidationService, RideSearchService, ScoreService, RideStatus, BookingStatus, Booking, Ride, NoshowReport path, RideController, Console/Kernel.php scheduling.

Controllers / services / jobs / events: ChatController, ChatMessageHandler, MessageTypeFactory, TextMessageType, ImageMessageType, ComplaintController, ComplaintService, DocumentController, DocumentVerificationService, VerificationController, ProfileController, ProfileUpdateService, ProfileInteractionService, FileUploadService, EmailOtpService, WhatsAppOtpService, TextMeBotOtpService, Otp, OtpRepository, NotificationService, NotificationController, PushNotificationController, PushNotificationService, FcmSenderService, PushTokenManager, SendPushNotificationJob, SendPushNotification, SendScheduledNotification, ProcessBulkNotifications, NotificationSent, RideCreated, RideCancelled, RideBooked, AdminExportService, AdminReportService, AdminTripService, AdminDriverService, AdminUserService, AdminBanController, Conversation, Message.

Database: 11+ migrations read in full (refresh tokens, wallets, wallet_transactions, wallet_requests, wallet_requests status, decouple_system_wallets, both performance-index migrations, enum widening, complaints, otps), plus targeted greps across all 66.

Config / deployment / CI: config/database.php, filesystems.php, queue.php, jwt.php, broadcasting.php, l5-swagger.php, app.php, cors.php, session.php, auth.php, admin.php, rate-limiting.php, rate_limiting.php, phpunit.xml, Dockerfile, docker-compose.yml, docker/start.sh, render.yaml, .rr.yaml, nginx-docker.conf, .github/workflows/sonar.yml, .github/workflows/deploy-to-vps.yml, .gitignore, .dockerignore, sonar-project.properties, public/, vite.config.js, resources/js/.

Frontend: resources/js/app.js, resources/js/firebase.js, vite.config.js, package.json.

Repo hygiene: git status, git ls-tree -r HEAD, git diff --cached --stat, git check-ignore on all six sensitive paths, root artifact inventory.

## F. Areas That Could Not Be Fully Verified

Environment limitations (be explicit about these):

No code was executed. The shell sandbox failed partway through (sandbox mode "workspace-write" is requested but no sandbox backend is usable on this host... --temp is not an existing directory). I did not retry with elevated permissions, per the read-only constraint. Consequently no finding is dynamically confirmed: I could not run php artisan route:list, php artisan migrate --pretend, the test suite, or a linter. T1-1, T1-2 and T2-2 are high-confidence static traces, not observed failures.
vendor/ was not inspected, so there is no dependency CVE assessment. composer.lock and package-lock.json bodies were not read; third-party package versions are unaudited.
The six sensitive files were never opened (.env, config/firebase-credentials.json, storage/app/firebase/service-account.json, dump.sql, all_code.txt, tests_code.txt) — located by path only. I therefore cannot assess .env-driven misconfiguration: actual QUEUE_CONNECTION, APP_DEBUG, SESSION_SECURE_COOKIE, EMAIL_OTP_MODE, OTP_BYPASS_ENABLED, or whether production credentials differ from the committed defaults. T3-14 and T2-4's exploitability are specifically gated on values I could not read.
Delegated work failed. Three background subagents intended to cover exhaustive per-line security review, every migration and seeder, and the full deployment sweep all terminated without producing output. I completed the coverage myself through targeted inspection — but this is wasted work that should be recorded, and it means my coverage is sampled rather than exhaustive.

Coverage gaps (sampled, not exhaustive):

Migrations: 11 of 66 read in full; the remaining ~55 are represented by grep evidence only. A defect may exist in an unread migration.
Seeders: 4 of 12 read in full.
Controllers: 8 of ~45 read in full; the rest covered by targeted greps for dangerous patterns.
Services: 6 of ~40 read in full (plus the payment/score/notification core).
Tests: 2 of 112 test files read in full; the inventory and phpunit.xml configuration were reviewed.
Not inspected: app/Docs/** (12 files), app/DTOs/**, app/Domain/** score policies beyond what I read, app/Events/** bodies, app/Interfaces/**, app/Repositories/** beyond OtpRepository and the ride repo, app/Services/Admin/** beyond AdminWalletService and AdminAuthService, app/Services/Staff/** beyond StaffJwtService, app/Http/Requests/** beyond the Wallet set, README.md, resources/views/**, storage/ (19.2 MB of generated content), k6-load result data, perf-results/.

Hypotheses I tested and disproved — reported so they are not re-investigated:

Mass assignment: exhaustively grepped for update($request->all()), create($request->all()), fill($request->all()) and variants across app/ → zero matches. T4-5 is therefore not a live vulnerability.
SQL injection via raw fragments: all 37 whereRaw/selectRaw/orderByRaw sites were reviewed — every one uses bound parameters or constant SQL. No interpolated user input found.
IDOR in RideController::show(): disproved — the endpoint deliberately returns seat aggregates only, with an explicit comment that passenger data never leaves it.
Double escrow release: the two release paths (releaseEarningsToDriver and releaseEscrowToDriver) are currently not simultaneously reachable, because the completion state machine deadlock (T1-1) and the status = CONFIRMED filter in checkAndCompleteRide prevent both from firing on one booking. This is a real risk if T1-1 is fixed naively — collapsing to a single release site matters.

## G. Prioritized Remediation Sequence

Sequenced by dependency and risk. No change has been made; this is a plan for your approval only.

Phase 0 — Containment (do first, independent of code changes). Rotate the Firebase service-account credential and any credential contained in dump.sql; treat both as disclosed, because they remain in git history (T1-3). Swap the secret DB root password and stop publishing MySQL/phpMyAdmin ports (T2-5). Rotate the committed Pusher credential (T2-8). Rotate the k6-referenced admin password (T3-9). These are external actions and must precede or accompany the code work — history rewriting is pointless until the material is invalidated.

Phase 1 — Make the money path work (highest functional risk). Fix T1-2 first: choosing the schema representation determines what the services must emit, and it is a prerequisite for T1-1's payout path being testable end-to-end. Then fix T1-1, deciding deliberately on one release site — if you unify on checkAndCompleteRide, delete releaseEscrowToDriver; if you keep per-booking release, remove the checkAndCompleteRide payout. Do not leave both, or you will create the double-payout that T1-1 currently prevents by accident. Add an end-to-end E-PAY integration test (booking → charge → completion → payout) as the acceptance gate.

Phase 2 — Restore the test environment's ability to see the truth. Without this, Phase 1's fixes cannot be regression-guarded and the class of bug that produced T1-2 stays invisible. Stand up a MySQL service in CI (T3-6), remove the global withoutMiddleware(ThrottleRequests::class) (T4-1), extend coverage to app/Http/Middleware (T4-1), and remove || true from sonar.yml (T3-11).

Phase 3 — Authorization and identity correctness. Fix T2-1 (setUserResolver in StaffJwtMiddleware) and T3-4 together, since the FK target must be corrected in the same change that starts populating the column. Then resolve T2-2 by defining the admin role set once and consuming it in both places — and decide the intended fate of AdminJwtMiddleware and VerifyOtpMiddleware (delete or implement; do not leave them as decoration). Add tests asserting that each role can and cannot reach each admin route group.

Phase 4 — Account-security surface. T2-3 (enforce the OTP attempt cap) and T2-10 (key limiters on account identity, delete the duplicate config) are complementary and should land together, since neither is sufficient alone. Then T2-4 (stop overwriting unverified passwords; stop echoing OTP codes). Then T2-9 (make Google OAuth issue a token the API actually accepts) and T2-11 (hash staff refresh tokens, with a migration that invalidates outstanding sessions).

Phase 5 — Deployment and exposure. T2-5 (remove bind-mounts so the image is what runs; add credentials via secrets), T2-6 (delete debug endpoints; stop leaking exception text from /up), T3-10 (deploy from a release tag; stop embedding the token in the remote URL), T3-12 (gate API docs). Then T3-17 and T2-12.

Phase 6 — Data integrity and hardening. T3-1 (collision-proof transaction ids), T3-2 (unify currency precision), T3-3 (remove or secure create-direct), T3-5 (collapse duplicate indexes), T3-8 and T3-9 (seeder credentials), T3-13 (log swallowed exceptions), T3-14 (require an async queue in production), T3-15/T3-16 (add row locks so safety does not depend on scheduler topology).

Phase 7 — Cleanup. Tier 4 items: remove dead/duplicate artifacts, align JWT_TTL with its documentation and drive both token services from config, tighten User::$fillable, and delete or implement the no-op job and stub endpoint.

Deferred pending your decision: T4-5 is not exploitable today and should not displace higher-priority work; address it when the model layer is next touched. The AGENTS.md carve-out for the Firebase finding remains in force unless you extend it — I included it here only because this request explicitly asked for full configuration coverage, and I have referred to all credentials by name only. This is the complete original Syride audit. Treat it as the authoritative baseline audit.

---

# Part 2 — Current Remediation State

*This section describes the repository as it exists **now**. It is maintained as tasks complete and is
separate from the historical snapshot above. Where remediation work disproved part of the original
audit, the correction is recorded here — the Part 1 text is left untouched on purpose.*

## Progress Table

| Task | Status |
| --- | --- |
| T1-1 | VERIFIED FIX |
| T1-2 | VERIFIED FIX |
| T1-3 | BLOCKED — owner action required (rotation + history purge) |
| T2-1 | VERIFIED FIX |
| T2-2 | VERIFIED FIX (original premise partly DISPROVED — see section) |
| T2-3 | VERIFIED FIX (defect deeper than the original audit described) |
| T2-4 | VERIFIED FIX |
| T2-5 | VERIFIED FIX (2 audit premises corrected; dev convenience moved to an override file) |
| T2-6 | VERIFIED FIX |
| T2-7 | VERIFIED FIX (original mechanism imprecise; the real defect was the producer channel type) |
| T2-8 | VERIFIED FIX (escalated: committed literals are byte-identical to the live credential — owner rotation required) |
| T2-9 | VERIFIED FIX (confirmed exactly; two parity gates added that the fix newly exposed) |
| T2-10 | VERIFIED FIX (confirmed exactly; identity bucket added alongside IP, not replacing it) |
| T2-11 | VERIFIED FIX (confirmed exactly; legacy rows purged, not re-hashed — deliberate) |
| T2-12 | VERIFIED FIX (http_only restored + credentialed CORS off by default; audit's 'encrypt' premise DISPROVED) |
| T3-1 | VERIFIED FIX (confirmed; UUID id, the collision reproduced live at 500 pre-fix) |
| T3-2 | VERIFIED FIX (money columns standardised to decimal(15,2); defaults preserved) |
| T3-5 | VERIFIED FIX (3 dup indexes collapsed + 2 more found live during T3-6; driver-agnostic) |
| T3-6 | VERIFIED FIX (7 MySQL-only migrations driver-guarded; MySQL-in-CI is the durable half) |
| T3-7 | VERIFIED FIX (dead stub + misleading test deleted, not repaired) |
| T3-8 | VERIFIED FIX (broken seeder deleted; it created a null-email/null-password user) |
| T3-9 | VERIFIED FIX (no committed passwords in seeders/k6; trait + env + random-by-default) |
| T3-11 | VERIFIED FIX (CI fails on test failure; Sonar action pinned to SHA; JWT secret generated) |
| T3-12 | VERIFIED FIX (docs gated by env/IP; generated spec untracked + gitignored) |
| T3-13 | VERIFIED FIX (27 silent catches now Log::warning; 2 cache sites annotated + pinned) |
| T3-14 | VERIFIED FIX (sync-queue boot guard, mirroring the T2-8 pusher guard) |
| T3-15 | VERIFIED FIX (withdraw check moved inside txn + wallet row locked FOR UPDATE) |
| T3-16 | VERIFIED FIX (report re-locked + status re-checked before any money moves) |
| T3-3 | DEFERRED (owner: leave for later) |
| T3-4 | NOT STARTED (contradicts the owner's T2-1 decision; awaiting a change of mind) |
| T3-10 | NOT STARTED (deploy automation; entangled with owner-owned T1-3) |
| T3-17 | DEFERRED (owner: leave for later) |
| T4-1 | VERIFIED FIX (global throttle opt-out removed; coverage blind spots closed; exposed + fixed a real Header bug) |
| T4-2 | VERIFIED FIX (WKT built in PHP and bound; wrong Builder type-hint removed too) |
| T4-3 | VERIFIED FIX (DELETE group parenthesised; SQL unchanged for today's callers) |
| T4-4 | VERIFIED FIX (both token families read config; staff default preserves 3600 s) |
| T4-5 | NOT STARTED — ROLLED BACK (deferred on owner instruction; hazard proven) |
| T4-6 | VERIFIED FIX (dead artifacts deleted, misfiled test relocated, k6 names cleaned) |
| T4-7 | VERIFIED FIX (no-op job and success-reporting upload endpoint removed) |
| **Next** | see docs/audit/STATE.md |

## Verification environment (used for T1-1 and T1-2)

- Production-compatible **MySQL 8.2.0** on `127.0.0.1:3399`, scratch schema, started from a standalone
  data directory outside the repository. This was necessary because the default `phpunit.xml`
  configuration is SQLite, and the `rides` table uses spatial indexes that SQLite cannot create
  (see T3-6 in Part 1).
- Tests were run with shell environment variables overriding the XML config, e.g.
  `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3399`, `DB_DATABASE=<scratch>`,
  `DB_USERNAME=...`, `DB_PASSWORD=...`, `DB_REPLICA_HOST=127.0.0.1`.
- **The production database was never contacted.** Credentials and host/port resolution were verified
  before trusting any test result.

## T1-1 — VERIFIED FIX

**Original finding (Part 1):** Ride-completion state machine is self-contradictory; E-PAY escrow can
never be released. Traced primarily to `RideService::finishRide()` setting `awaiting_confirmation`
at `:320`, which `BookingService::passengerConfirmCompletion()` then rejects.

**Verified / current finding (corrected during remediation):** The original static trace pointed at a
state machine that is **unreachable over HTTP**. `RideController::finishRide()` and
`RideController::driverConfirmCompletion()` are already no-op stubs at HEAD (`RideController.php:742`
and `:758`), so `RideService::finishRide()` and `RideService::checkAndCompleteRide()` cannot be entered
via the API. The **live** defect is narrower and different:

> `BookingService::passengerConfirmCompletion()` hand-rolled its status gate as
> `in_array($ride->status, [ACTIVE, FULL])` / `elseif ($ride->status !== LAUNCHED)`. The legacy
> `awaiting_confirmation` value matched neither branch, so any ride row still holding that value made
> its passengers permanently unable to confirm — their escrowed money could never be released and the
> ride could never reach `finished`. This contradicted the code's own intent: `RideStatus` documents
> that `LAUNCHED` replaced `awaiting_confirmation`, and ships an unused `isConfirmable()` predicate
> covering both values.

**Root cause:** Duplicated, stale status logic in the service instead of using the enum's own
predicates; the enum retains `awaiting_confirmation` in the DB column, so real legacy rows exist.

**Files changed:**
- `app/Services/Ride/BookingService.php` — the only production change. One hunk at
  `passengerConfirmCompletion()`. The gate now uses `RideStatus::tryFrom()` plus
  `canBeBooked()`/`isConfirmable()`; unknown statuses still throw; legacy values normalise forward to
  `launched`.
- `tests/Feature/Rides/PassengerConfirmCompletionTest.php` — new regression suite (10 tests).

**Tests / checks performed:**
- `php vendor/bin/phpunit tests/Feature/Rides/PassengerConfirmCompletionTest.php --no-coverage` on
  MySQL 8.2.0 → **`OK (10 tests, 47 assertions)`**.
- Driven through the real `jwt`-guarded HTTP endpoint, not just the service layer.
- **Causality proven:** with only the fix stashed, the suite produced exactly
  `Tests: 10, Assertions: 44, Failures: 1` — only
  `test_legacy_awaiting_confirmation_ride_is_still_confirmable` failed, with
  `InvalidArgumentException: This ride cannot be confirmed (current status: awaiting_confirmation).`
  Restoring the fix returned the suite to 0 failures.
- Money-path facts measured against the production-compatible DB: escrow in 100,000 for 2 seats →
  after p1 confirms, driver +47,500 (95%), Primary +2,500, SyCash 50,000, booking `completed`, ride
  `launched`; after p2 confirms, driver 95,000 total, SyCash 0, ride `finished`. Exactly one
  `ride_earning` and one `escrow_release` per booking; zero `escrow_released`.
- Denied paths verified to move no money: unauthenticated (401), another passenger's booking,
  before departure, cancelled ride, finished ride, and double-confirm paying exactly once.
- Ride-wide `releaseEarningsToDriver()` (`escrow_released`) verified **never** to fire, locking in
  the no-double-payout property.
- On the default SQLite configuration the suite **skips cleanly** (10 skipped) rather than erroring;
  the skip guard is placed before `parent::setUp()` because `RefreshDatabase` migrates inside it.
- `php -l` clean.

**Causality / rollback evidence:** measured as above (stash → 1 failure; restore → 0 failures). No
rollback was required.

**Final state: VERIFIED FIX**

**Genuinely unverified:**
- Not exercised through a live RoadRunner/Octane server; verified via in-process HTTP through the
  real middleware. Queue/notification side effects were not asserted.
- The design decision "per-passenger release only; remove the driver finish action entirely" was
  settled with the user, but **the stubbed driver endpoints and their routes were NOT removed** —
  `routes/api.php:205-206` and the controller stubs remain. This is a deliberate broadening of the
  fix (the stub already made the ride-wide payout unreachable over HTTP), so removing the routes is
  tracked separately as a candidate cleanup rather than being folded into T1-1.
- `tests/Feature/Rides/RideControllerFullTest.php` remains stale and unrun: it expects
  `awaiting_confirmation` to be confirmable and `active` to be rejected, which the new behaviour
  contradicts. It also still fails in `setUp` for an unrelated pre-existing reason (see below).

## T1-2 — VERIFIED FIX

**Original finding (Part 1):** Every escrow/driver-payout ledger write uses a `type` value absent from
the database enum, and `strict => true` makes it fatal — money movement inserts throw and roll back.

**Verified / current finding (corrected during remediation):** The "insert throws" premise is
**false**, and this was established by measurement, not reading:

> On a clean, full migration against MySQL 8.2.0, the `wallet_transactions.type` column is
> **`varchar(255)`**, not an enum. The `status` column on the same table *does* remain
> `enum('pending','completed','failed','cancelled')`. Therefore no insert crashes for an
> unrecognised type value. The mechanism: migration
> `2025_07_18_212423_update_type_enum_on_wallet_transactions.php:65` uses `renameColumn()`, which
> Laravel routes through doctrine/dbal, rebuilding an `ENUM` as `VARCHAR(255)`. That migration is
> effectively a **no-op that silently destroyed its own constraint** — a real finding in its own
> right (the intended validation is gone), but not a live crash.

**The real live defect found instead:** a **writer/reader type-name mismatch** in the admin financial
report:

| Role | Value | Location |
| --- | --- | --- |
| Writer (live) | `escrow_release` **singular** | `WalletTransactionService.php:742` |
| Reader | `escrow_released` **plural** | `AdminReportService.php:310` |

The live per-passenger payout path writes the singular name; the report summed only the plural one, so
it matched **zero rows**. `total_escrow_out` therefore reported **0.00 SYP permanently** while SyCash
genuinely drained and drivers were genuinely paid — escrow in and out could never reconcile, silently.

**Root cause:** Two sources of truth for the same concept (copy-paste drift with near-miss naming —
the "Copy-paste drift" pattern in Part 1, section D).

**Files changed:**
- `app/Services/Admin/AdminReportService.php` — one hunk: `where('type', 'escrow_released')` →
  `whereIn('type', ['escrow_release', 'escrow_released'])`, with a comment explaining both spellings.
  Counting both is deliberate: production may hold historical plural rows from the now-stubbed
  ride-wide release, and dropping them would under-report history a different way.
- `tests/Feature/Admin/AdminFinancialReportEscrowTest.php` — new regression suite (4 tests).

**Explicitly excluded from the fix (checked, not changed):** the `ride_earnings` (plural, `:192`) vs
`ride_earning` (singular, `:756`) pair has **no reader anywhere** in `app/`, so it is a latent naming
inconsistency rather than a live defect. Every other type read by that report (`escrow_received`,
`platform_fee`, `driver_no_show_refund`, `driver_cancellation_refunds`, `cancellation_processing`)
matches its writer and was left alone.

**Tests / checks performed:**
- `php vendor/bin/phpunit tests/Feature/Admin/AdminFinancialReportEscrowTest.php --no-coverage` on
  MySQL 8.2.0 → **`OK (4 tests, 14 assertions)`**.
- Covers: report reflects the first per-passenger release (50,000) then the full drain (100,000);
  the report's escrow-out figure equals the actual SyCash balance drop; legacy plural rows are still
  counted; and a booking with no confirmation yields 0.00 out.
- **Causality proven:** with only the one-line fix stashed →
  `Tests: 4, Assertions: 10, Failures: 2`, failing with
  `Failed asserting that '0.00 SYP' ... contains "50,000.00"` while 50,000 SYP had genuinely left
  escrow, and `Failed asserting that 0.0 matches expected 50000.0`. Restoring the fix → 0 failures.
- **T1-1 regression check re-run after the T1-2 change:** `OK (10 tests, 47 assertions)` — unchanged.
- `php -l` clean.

**Causality / rollback evidence:** measured as above (stash → 2 failures; restore → 0 failures). No
rollback was required.

**Final state: VERIFIED FIX**

**Genuinely unverified:**
- Not exercised through a live RoadRunner/Octane server.
- If `releaseEarningsToDriver()` (the ride-wide plural writer) were ever revived, its row would now
  be counted alongside per-passenger rows and could double-count the same fares in the report. It is
  unreachable today (controller stubs) and T1-1's
  `test_ride_wide_lump_sum_release_never_fires` locks that in, but the report does not distinguish
  the two shapes if it returns.
- The destroyed `type` enum constraint and the `ride_earning`/`ride_earnings` inconsistency are
  reported here but **not fixed** — they are separate findings.

## T1-3 — BLOCKED (owner action required; no code change made)

**Original finding (Part 1):** Secrets are committed in git history and remain recoverable —
`config/firebase-credentials.json` and `dump.sql` are in `HEAD`; staging their deletion does not
remove the blobs from history.

**Verified / current finding (confirmed and found to be WORSE than the original audit's conditional
wording):** the original entry said *"If either has ever been pushed to a shared remote, the secret
must be treated as permanently disclosed."* That condition is **met**, and verified directly:

| Fact | Evidence (metadata/paths only — no secret content was opened) |
| --- | --- |
| Both files are in local `HEAD` | `git ls-tree -r HEAD` → `config/firebase-credentials.json`, `dump.sql` |
| **Both files are in `origin/main`** | `git ls-tree -r origin/main` → both present |
| Local and remote are identical | `git rev-list --left-right --count origin/main...main` → `0  0` |
| **The repository is PUBLIC** | GitHub API `repos/abdurrahman8151/ATAREEKAK` → `"private": false`, `"visibility": "public"` |
| Blob sizes (not content) | firebase file ≈ 2,397 B; `dump.sql` ≈ 3,906,480 B |
| History depth | `firebase-credentials.json` present in 2 commits; `dump.sql` in 3; reachable from `origin/main` |
| Future commits | Staged as `D` (`git rm --cached`); `git ls-files --error-unmatch` now errors → untracked going forward |
| `.gitignore` coverage | Covered: `config/*firebase*.json` and `dump.sql` |

**Conclusion: the disclosure is unconditional, not conditional.** Both blobs are retrievable from a
public GitHub repository today, without authentication, by anyone.

**Additional exposure discovered during this task (new, not in the original audit):** the `origin`
remote URL in `.git/config` embeds a **GitHub personal access token as plaintext userinfo**. This is a
live bearer credential for the GitHub account and is stored on disk unencrypted. The value is
deliberately **not** recorded in this file. A `repo`-scoped PAT also grants write access to the
repository. Treat as disclosed and rotate.

**Root cause:** secret material was committed before ignore rules existed; deletion-from-history
cannot be achieved by staging a removal, and the credentials were never rotated, so the leaked
material remains valid.

**Files changed:** NONE. No code change was made — by explicit user decision (see below).

**Why no code change:** remediation is not a code change. It requires (1) rotating the Firebase
service-account credential, any credential inside `dump.sql`, and the GitHub PAT; (2) purging history
and force-pushing; (3) adding a scanner/CI gate. Steps (1) and (2) are external/destructive actions
outside this agent's authority — `AGENTS.md` forbids rewriting history, force-pushing, and touching
remotes. Step (3) is prevention only: implementing it alone would have presented prevention as a fix
while the live secrets stayed readable on a public repo, which `AGENTS.md` prohibits. The user
therefore chose to treat T1-3 as owner-action-only.

**Tests / checks performed:** none applicable (no code change). The verification above is git/API
metadata inspection only. No secret was opened, printed, or recorded — only paths, blob SHAs/sizes,
and `git` diff status were read.

**Final state: BLOCKED — owner action required.** Not `VERIFIED FIX`; the vulnerability remains live.

**Owner action checklist (ordered; rotation before purge — purge is worthless while the credentials
are still valid):**
1. Rotate the Firebase service-account credential for the Firebase project.
2. Rotate/revoke any credential contained in `dump.sql` (DB users, API keys, tokens) and treat the
   database contents as disclosed.
3. Revoke the GitHub PAT embedded in the `origin` remote URL, then remove it from `.git/config`
   (use a credential helper, SSH deploy key, or a fresh token injected at use time).
4. Purge the blobs from history (`git filter-repo --path config/firebase-credentials.json --path dump.sql --invert-paths`), then
   force-push and coordinate with every clone. `git-filter-repo` is **not installed** on this host.
5. Add a pre-commit secret scanner plus a CI gate (e.g. gitleaks). No `.gitleaks.toml`,
   `.pre-commit-config.yaml`, or gitleaks workflow exists today.
6. Because the repo is public, assume the blobs may already be mirrored/forked; rotation — not purge —
   is what actually removes the risk.

**Genuinely unverified:** the contents of both blobs were never read, so the *specific* credentials
inside `dump.sql` are unknown; the checklist above is therefore stated generically. Whether third
parties have already cloned or forked the repository is unknowable from here.

## T2-1 — VERIFIED FIX

**Original finding (Part 1):** Admin identity is always null on every admin write, so audit fields are
never recorded — `StaffJwtMiddleware` sets only `attributes['staffEmployee']` and never calls
`setUserResolver()`.

**Verified / current finding: confirmed, and the blast radius is larger than the original audit
recorded.** The original listed 4 call sites (`AdminWalletRequestController.php:165,271`;
`PassengerProfileController.php:340,353`). A full grep found **~10** `$request->user()` reads on
staff-guarded admin routes:

| Site | Line | Effect when null |
| --- | --- | --- |
| `AdminWalletRequestController` | `:165`, `:271` | `wallet_requests.processed_by` always NULL |
| `AdminBanController` | `:93`, `:104`, `:186` | `users.banned_by` always NULL (`:186` is a log field) |
| `PassengerProfileController` | `:340` | `wallet_transactions.user_id` always NULL (admin charge) |
| `PassengerProfileController` | `:353` | log `admin_id` always NULL |
| `AdminUserController` | `:68` | `adminUserId` null → admin photo lookup returns null |
| `AdminDriverController` | `:67` | same |

**Root cause:** `StaffJwtMiddleware` injects the principal into `attributes` only. Its two sibling
middlewares do call `setUserResolver()` (`AdminJwtMiddleware.php:91`, `JwtAuthMiddleware.php:90,111`).
Because the default guard is `web`/session (`config/auth.php`), `$request->user()` returned null on
every staff-token request, and the nullable columns + `?->` operator converted the logic error into a
silent data gap rather than an exception.

**Why the original audit's literal fix would have made things WORSE (measured, not inferred):** the
obvious fix is `setUserResolver(fn () => $employee)`. But every admin route is guarded by
`staff:admin,system_admin` (`routes/api.php:289`), so the principal is an **Employee**, while these
columns reference **`users`**:

- `wallet_requests.processed_by` → `constrained('users')` (`2026_05_17_012515:20`) — real FK
- `wallet_transactions.user_id` → FK `users` (`2025_07_17_153007:30`) — real FK

Proven directly against MySQL 8.2.0: with `employees.id=77` present but absent from `users`,
`UPDATE wallet_requests SET processed_by = 77` raised
`ERROR 1452 ... FOREIGN KEY (processed_by) REFERENCES users (id)`, while the same update with a real
`users.id` succeeded. So the naive fix would convert a **silent NULL into a 500 on every wallet-request
approval**, aborting the money-moving transaction. `users.banned_by` has no FK at all, so it would have
silently recorded the wrong actor — the reader at `AdminBanController.php:285-286` does
`User::find($user->banned_by)` and would display **a different person's name**.

**The correct mechanism already existed in the codebase.** `EmployeeManagementService::ensureShadowUser()`
maps `Employee::email → User::email`, and its own docblock states it exists because *"the chat system
stores conversations against the `users` table, not the `employees` table."* It is already used by
`ContactController.php:48` and `StaffChatController.php:228`. Decision confirmed with the user: resolve
to the shadow User rather than retargeting the FK columns (which is T3-4, left untouched).

**Files changed:**
- `app/Http/Middleware/StaffJwtMiddleware.php` — one addition (+29 lines): inject
  `EmployeeManagementService` and call `$request->setUserResolver(...)` returning the employee's shadow
  User. The closure is **lazy**, so the shadow User is only looked up/created when a controller actually
  calls `$request->user()`; routes that never do pay nothing. Returns `null` when the employee has no
  email, preserving the previous behaviour for email-less accounts instead of failing.
- `tests/Feature/Admin/StaffAdminIdentityAttributionTest.php` — new regression suite (10 tests).

**Not changed:** the user's pre-existing, unrelated work in `AdminWalletRequestController.php`
(cancelled-status feature + `Cache::forget`) and the wallet_requests `status` enum migration were left
untouched; both `processed_by` call sites still read `$request->user()?->id`, which the middleware now
populates. No migration, no FK change (T3-4 remains open).

**Tests / checks performed:**
- `php vendor/bin/phpunit tests/Feature/Admin/StaffAdminIdentityAttributionTest.php --no-coverage` on
  MySQL 8.2.0 → **`OK (10 tests, 44 assertions)`**.
- Driven through the **real re-authenticated HTTP endpoints** (staff login → admin route), not the
  service layer, so the middleware path is genuinely covered.
- Allowed paths verified: wallet-request approve and reject both record the acting admin; ban records
  `banned_by` and the id resolves back to the correct person; admin wallet charge writes
  `wallet_transactions.user_id` and the balance actually moves; the shadow User is reused, not
  duplicated per request.
- Denied paths verified and unchanged (no authz weakening): unauthenticated → 401, invalid token → 401,
  a **passenger (`jwt`) token on the staff guard → 401**, an inactive employee → 401, and a
  `support_agent` → 403. The last case also asserts that a rejected request creates **no** shadow User
  (the resolver is lazy), and that `processed_by` stays NULL and status stays `pending`.
- **Causality proven:** with only the middleware fix stashed, the suite produced
  `Tests: 10, Assertions: 36, Failures: 5` — exactly the 5 attribution tests, each failing with
  `Failed asserting that null is not null` (`processed_by`, `banned_by`, `wallet_transactions.user_id`,
  the shadow-User count) — while all 5 denied-path tests still passed. Restoring the fix returned
  `OK (10 tests, 44 assertions)`.
- **Regression suites re-run:** T1-1 `OK (10 tests, 47 assertions)`; T1-2 `OK (4 tests, 14 assertions)`.
- Pre-existing failures confirmed **unchanged** by stashing the fix and re-running:
  `NationalIdVerificationTest` = 7 tests / 2 failures both with and without the fix;
  `AdminBanControllerTest` = 29 tests / 26 errors both with and without the fix. Neither is caused by
  T2-1 (they fail in `setUp`/token issuance via `/api/admin/login`, the `config('admin.*')['email']`
  root cause already recorded as T3-8).
- `php -l` clean. No stashes left behind (`git stash list` empty).

**Final state: VERIFIED FIX**

**Genuinely unverified:**
- Not exercised through a live RoadRunner/Octane server; verified via in-process HTTP through the real
  middleware and routes.
- No test asserts the `AdminUserController`/`AdminDriverController` admin-photo lookups
  (`adminUserId` → `Profile::where('user_id', ...)`). These are read-only display fields; the resolver
  now supplies a valid id, and since shadow Users are created without a `Profile` row, those endpoints
  previously returned `admin_photo: null` and still will until a profile exists for the shadow User.
  **This is a known cosmetic limitation, not a regression** — the field was already always null.
- Whether production `employees` rows all have emails set is unknown (not readable from here). For an
  email-less employee the resolver returns null, i.e. the old NULL-attribution behaviour is preserved
  rather than fixed. `SpecialAccountSeeder` requires `SYSTEM_ADMIN_EMAIL`/`SYCASH_EMAIL`, so the
  privileged accounts are expected to have one.
- The design choice leaves **T3-4 open**: `wallet_requests.processed_by` still points at `users` while
  the actor is conceptually an employee. That is intentional and recorded, not overlooked.

## T2-2 — VERIFIED FIX (original premise partly DISPROVED; a different, real bug fixed instead)

**Original finding (Part 1):** "The admin-panel role gate contradicts the token issuer; the financial-admin
role is locked out." Claimed that `{admin, system_admin}` (routes) and `{system_admin, sycash}`
(`isAdminRole()`) intersect only at `system_admin`, that `sycash` is 403'd everywhere, and that `admin`
"can never obtain a token, since isAdminRole() excludes it".

**Verified / current finding — two of the three claims are FALSE.** Ground truth was captured with a
temporary read-only probe driven through the real HTTP endpoints (probe since deleted; it asserted
*observed* behaviour, not desired behaviour):

| role | `/api/admin/login` | `/api/staff/login` | `GET wallet/requests` | `GET reports` | `GET wallet/` |
| --- | --- | --- | --- | --- | --- |
| system_admin | YES | YES | 200 | 200 | 200 |
| sycash | YES | YES | 403 | 403 | 403 |
| admin | **NO** | YES | 200 | **403** | **RuntimeException → 500** |
| support_agent | NO | YES | 403 | 403 | 403 |

- **DISPROVED: "`admin` can never obtain a token."** `/api/staff/login` routes to
  `EmployeeAuthService::authenticate()` (`:31-48`), which checks only `is_active` and has **no role gate**.
  `admin` therefore gets a token there and is then admitted by `staff:admin,system_admin` (`routes/api.php:289`).
- **DISPROVED: "the financial administrator is locked out, defeating the intended sycash financial role."**
  `sycash` is **not a persona — it is a system wallet**. Confirmed by the code: `config/admin.php` holds
  only `system_admin.phone` and `sycash.phone`; `config('admin.sycash.phone')` feeds `lockWalletByPhone()`
  in **8** call sites in `WalletTransactionService`, and `AdminWalletService:19` states *"This service never
  creates User rows — it only reads wallets by phone number."* The SyCash flow works through the **wallet
  row**, never through HTTP authorization, so `sycash` being 403 on admin routes is **correct and intended**
  (it is also a `isRestricted()` role that cannot manage people). There is no locked-out financial persona.
- **CONFIRMED:** `AdminJwtMiddleware` (`auth.admin`) is dead code — zero routes reference it
  (`grep` over `routes/` returned nothing), and no route lists `sycash`.

**The REAL defect found instead (reachable, user-visible):** `routes/api.php:289` granted the **`admin`**
role the entire admin panel, but `config/admin.php` has **no `admin` key** — proven at runtime:
`config('admin.admin.phone') === null` (and `support_agent` likewise). `AdminAuthService::getAdminConfigFromRequest()`
builds `phone = config("admin.$roleKey.phone")` → `null`, so `AdminWalletService::getOrCreateWallet()`
(`:35`) queried `phone_number = NULL`, found nothing, and threw
`RuntimeException("System wallet for phone [] not found")` → **HTTP 500 on `GET /api/admin/wallet`** for a
role the route table explicitly authorized. The empty `[]` in that message is the proof the config lookup
returned null.

**Root cause:** three components defined "admin" three ways, and the outer route group was broader than the
set of roles the wallet layer is configured for. `AdminAuthService:52` (*"Only system_admin and sycash may
use the admin panel"*), `AdminJwtMiddleware:64` (*"system_admin and sycash are the only two admin roles"*)
and `config/admin.php` all agreed; only `routes/api.php:289` disagreed.

**Fix chosen (user-confirmed):** stop admitting `admin` to the financial surface it is not configured for —
granting no role anything new. The whole financial surface is now consistently `staff:system_admin`,
matching the gate that `/wallet/charge`, `/reports`, `/export/pdf` and `/verifications` **already** carried
in the same file.

**Files changed:** `routes/api.php` only (+2 `staff:system_admin` groups, no removals):
- the `wallet` prefix group plus `GET /wallets` (which act on the *caller's own* system wallet);
- `POST /passengers/{userId}/charge-wallet` — a **money-moving** endpoint that credits a real balance and
  writes a `wallet_transactions` ledger row (`PassengerProfileController:330-357`).
- `tests/Feature/Admin/AdminFinancialSurfaceAuthorizationTest.php` — new regression suite (7 tests).

**Tests / checks performed:**
- `php vendor/bin/phpunit tests/Feature/Admin/AdminFinancialSurfaceAuthorizationTest.php` on MySQL 8.2.0 →
  **`OK (7 tests, 20 assertions)`**, stable across repeated runs.
- Route table re-read after the change: every financial endpoint
  (`wallet`, `wallet/{id}/transactions`, `wallet/requests`, `.../approve`, `.../reject`, `wallets`,
  `passengers/{id}/charge-wallet`, `wallet/charge`, `reports`, `export/pdf`, `verifications`) now carries
  `staff:system_admin`. `GET /admin/users`, ban/unban and the read-only passenger dashboard keep
  `staff:admin,system_admin` — the `admin` role is **not** stripped of its operational surface.
- Denied paths verified: `admin` → **403** on every financial GET plus approve/reject/charge/charge-wallet;
  `sycash` → 403; unauthenticated → 401.
- Allowed paths verified (no over-restriction): `system_admin` still gets **200** on `wallet`, `wallets`,
  `wallet/requests`, and still charges a passenger wallet successfully with the **balance actually moving**
  (asserted `5000.0` on the wallet row); `admin` still gets **200** on `/admin/users`,
  `/admin/users/{id}/status` and `/passengers/{id}/full-profile`.
- **Causality proven:** with only `routes/api.php` stashed, the suite failed with
  `Failed asserting that 500 is identical to 403` twice (the 500 being the real pre-fix defect) and the
  final `OK (7 tests, 20 assertions)` returned on restore. Re-ran 3× to confirm determinism.
- **Regression suites re-run:** T2-1 `OK (10 tests, 44 assertions)`; T1-1 `OK (10 tests, 47 assertions)`;
  T1-2 `OK (4 tests, 14 assertions)`.
- Pre-existing failures confirmed **unchanged** by stashing the fix and re-running:
  `AdminDashboardControllerTest` wallet subset = `5 tests, 3 errors` both with and without the change
  (`primaryToken(): Return value must be of type string, null returned` — the `config('admin.*')['email']`
  root cause already recorded as T3-8, not caused by T2-2).
- No route/config cache present in `bootstrap/cache`, so the stash genuinely altered routing.
  No stashes left behind; temporary probe and temp route-dump script both deleted.
- `php -l routes/api.php` clean.

**Final state: VERIFIED FIX**

**Genuinely unverified:**
- `AdminDashboardControllerTest::test_sycash_admin_can_login` and its four `test_sycash_admin_cannot_*`
  siblings were **already failing before this change** (baseline: `test_sycash_admin_can_login` =
  `Failed asserting that 401 is identical to 200`) and still fail. They belong to the T3-8 class
  (`config('admin.*')['email']` removed) and assert the old role model. **They were deliberately not
  rewritten** — reconciling stale expectations is tracked separately and is not part of T2-2.
- Not exercised through a live RoadRunner/Octane server; verified via real in-process HTTP routing.
- Whether any *production* `employees` row actually holds the `admin` role (and was therefore relying on
  the accidentally-working wallet path) is unknown from here — such a row would have been getting 500s, so
  the change can only remove a failure, not introduce one.
- The wider question of whether `admin` should eventually get its own configured system wallet (which would
  make `/admin/wallet` meaningful for it) is left as an open **design** question, not fixed here.
- **T3-4 remains open** and untouched, as before.

## T2-3 — VERIFIED FIX (defect was deeper than the original audit described)

**Original finding (Part 1):** "OTP attempt limit is defined but never enforced; codes are brute-forceable
for their full 10-minute lifetime." Cited `Otp::incrementAttempts()` (`:59-62`) as defined; `isValid()`
(`:42`) and `scopeActive()` (`:79`) both gating on `attempts < 3`; `EmailOtpService:80-109` and
`WhatsAppOtpService:103-133` calling `isValid()` but never `incrementAttempts()`; lookup via
`active()` in `OtpRepository:30-39`; only `throttle:auth` on `routes/api.php:98-110`. Recommended fix:
*"Call incrementAttempts() on every failed verification … Prefer a per-identifier attempt counter."*

**Verified / current finding — the original description was correct in substance but UNDERSTATED the
defect, and its recommended fix would NOT have worked.** All cited lines were confirmed at the current
HEAD: `incrementAttempts()` has **zero call sites** in the entire codebase (grep returns only the
definition), `attempts` is written as `0` at creation in all three send paths and never changed again, so
the `attempts < 3` guard in `isValid()` and `scopeActive()` is permanently true.

**The overlooked mechanism (root cause):** `OtpRepository::findByPhoneAndCode()` filtered the *query* on
the guessed value:

```php
->where('phone_number', $phoneNumber)->where('otp_code', $code)->active()->first();
```

A wrong guess therefore matched **no row** and returned `null`. The guard `if (!$otp || !$otp->isValid())`
returned early with nothing to increment. This is why the audit's suggested fix ("call
incrementAttempts() on every failed verification") would have failed as written — **at the point of a
failed verification there was no `$otp` object in hand to increment.** The cap was not merely unenforced;
it was structurally unreachable. The lookup had to be split in two before any counter could work.

**Reachability:** 4 verify paths shared the defect — `EmailOtpService::verifyOtp()`,
`WhatsAppOtpService::verifyOtp()`, `TextMeBotOtpService::verifyOtp()` (all three via
`findByPhoneAndCode()`). The email path backs `POST /api/auth/password/verify-otp`, whose success issues
a `reset_token` accepted by `/api/auth/password/reset` — i.e. the account-takeover impact the audit
described. Note `findByPhoneAndCode()` remains defined but now has **no callers**; it was left in place
rather than deleted to keep the change minimal (see unverified items).

**Fix applied (smallest correct change, root cause):**
1. `OtpRepositoryInterface` / `OtpRepository` — added `findLatestByPhone(string): ?Otp`, which selects the
   newest row by identifier **without** filtering on code, verification state, expiry or attempts.
2. `Otp` — added `public const MAX_ATTEMPTS = 3` (single source of truth, replacing the two hardcoded `3`s
   in `isValid()`/`scopeActive()`); added `matchesCode(string): bool` using `hash_equals()` for constant-time
   comparison (the code is no longer compared inside SQL); added `registerFailedAttempt(): bool` which
   increments and reports whether the code is burned.
3. All three services — look the row up with `findLatestByPhone()`, gate on `isValid()`, then compare the
   guess via `matchesCode()`, calling `registerFailedAttempt()` on mismatch. A correct code still verifies
   and does **not** consume the allowance.

Because `isValid()` re-checks `attempts < MAX_ATTEMPTS` on every call, the row becomes invalid the moment
the cap is hit — the correct code is refused afterwards. No schema change, no migration, no behaviour
change to the send paths.

**Files changed:** `app/Models/Otp.php`, `app/Repositories/OtpRepository.php`,
`app/Interfaces/OtpRepositoryInterface.php`, `app/Services/EmailOtpService.php`,
`app/Services/WhatsAppOtpService.php`, `app/Services/TextMeBotOtpService.php`;
new `tests/Feature/Otp/OtpAttemptLimitTest.php`. No route, config or migration changes.

**Tests / checks performed:**
- New suite `OtpAttemptLimitTest` → **`OK (13 tests, 55 assertions)`**, deterministic across 3 consecutive
  runs. Covers: a wrong guess is recorded; attempts accumulate; **the code is burned at the cap and the
  correct code is then refused**; correct code still works first try (and does not count as a failure) and
  below the cap; expired code rejected; verified code not replayable; the password-reset flow specifically
  yields **no `reset_token`** after the cap; the repository returns the live row for a non-matching guess;
  `matchesCode()` exactness; `registerFailedAttempt()` burn signal.
- **Causality proven** by stashing only the six source files (leaving the new test in place): the suite then
  failed `8` of 13, including the decisive `Failed asserting that 0 is identical to 1` — i.e. `attempts`
  stayed **0** after a wrong guess, which *is* the vulnerability — plus `0 is identical to 3` in both the
  phone and password-reset flows, and 4 errors from the absent new methods. Restored → `OK (13/55)`.
- Existing OTP/email suites re-run: `CleanupExpiredOtpsCommandTest` `OK (7/15)`,
  `ResetPasswordControllerTest` `OK (16/23)`, `VerifyOtpMiddlewareTest` `OK (6/11)`,
  `OtpSentTest` `OK (10/10)`, `VerifyEmailOtpRequestTest` `OK (16/28)`,
  `SendEmailOtpRequestTest` `OK (16/23)`, `AppServiceProviderTest` `OK (18/19)`.
- Prior-task regressions re-run: T2-1 `OK (10/44)`, T2-2 `OK (7/20)`, T1-1 `OK (10/47)`.
- **Pre-existing failures confirmed IDENTICAL with and without the fix** by stashing it: `OtpTest`
  `11 tests / 2 failures` (`test_send_otp_fails_with_missing_number`,
  `test_otp_phone_number_is_required_for_verify`), `TextMeOtpControllerTest` `9 tests / 2 failures`,
  `EmailVerificationControllerTest` `17 tests / 2 failures` — all four are **422-vs-401 / validation-shape
  mismatches** (`Failed asserting that an array has the key 'phone_number'` / `'email'`), entirely
  unrelated to attempt counting and not caused by this change.
- No test double implements `OtpRepositoryInterface` (grep over `tests/` found only `app()` resolution in
  the provider test and the new suite), so adding a method broke no fake. Adding a method to the interface
  is therefore safe; `AppServiceProviderTest` `OK (18/19)` confirms the binding still resolves.
- Architecture self-check on `app/`: **no cycles, no fan-in/fan-out hotspots**; the 5 oversized modules
  reported are pre-existing (`RideController` 965 lines etc.) and untouched by this change.
- All six changed files `php -l` clean. No stashes left behind; temp route-dump script deleted.
- Live DB behaviour is production-compatible: `otps.attempts` is a plain `integer DEFAULT 0`
  (`2025_07_11_113735:22`), and `increment()` emits a plain `UPDATE … SET attempts = attempts + 1`.

**Final state: VERIFIED FIX**

**Genuinely unverified:**
- `findByPhoneAndCode()` is now **dead code** (definition + interface entry retained, zero callers). Deleting
  it would be safe but is a separate cleanup and was deliberately not bundled into a security fix.
- The route limiter itself (`throttle:auth`, IP-keyed, shared behind NAT) was **not** changed — the audit's
  own T2-10 concern about its true effective rate remains open and is not addressed here.
- The audit's further suggestions — per-identifier attempt counter with a lockout window, longer codes,
  single-use-per-session — were **not** implemented; the fix enforces the cap that already existed rather
  than redesigning the OTP scheme. A per-row counter is resettable by requesting a new code
  (`deleteByPhone()` + fresh row), which is bounded by the existing 3-per-5-minute send limit.
- `attempts` is incremented with a read-modify-write via `increment()`; under true concurrent guessing the
  counter could lose an update, though the cap still cannot be bypassed. Not load-tested.
- Not exercised through a live RoadRunner/Octane server or a real mail provider; verified via real
  in-process HTTP routing with the DB-backed OTP rows.
- `OTP_BYPASS_ENABLED` / local-testing bypass (`WhatsAppOtpService:151-185`) is **unused dead code** —
  `isBypassMode()` and `createBypassOtpRecord()` have no callers. Left untouched.

## T2-4 — VERIFIED FIX

**Original finding (Part 1):** "Signup allows an unverified account's password to be overwritten without
proof of email ownership." Cited `SignupController:57-97` (specifically `:68-71`), OTP echo at `:93-95`
and `:151-153`. Recommended fix: *"Do not treat password mutation as part of 'resend OTP'. Return a neutral
'check your email' response, keep the existing password … Never echo OTP codes in API responses."*
Confidence High; confirmed bug for the overwrite, conditional for the echo.

**Verified / current finding — CONFIRMED exactly as described.** At path A (`$existingUser` present,
`email_verified_at === null`) the controller executed:

```php
$existingUser->password = Hash::make($request->password);
$existingUser->save();
```

before sending a fresh OTP, and then returned the account's `id`, `first_name` and `email` plus
`otp_code` when `isset($otpResult['otp_code'])`. Path B echoed `otp_code` the same way. Nothing in either
path proved the caller controlled the mailbox. **The echo is a project-wide pattern** — the same
`if (isset($result['otp_code']))` is repeated in `WalletController:87-89` and
`ForgotPasswordController:77-79`; T2-4 is scoped to the two lines the audit cites in `SignupController`,
and the other two sites are recorded as still-open below.

**Impact, stated precisely:** an attacker who knew any abandoned (unverified) address could set that
account's password to a value of their choosing. `LoginController:66` does block unverified accounts with
`403 EMAIL_NOT_VERIFIED`, which **bounds immediate access** — but the credential corruption persists, so
once the legitimate owner completes verification their original password no longer works while the
attacker's chosen value does. The pre-fix causality run demonstrated this end-to-end (below). The OTP echo
is worse where reachable: with `EMAIL_OTP_MODE=testing` (or any non-production default) the code is handed
straight to the caller, completing the takeover without mailbox access.

**Fix applied (smallest correct change, both facets the audit named):**
- Path A no longer touches the password at all and no longer echoes account identity — it now returns only
  `{status, message}`. The password can now only change through the OTP-verified reset flow under
  `/api/auth/password/*`.
- Both paths no longer include `otp_code` in any response.
- No dev capability was lost: the code is still written to the log by
  `EmailOtpService::sendOtp()` (`:50`, `Log::info("Email OTP (testing) for …")`), which is the
  `app()->environment('local')`-style log-only affordance the audit recommended.
- Path B's legitimate new-user response shape is unchanged apart from the removed `otp_code`.

**Files changed:** `app/Http/Controllers/API/SignupController.php` only;
new `tests/Feature/Auth/SignupPasswordOverwriteTest.php`. No route, config, DTO or service changes.
`use Hash` is retained — still used by path B at `:120`.

**Tests / checks performed:**
- New suite `SignupPasswordOverwriteTest` → **`OK (12 tests, 43 assertions)`**, deterministic across 3
  consecutive runs. Asserts the stored hash is bit-identical after the attack, `Hash::check` succeeds for
  the original password and **fails** for the attacker's, repeated hammering cannot converge on the
  attacker's value, the victim can still log in after verifying, the attacker password is rejected at
  login, no `otp_code` is echoed for either an existing-unverified or a brand-new email (with
  `EMAIL_OTP_MODE=testing` forced on), the neutral response leaks no `user` identity, and the pre-existing
  behaviours survive (new user created unverified, `409` for a verified duplicate, resend still answers
  200, password confirmation still validated).
- **Causality proven** by stashing only `SignupController.php` (leaving the test in place): the suite then
  failed **7 of 12**, including the decisive `test_the_attacker_password_is_rejected_at_login` →
  `Failed asserting that 200 is identical to 401` — pre-fix the **attacker's password logged in
  successfully**, i.e. a complete account takeover reproduced end-to-end — plus `test_the_victim_can_still_log_in_after_verifying` → `401 is identical to 200` (the victim was locked out of their
  own account), `Failed asserting that two strings are identical` on the stored hash, and
  `Failed asserting that true is false` on all three OTP-echo/identity-leak assertions. Restored →
  `OK (12/43)`.
- Regression suites re-run: `AuthTest` `14 tests / 1 failure`, `EmailVerificationControllerTest`
  `17/2`, `ResetPasswordControllerTest` `OK (16/23)`, `GoogleControllerTest` `OK (5/12)`,
  `OtpAttemptLimitTest` `OK (13/55)`, T2-1 `OK (10/44)`, T2-2 `OK (7/20)`, T1-1 `OK (10/47)`.
- **Pre-existing failures confirmed IDENTICAL at baseline** by stashing **all** T1-x/T2-x source changes
  and re-running: `WalletTest` `10 tests / 10 errors` and `WalletRequestControllerTest`
  `24 tests / 9 errors / 5 failures` (both `ErrorException: Undefined array key "email"` — the
  `config('admin.*')['email']` T3-8 class), `AuthTest` `14 tests / 1 failure`
  (`test_user_can_register` → `500 is identical to 201`, because that test never sets
  `EMAIL_OTP_MODE=testing` and therefore reaches real SMTP), `EmailVerificationControllerTest` `17/2`.
  All identical with and without this change.
- Architecture self-check on `app/`: **no cycles, no fan-in/fan-out hotspots** (the 5 oversized modules are
  pre-existing and untouched). `grep` over `resources/` found no frontend dependency on the removed
  `otp_code`/`user` keys. `php -l` clean. No stashes left behind.

**Final state: VERIFIED FIX**

**Genuinely unverified / still open:**
- **Two further `otp_code` echo sites were deliberately NOT changed** because T2-4's file scope is
  `SignupController`: `WalletController:87-89` and `ForgotPasswordController:77-79`. They share the same
  conditional-echo pattern and the same risk profile. Existing tests actively depend on the echo at those
  sites (`EmailVerificationControllerTest::test_send_exposes_otp_code_in_testing_mode`,
  `ResetPasswordControllerTest`, `WalletTest`), so removing them is a broader behavioural change that
  needs its own task rather than being bundled here. **Recorded as a new finding below.**
- `EmailVerificationController::send()/resend()` return the service result **verbatim**
  (`response()->json($result, …)`), so they forward `otp_code` whenever the service includes it. Same
  class of issue, equally out of scope for this task.
- The `409` on a verified duplicate still confirms that a verified account exists (an enumeration aid the
  unverified branch no longer provides). Pre-existing and unchanged; not addressed.
- Whether production runs with `EMAIL_OTP_MODE=production` is not verifiable from here — the fix removes
  the dependency on that being correct, which is the point.
- Not exercised through a live RoadRunner/Octane server or a real mail provider; verified via real
  in-process HTTP routing.
- **NEW FINDING (discovered while writing the T2-4 test, not in the original audit):**
  `UserRepository::createUser()` (`:27-40`) **hardcodes `'status' => 1`** and ignores
  `$data['status']`. `SignupController` path B explicitly passes `'status' => 0` with a comment claiming
  *"was 1 (active) — user must verify email before they can log in … starting at 0 adds a second layer of
  defence"*, but that intent is silently discarded. The `users.status` column defaults to `1` anyway, so
  the effective behaviour is unchanged — the second layer of defence described in that comment **does not
  exist**. Login is actually gated solely by `email_verified_at` (`LoginController:66`), which does hold.
  Left unfixed: it is a distinct defect, and fixing it would change login/status semantics beyond T2-4.


## New issue discovered during T1-2 work (not in the original audit)

- **A live database credential is present in `phpunit.xml` in the working tree** (DB host, port,
  database name, username and password keys — values deliberately **not** recorded here). At the time
  of discovery it was **worktree-only and never committed** (verified:
  `git log --all -S "<host token>" -- phpunit.xml` returned nothing), and `phpunit.xml` is a tracked
  file, so a single `git add` would commit a live credential. The same file also contains a
  generated-API-key value for a routing service.
  **Status: NOT STARTED. Report to the repository owner; do not commit `phpunit.xml` until resolved.**
  This is directly adjacent to T1-3 (secrets in the repository).

## Pre-existing conditions confirmed during remediation (not caused by, and not fixed by, T1-1/T1-2)

- `tests/Feature/Rides/RideControllerFullTest.php` — **39 of 39 errors** in `setUp`. Root cause:
  `config('admin.*')['email']` / `['password']` no longer exist, because `config/admin.php` now holds
  only `phone`/`wallet_prefix`; credentials moved to the `employees` table /
  `SpecialAccountSeeder`. Identical with the fixes stashed, so pre-existing. This is the same class of
  issue as original-audit **T3-8**.
- `tests/Feature/Payment/WalletTransactionServiceTest.php` — **27 of 27 errors** in `setUp`, same root
  cause.
- Default `phpunit.xml` is SQLite while the `rides` table uses spatial indexes, so the ride feature
  suite cannot run on the default configuration at all (original-audit **T3-6** /
  **T4-1**).
- `tests/Unit/Models/ComplaintTest` fillable failures and 17 unit errors are identical pre- and
  post-fix. `tests/Unit/Enums/RideStatusTest.php` alone passes 13/13.
- `AdminReportService.php:290` still counts `awaiting_confirmation` rows for reporting, which now
  diverges from the normalised `launched` state established by T1-1. Behaviour unchanged by the fix;
  a genuine leftover (candidate finding).

## Hypotheses re-tested during remediation and confirmed/disproved

- **T1-2 "enum insert crashes": DISPROVED** — column is `varchar(255)` after a clean migration
  (measured). See above.
- **T1-1 "driver finish / awaiting_confirmation deadlock is the live bug": SUPERSEDED** — that path
  is unreachable via HTTP because the controller already stubs it. The live defect was the service's
  own stale status gate. Both are described above.
- **Double-payout surface:** confirmed structurally present but currently **unreachable**, because
  `RideService::checkAndCompleteRide()` is only reached from the stubbed driver action, and
  `Booking::markPassengerConfirmed()` has no callers. Do **not** refactor toward a single release
  site without re-verifying.

## T2-5 — VERIFIED FIX

**Original finding (Part 1):** "Production deploy configuration ships literal database credentials,
exposes phpMyAdmin, and mounts the source tree over the built image." Cited `docker-compose.yml`
`:16,51,85,120,155,201,207,222,241` (eleven literal `"secret"` values, including `-psecret` in the
healthcheck), `:22-27,56-61,90-95,124-129,159-164` (bind-mounts of `./app`, `./config`, `./routes`,
`./database`), `:232-241` (phpMyAdmin on 8081 with the root password), and
`app/Console/Commands/Getloadtesttokens.php:159` (prints `mysql -uroot -psecret`). Recommended fix:
*"Move all credentials to env/secret injection with no literal defaults; do not publish MySQL or
phpMyAdmin ports; remove the application-source bind-mounts from production (keep them only in an
override file for local dev) so the image is what runs; require TLS to the DB."* Confidence High.

**Verified / current finding — the three core claims CONFIRMED.** Every cited line was checked at HEAD
`993fab5`: exactly **11** occurrences of the literal `"secret"` (`DB_PASSWORD` on app1–app5/queue/
scheduler, `MYSQL_ROOT_PASSWORD` on `mysql`/`mysql_replica`/`phpmyadmin`, plus the healthcheck's
`-psecret`); source bind-mounts on all five replicas plus queue and scheduler; phpMyAdmin published on
`8081:80` with `MYSQL_ROOT_PASSWORD: "secret"`; and the `-psecret` help string at
`Getloadtesttokens.php:159`. `Dockerfile:72` does `COPY . .`, so the multi-stage build **did** contain
the application code and the bind-mounts really did discard it at runtime — the audit's central point
is correct.

**Two premises in the original audit were imprecise (recorded, not silently "corrected"):**
1. **MySQL port 3306 is NOT published.** Only two services declare `ports:` — `nginx`
   (`127.0.0.1:8080:80`, loopback) and `phpmyadmin` (`8081:80`, all interfaces). The audit's trigger
   *"Reach port 3306 or 8081 and log in as root with secret"* is therefore only half reachable from
   outside: 3306 was never exposed, so the "network-adjacent compromise of the database" is via
   phpMyAdmin, not a direct MySQL connection. The weak-credential finding itself still stands, because
   phpMyAdmin was genuinely exposed with it.
2. **"phpMyAdmin on public IPs"** overstated the nginx case, which is bound to loopback. phpMyAdmin
   (`8081:80`) *was* bound to all interfaces and is the real exposure.

**Fix applied (the audit's recommended direction, in full):**
- **No literal credentials anywhere.** All eleven values now come from the environment via
  `${DB_PASSWORD:?...}` / `${MYSQL_ROOT_PASSWORD:?...}`. The `:?` form makes `docker compose up`
  **hard-fail with a named error** when a value is missing; the `:-default` form is deliberately unused,
  so there is no silent fallback to a known password.
- **Healthcheck no longer carries the password on its argv.** It now uses `mysqladmin ping -h localhost
  -u root` with `MYSQL_PWD` supplied through the container environment, so the secret does not appear in
  `ps`/`docker inspect` output the way `-psecret` did.
- **phpMyAdmin removed from the production file** entirely, and **no MySQL port is published** (MySQL and
  the replica are reachable only on the compose network — unchanged for the replica, now explicit for
  both).
- **Application-source bind-mounts removed from production**; the container now runs the artifact
  `Dockerfile` built. Only `./storage/logs` remains mounted.
- **Local dev convenience preserved in a new `docker-compose.dev.yml`**: the exact original per-service
  mount set, plus phpMyAdmin bound to **`127.0.0.1:8081`** (not all interfaces). Devs opt in with
  `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`. It introduces no new literals —
  credentials still come from the environment.
- `Getloadtesttokens.php:159` no longer prints a password; it now uses `-p` (interactive prompt) and reads
  the database name from config.
- New **`.env.example`** added: the variable *names* the compose file requires, with no real values. It
  was genuinely missing (only `.env` existed, untracked), which is why the hard-fail would otherwise have
  been undebuggable. Verified **not** git-ignored (`.gitignore:42` has `!.env.example`) and **not**
  excluded from the image (`.dockerignore` has `!.env.example`), so it ships without shipping secrets.

**Files changed:** `docker-compose.yml` (rewritten, production-shaped), new `docker-compose.dev.yml`,
new `.env.example`, `app/Console/Commands/Getloadtesttokens.php` (help-string + lint only).

**Tests / checks performed:**
- `docker compose -f docker-compose.yml config` **fails with exit 1** and the named message
  `required variable MYSQL_ROOT_PASSWORD is missing a value: MYSQL_ROOT_PASSWORD must be set - see
  .env.example` when no variables are set — the hard-fail behaviour is **proven**, not asserted.
- With values injected, **both** `docker compose -f docker-compose.yml config` and
  `docker compose -f docker-compose.yml -f docker-compose.dev.yml config` parse cleanly (`--quiet`,
  exit 0).
- Resolved production config verified programmatically: services are `app1`–`app5, mysql, mysql_replica,
  nginx, queue, redis, scheduler` — **`phpmyadmin` absent**; **zero** source bind-mounts (only
  `storage/logs`); the only published host port is `host_ip: 127.0.0.1 / published: "8080"`; the
  healthcheck test resolves to `CMD mysqladmin ping -h localhost -u root` with **no** `-psecret`;
  `MYSQL_PWD` is present in both MySQL services' environments.
- The `"secret"` substring appearing in the resolved config was tracked down by **key name only** (values
  never printed): the remaining hits are all variable *names* — `JWT_SECRET`, `AWS_SECRET_ACCESS_KEY`,
  `GOOGLE_CLIENT_SECRET`, `PUSHER_APP_SECRET`, `STRIPE_SECRET` — pulled in by `env_file: .env`
  expansion. **No literal password value remains.**
- Repo-wide sweep (excluding `vendor`/`node_modules`/`storage`) for `psecret` across `*.yml,*.yaml,*.php,
  *.sh,*.conf`: **zero matches**. `php -l` on the changed command: clean.
- Dev override verified to restore the **exact** original mount set per service (app1: app, database,
  config, routes, phpunit.xml; app2–app5: app, database, config, routes; queue: app, config; scheduler:
  app) and to publish phpMyAdmin on `host_ip: 127.0.0.1` / `published: "8081"`.
- `render.yaml` checked for the same defect: it declares only `PORT`, **no credentials** — clean.
  `docker/mysql/replica-init.sh` already reads `${MYSQL_ROOT_PASSWORD}` from the environment — clean, no
  change needed. `primary.cnf` / `replica.cnf` contain no credentials.
- Architecture self-check on `app/`: no cycles, no fan-in/fan-out hotspots; the 5 oversized modules are
  pre-existing and untouched (the only PHP file changed is one help string in a console command).

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations:**
- **Docker Desktop cannot start in this environment** (`Docker Desktop is unable to start`), so
  `docker compose up` / `build` was **never** run. Verification is therefore limited to: compose schema
  and interpolation parsing (which does exercise the hard-fail and the merge of the dev override),
  programmatic inspection of the fully-resolved config, and static file checks. The containers were not
  started and no live healthcheck ran. This is a real gap and is the main thing to re-confirm on a host
  with a working daemon.
- **Breaking change for existing environments, deliberate:** because `MYSQL_ROOT_PASSWORD` is now
  required and `.env` did not previously define it (only `DB_PASSWORD`/`DB_USERNAME` were present;
  `DB_USERNAME` is **not** root), `docker compose up` will fail until it is added — copy `.env.example`
  and set it. This is the intended replacement for the silent `secret` default, but it means the next
  deployment needs the variable provisioned **before** the workflow runs.
- **Not addressed (out of scope, recorded):** the audit's fourth recommendation, *"require TLS to the
  DB"*, is **not implemented** — this compose talks to a plaintext local `mysql:8.0`. `.env` does carry
  `MYSQL_ATTR_SSL_CA` and `docker/start.sh:49-51` sets `PDO::MYSQL_ATTR_SSL_CA`, so an SSL-capable path
  exists, but no `--require-secure-transport`/TLS enforcement was added to the MySQL service here.
- `DB_PASSWORD` is still shared with the app's own user rather than a dedicated least-privilege account
  (`.env.example` recommends one, but no grant/role migration was written).
- **NEW FINDING (discovered during T2-5, not in the original audit — NOT fixed):**
  `.github/workflows/deploy-to-vps.yml:60,63` runs `docker compose exec -T app php artisan migrate
  --force` and `... optimize`, but **no service named `app` exists** — the web services are `app1`
  through `app5`. Both commands would fail on deploy (`no such service: app`), so the migration and
  optimize steps have never worked as written. The workflow also triggers on branch `samer`, which does
  not exist in this repository (only `main`), so it would not run on current pushes at all. Left
  unfixed: changing deploy automation is an owner decision, and it is a distinct defect.
- The audit's cited line numbers describe the **pre-fix** file; `docker-compose.yml` was rewritten, so
  those numbers no longer map. The original snapshot in Part 1 was preserved verbatim as required.

## T2-6 — VERIFIED FIX

**Original finding (Part 1):** "Unauthenticated infrastructure disclosure and exception-message leakage
on health/debug endpoints." Cited `routes/api.php:64-92` (`/api/test-db` returns DB name, host and table
count; the code's own comment at `:65` says to remove it before production), `routes/api.php:452`
(`/api/health` returns `gethostname()`), `routes/web.php:9-17` (`/up` returns
`'Database unavailable: ' . $e->getMessage()`), and `render.yaml:5` (healthcheck path is `/up`).
Recommended fix: *"Delete /api/test-db and /api/health (or restrict to an internal network /
authenticated admin). /up should return a bare status code and log the exception server-side; the
healthcheck only needs 200 vs 500."* Confidence High, confirmed bug.

**Verified / current finding — CONFIRMED in full, at the exact cited lines.**
- `routes/api.php:73-92` — `/api/test-db` returned `database`, `host`, `tables_count`, and on failure the
  raw `$e->getMessage()` plus a `config` block with host/database/port. Its own comment at `:65` read
  "Remove /test-db before production (exposes DB config)".
- `routes/api.php:470` — `/api/health` returned `['status' => 'ok', 'node' => gethostname()]`.
- `routes/web.php:15` — `/up` returned `'Database unavailable: ' . $e->getMessage()`.
- `render.yaml:5` — `healthCheckPath: /up`, confirming `/up` is public by design.

**The unauthenticated/unthrottled premise was measured, not assumed.** The live route table was dumped
at runtime and all four routes carried only their group middleware with **no auth and no throttle**:
`up mw=[web]`, `api/test-db mw=[api]`, `api/health mw=[api]`, `api/ping mw=[api]`, `api/test mw=[api]`.

**Why it mattered beyond topology:** `/up` and `/test-db` each caught the exception **inside the route
closure**, so the exception never reached `app/Exceptions/Handler.php:57-63` — which correctly gates the
message on `config('app.debug')`. That gate was therefore bypassed entirely, and both endpoints leaked
raw driver text in production regardless of `APP_DEBUG`. This is the mechanism the original audit did
not spell out and it is why the fix had to change the endpoints themselves rather than the handler.

**Fix applied (exactly the audit's recommended direction):**
- **`/api/test-db` deleted.** No consumer existed anywhere in the repo (grepped across PHP/JS/YAML/SH/
  k6 scripts) and no test referenced it — including the audit's suggested alternative of restricting it,
  deletion was the smaller correct change.
- **`/api/health` deleted.** Nothing in the repository consumed it; the platform healthcheck is `/up`
  (`render.yaml:5`). A comment records why, and notes that a future internal probe belongs behind the
  internal network or admin middleware rather than public.
- **`/up` now returns a bare `'Service unavailable'` (500)** on failure and logs the exception
  server-side via `Log::error` with the exception class and message. Success still returns `'OK'` (200),
  so the healthcheck's 200-vs-500 contract is unchanged and `render.yaml` needs no edit.
- Removed the now-unused `use Illuminate\Support\Facades\DB;` import from `routes/api.php`; added
  `use Illuminate\Support\Facades\Log;` to `routes/web.php`. `DB` is still used by `/up` in `web.php`.
- **`/ping` and `/test` deliberately left alone.** They return fixed constants and disclose nothing about
  the backing services, and eight `k6-load/*.js` scripts plus `RouteServiceProviderTest` exercise them;
  removing them would have broken load tooling for no security gain. This is a scope decision, recorded
  rather than silently taken.

**Files changed:** `routes/api.php`, `routes/web.php`; new
`tests/Feature/Security/DebugEndpointDisclosureTest.php`.

**Tests / checks performed:**
- New suite `DebugEndpointDisclosureTest` → **`OK (9 tests, 211 assertions)`**, deterministic across 3
  consecutive runs. It exercises the real routing layer: both removed routes now `404` and are absent
  from the route table; `/up` was driven with a **mocked throwing `DB::select`** (a `PDOException`
  shaped like `SQLSTATE[HY000] [1045] Access denied for user "<db-user>"@"<internal-ip>" using
  password: YES`, using placeholder values — the real username and IP are deliberately not recorded
  here) and the response body was asserted to contain **none** of `SQLSTATE`, `1045`, the username,
  `Access denied`, the IP, `using password`; `/up` returns exactly `'Service unavailable'` on
  failure and exactly `'OK'` on success; `/ping` returns `{"ok":true}` exactly; `/api/test` leaks none of
  `database`/`host`/`port`/`tables_count`/`gethostname`; and no registered route action contains
  `gethostname`.
- **Causality proven** by stashing only `routes/api.php` + `routes/web.php` (leaving the test in place):
  the suite failed **5 of 9**, with `Failed asserting that 200 is identical to 404` for both removed
  routes, `Failed asserting that an array does not contain 'api/test-db'`, and — decisively — the
  pre-fix body captured verbatim (credentials redacted here; the raw string is in the run log):
  `GET /up leaked 'SQLSTATE' from the database exception. Failed asserting that 'Database unavailable: SQLSTATE[HY000] [1045] Access denied for user "<db-user>"@"<internal-ip>" using password: YES' does not contain "SQLSTATE"`.
  That single assertion demonstrates the disclosure end-to-end: the SQLSTATE code, the database
  username, an internal IP and the auth-failure mode, all in an unauthenticated response body.
  Restored → `OK (9/211)`.
- The `Log::error` call was **independently observed firing** in the test run's stderr
  (`testing.ERROR: Healthcheck /up failed: database unavailable {"exception":"PDOException","message":...}`)
  — confirming the detail is now captured server-side rather than returned to the caller, which is
  exactly the audit's requirement that it be logged instead of disclosed.
- Regression suites: `RouteServiceProviderTest` (touches `/ping`) `OK (12/14)`, T1-1 `OK (10/47)`,
  T2-1 `OK (10/44)`, T2-2 `OK (7/20)`, T2-3 `OK (13/55)`, T2-4 `OK (12/43)`,
  `ResetPasswordControllerTest` `OK (16/23)`, `GoogleControllerTest` `OK (5/12)`. `AuthTest` `14/1` —
  the same **pre-existing** `test_user_can_register` 500 (real-SMTP, no `EMAIL_OTP_MODE`), unchanged.
- `php -l` clean on both changed route files. No stale `bootstrap/cache/routes-v*.php` exists (verified;
  the deploy workflow runs `optimize`, which rebuilds it), so no cached route table masks the removal.
- Architecture self-check on `app/`: no cycles, no fan-in/fan-out hotspots; the 5 oversized modules are
  pre-existing and were not touched.

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations:**
- Verified through Laravel's real in-process HTTP kernel, **not** through a live RoadRunner/Octane server
  or a deployed edge (nginx/CDN). The global middleware stack as configured in production is the same
  path exercised, but no live request was made.
- The `/up` failure branch was verified with a **mocked** `DB::select` throwing a realistic
  `PDOException`, not by taking a real database offline. The leak shape is therefore representative of a
  driver failure, and the pre-fix causality run shows the true prior body, but an actual outage was not
  staged.
- **`/api/test-db` removal is a breaking API change for any external consumer.** None exists in this
  repository, but anything outside it that probed `/api/test-db` will now receive `404`. Given the
  endpoint was documented as removal-pending and returns DB config, this is intended.
- `phpunit.xml` still contains a **live database credential** (DB host, port, username, password) and an
  API key, and the file is **tracked in git**. This was already discovered and recorded earlier in Part 2
  (**Status: NOT STARTED**); it was re-encountered while reading the test configuration and is **not**
  re-recorded or changed here, and the values are deliberately not reproduced in this record.
  `phpunit.xml` is also excluded from the image by `.dockerignore`, but it remains in version control.
  **It still needs owner action.**
- Not addressed (separate findings, not T2-6): the `GoogleController` handlers echo `$e->getMessage()`
  and Guzzle response bodies into `$errorMessage` (`:135,145,156`), and several
  `EmployeeManagementController` handlers return `$e->getMessage()` to the caller in `403`/`409`
  responses. These are authenticated/admin surfaces rather than unauthenticated ones, so they fall
  outside T2-6's stated scope, but they are the same leakage class and should be reviewed as their own
  task.

## T2-7 — VERIFIED FIX (the original *mechanism* was imprecise; the vulnerability is real but of a different kind)

**Original finding (Part 1):** "The public broadcast channel authorizes everyone." Cited
`routes/channels.php:23-25` — `Broadcast::channel('rides', fn () => true)` — with producers at
`app/Events/RideCreated.php:27` and `app/Events/RideCancelled.php:36`. Stated mechanism: *"The
authorization callback is the only access control on a private/presence channel; returning true removes
it."* Recommended fix: *"Make the channel private and authorize against an actual relationship or role
(driver/passenger on the ride, or authenticated user); remove any passenger-identifying data from the
payload if the channel must stay broad."* Confidence High, confirmed bug.

**Verified / current finding — the vulnerability is CONFIRMED, but not through the mechanism the audit
described.** The `git show HEAD:` baseline confirmed the cited lines exactly:

```php
Broadcast::channel('rides', function () { return true; });   // routes/channels.php
new Channel('rides'),                                        // both producers
```

**Three corrections to the original entry, all established from framework source rather than inferred:**

1. **`fn () => true` was never executed — it was dead code, not a permissive authorization callback.**
   The channel was a **public `Illuminate\Broadcasting\Channel`**, and a public Pusher channel is
   subscribed to *directly* with the app key: the client never contacts `/broadcasting/auth`, so the
   registered callback is never consulted. Verified in the framework: `PusherBroadcaster::auth()` is the
   only path that reaches `verifyUserCanAccessChannel()`, and it is reached solely for
   `private-`/`presence-` channel names. The audit's framing ("the authorization callback ... is the only
   access control on a private/presence channel") describes a *private* channel; this one was not private.
   **The real defect is therefore the CHANNEL TYPE in the two producers**, which is why the fix had to
   change them and not only the callback. Fixing only `channels.php` — which is what the citation
   foregrounds — would have left the hole completely open.
2. **"including passenger ... identity fields carried in the broadcast payload" is WRONG.** Both payloads
   were read in full: `RideCreated::broadcastWith()` emits ride id, `driver_id`, pickup/destination
   address, departure time, available seats, price per seat, vehicle type. `RideCancelled::broadcastWith()`
   emits ride id, addresses, departure time, the **driver's id and full name**, an
   `affected_bookings_count` integer and a timestamp. **No passenger identity is broadcast at all** — not
   even a count-by-name. So the audit's second recommendation ("remove any passenger-identifying data
   from the payload") had nothing to remove. Driver id/name and the two addresses *are* disclosed, which
   is still sensitive; the correction matters because it changes what needs fixing, not whether.
3. **The audit's "Any client holding the ... Pusher key can subscribe" statement is correct**, and the
   gap is confirmed by measuring the REST surface for comparison: **all 13 `api/rides/*` routes require
   `jwt`** (`api,jwt,throttle:api,...`), so the same ride data was publicly readable over the broadcast
   path while being authenticated-only over HTTP. That asymmetry — not the callback — is the finding.

**Root cause:** the `rides` stream was modelled as a public channel while the equivalent data is
authenticated-only in the REST layer; the `return true` callback was a decorative gesture that no code
path could reach, matching Part 1 section D's "guards that exist but are never invoked" pattern.

**Why "any authenticated user" was chosen over per-ride authorization:** the channel name is the single
global string `rides` and carries **no ride id**, so a driver/passenger-on-this-ride check is not
expressible without redesigning the channel to `ride.{id}` and rewiring every subscriber. The audit
explicitly lists "or authenticated user" as an acceptable policy, and that is exactly what the REST
surface enforces — so the fix removes the asymmetry without inventing a weaker or stronger rule.

**Fix applied (smallest correct change):**
- `app/Events/RideCreated.php`, `app/Events/RideCancelled.php` — `new Channel('rides')` →
  `new PrivateChannel('rides')`, and the then-unused `use Illuminate\Broadcasting\Channel;` import
  removed from each. **This is the change that closes the hole.**
- `routes/channels.php` — the callback now receives the user and returns `$user !== null`, so it is no
  longer an unconditional `true`. The `null` guard is deliberately defensive rather than load-bearing:
  `Broadcast::routes(['middleware' => ['jwt']])` (`BroadcastServiceProvider`) already guarantees a user,
  but the callback must not silently admit a null identity if that route guard is ever changed.
- **No payload change** — nothing identifiable-to-passenger was present to remove (correction 2).

**Files changed:** `routes/channels.php`, `app/Events/RideCreated.php`, `app/Events/RideCancelled.php`;
new `tests/Feature/Broadcast/RideChannelAuthorizationTest.php`. No migration, config or route-group
change. `BroadcastServiceProvider` was already `jwt`-gated and needed nothing.

**Tests / checks performed:**
- New suite `RideChannelAuthorizationTest` → **`OK (6 tests, 11 assertions)`**, deterministic across 3
  consecutive runs. Covers the allowed **and** denied paths:
  - *Channel type (the actual fix):* both producers target `private-rides` as a `PrivateChannel`; and a
    loop over every channel of both events asserts **none** is a public `Channel`
    (`$channel instanceof Channel && ! $channel instanceof PrivateChannel` — required because
    `PrivateChannel extends Channel`, so a naive `instanceof` would have passed pre-fix and made the
    test vacuous).
  - *Callback behaviour, invoked directly on the registered callback:* refuses `null` (denied); admits
    an authenticated `User` (allowed).
  - *Real HTTP through the jwt-guarded `/broadcasting/auth`:* anonymous subscriber → **401** (denied).
- **The authenticated HTTP round-trip is deliberately NOT asserted, and the reason was measured, not
  assumed.** Two earlier drafts did assert it (`200` + non-empty `auth` signature, and a 403 sanity
  case), and they passed 8/8 three times — then regressed. A comparison probe settled it: the
  **pre-existing** `user.{id}` channel, whose callback correctly compares ids and must therefore
  authorize for its owner but **403 for `user.{id+999}`**, returned an identical
  `status=200, content-type=text/html, body length 0` for **both** the should-allow and the should-deny
  case — exactly like `rides`. So `/broadcasting/auth`'s HTTP response is **not a trustworthy signal in
  this environment**. The root cause was then pinned down: **`phpunit.xml` sets
  `BROADCAST_DRIVER=null`** (and `config/broadcasting.php:4` reads it as `env('BROADCAST_DRIVER',
  'pusher')`), so the test process resolves a non-Pusher broadcaster whose `auth()` short-circuits and
  never consults the channel callback — while at least one earlier run evidently resolved the real
  Pusher broadcaster (it produced a genuine 403 for an unregistered channel). That run-to-run
  inconsistency across stash/pop cycles is precisely why an HTTP-level assertion of the *allow* path was
  rejected: it depends on broadcasting/env state orthogonal to the authorization control, not on the
  fix. Asserting the callback decision directly is both deterministic and closer to the actual control.
  The anonymous → 401 assertion was kept because it is enforced by the `jwt` middleware before any
  broadcaster is reached, and it was stable in every observed run.
- **Causality proven** by stashing only the three source files (leaving the test in place): the suite
  failed **4 of 6** —
  `RideCreated must target the rides channel as a PrivateChannel. Failed asserting that an array is not
  empty`; the same for `RideCancelled`;
  `App\Events\RideCreated must not expose a public channel. Failed asserting that true is false`; and
  decisively `An unauthenticated (null) user must be refused the rides channel. Failed asserting that
  true is false` — i.e. the pre-fix callback returned `true` for a null identity. Restored → `OK (6/11)`.
  No rollback was required.
- **Honest limitation of the causality result:** the one `/broadcasting/auth` HTTP test that remains
  (anonymous → 401) passes in **both** states, because the `jwt` gate on that route is unchanged by this
  fix. The decisive pre-fix failures are the channel-type and callback tests. This is expected and is
  the point: with a public channel the client never makes an auth request at all, so no route-level test
  can distinguish the two states.
- **Full regression sweep of every prior task's suite, all green:** T1-1 `OK (10 tests, 47 assertions)`,
  T1-2 `OK (4/14)`, T2-1 `OK (10/44)`, T2-2 `OK (7/20)`, T2-3 `OK (13/55)`, T2-4 `OK (12/43)`,
  T2-6 `OK (9/211)`, and `RouteServiceProviderTest` `OK (12/14)`.
- Repo-wide sweep for remaining public channels: **zero** `new Channel(` left anywhere in `app/`. Every
  other `'rides'` hit is a database-table reference (seeders, query joins), not a broadcast producer.
- `php -l` clean on all three changed files. Architecture self-check on `app/`: no cycles, no fan-in/
  fan-out hotspots; the 5 oversized modules are pre-existing and untouched. No stashes left
  (`git stash list` empty). The temporary route-inspection scripts used earlier were deleted.

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations:**
- **The real-time subscription itself was never observed end-to-end.** In the test environment
  `BROADCAST_DRIVER=null` (`phpunit.xml`), and no Pusher connection exists here, so verification stops at
  the server-side authorization decision — which is where the access control lives. That a browser then
  actually receives `ride.created` on `private-rides` against a real Pusher app was **not** confirmed.
- **Client-side impact is unverifiable and is a real breaking change for any external subscriber.**
  A public channel is subscribed as `Echo.channel('rides')`; a private one must be
  `Echo.private('rides')`. The only Echo client in this repository is `resources/js/bootstrap.js`, and it
  subscribes to **nothing** on `rides` (its sole call is `Echo.private('user.${userId}')`); a grep across
  `resources/`, `public/` build output and every `k6-load/*.js` found **no** `rides` subscriber. So no
  in-repo client breaks. Whether a mobile/Flutter client **outside this repository** subscribes
  publicly cannot be determined from here — if one does, it will silently stop receiving ride events
  until updated. This is the main thing to confirm with the client owners.
- The callback now admits **any authenticated user**, which is the audit-sanctioned policy and matches
  REST, but it is *not* the stronger per-ride relationship check the audit listed first. Implementing
  that requires redesigning the channel to `ride.{id}` plus subscriber changes — a deliberate
  deferral, not an oversight.
- **T2-8 (the committed Pusher credential that made the key obtainable) has since been CLOSED** — see the
  T2-8 section below. At the time T2-7 was worked it was still open, and the credential rotation it
  requires is still an owner action.
- The broadcast payload still carries the driver's id and full name plus pickup/destination addresses to
  every authenticated subscriber. That is now equivalent to the authenticated REST surface, so it is not
  the T2-7 defect, but it is a broader-than-necessary disclosure worth a separate decision.
- Not exercised through a live RoadRunner/Octane server; verified via real in-process HTTP routing and
  the actual registered callbacks.

## T2-8 — VERIFIED FIX (ESCALATED beyond the original finding; owner rotation still required)

**Original finding (Part 1):** "Pusher credentials are committed as in-code fallback defaults." Cited
`config/broadcasting.php:9-11`: *"key, secret and app_id are literal strings rather than env() calls with
no default."* Recommended fix: *"Convert to env('PUSHER_APP_KEY') etc. with no default, rotate the exposed
credential, and fail fast at boot if broadcasting is enabled without configuration."* Confidence High,
confirmed bug, values not reproduced.

**Verified / current finding — CONFIRMED at the exact lines, and materially WORSE than described.**
`git diff` vs `HEAD` on `config/broadcasting.php` was **empty**, proving the three literals are the
committed, tracked state — not a local edit. The audit characterised this as a robustness problem ("a
working-but-shared credential even when the environment is misconfigured"). The measurement below shows
it is a **live credential disclosure**:

| Credential (key name only) | committed literal vs live `.env` |
| --- | --- |
| `PUSHER_APP_KEY` | SHA-256 prefixes **match** — identical |
| `PUSHER_APP_SECRET` | SHA-256 prefixes **match** — identical |
| `PUSHER_APP_ID` | SHA-256 prefixes **match** — identical |

Compared by hashing both sides and printing only a 12-char digest prefix, so **no credential value was
ever printed, quoted or recorded anywhere in this work.** Conclusion: the committed defaults are **not**
placeholders — they are the production Pusher credentials, and the `PUSHER_APP_SECRET` is a
**server-side signing secret**, the kind that must never reach a client. Combined with T1-3's verified
finding that `abdurrahman8151/ATAREEKAK` is a **public** GitHub repository, the Pusher account has been
fully exposed to anyone with read access to this repository, since at least the commit that introduced
them. Concretely an attacker holding key+secret can forge channel authorisation for **any** private
channel (`user.{id}`, `conversation.{id}`) — bypassing the `isParticipant()` and id-equality callbacks
entirely — and can publish arbitrary payloads into other users' private notification channels. That is
broader than the T2-7 ride-data leak and is the reason this task was escalated rather than treated as a
config tidy-up.

**Root cause:** literal defaults passed as the second argument to `env()` in a tracked config file, while
every sibling credential in the codebase correctly uses the no-default form. Verified: the only remaining
`env(...,'literal')` occurrences for secret-shaped keys elsewhere are `config/database.php`
(`DB_PASSWORD`, ×3) and the `*_TOKEN_PREFIX` entries in `jwt.php`/`sanctum.php`, and **all of those
default to the empty string**, i.e. already safe. The pusher block was the single outlier — which supports
the audit's read that this was an oversight, not a decision.

**Fix applied (both parts of the audit's direction that are code; the third is owner action):**
- `config/broadcasting.php` — the three credential reads are now `env('PUSHER_APP_KEY')` /
  `env('PUSHER_APP_SECRET')` / `env('PUSHER_APP_ID')`, **with no default at all**. `PUSHER_APP_CLUSTER`
  deliberately **keeps** its `'ap2'` default: it is a region name, not a credential, and stripping it
  would be behaviour loss without a security gain.
- `app/Providers/AppServiceProvider::boot()` — the audit's "fail fast at boot if broadcasting is enabled
  without configuration": a production boot that selects the `pusher` driver with an empty key or secret
  now throws `RuntimeException` naming the two missing variables. This is the correct consequence of
  removing the default — previously the app silently ran on the committed credential. It mirrors the
  repo's existing `SpecialAccountSeeder` "env() missing → hard stop" convention rather than inventing a
  new one. **Exempted for `local` and `testing`** so it cannot brick a developer machine or the suite;
  every real deployment environment, including any added later, is still covered.
- `.env.example` — names `BROADCAST_DRIVER` and the three `PUSHER_APP_*` variables (empty values), with a
  note that the previously committed values must be rotated and not reused.

**Files changed:** `config/broadcasting.php`, `app/Providers/AppServiceProvider.php`, `.env.example`;
new `tests/Feature/Config/PusherCredentialFallbackTest.php`. **The user's pre-existing, unrelated
`register()` hunk in `AppServiceProvider.php` (a `WalletRequestService` singleton binding) was left
untouched** — only `boot()` was extended.

**Tests / checks performed:**
- New suite `PusherCredentialFallbackTest` → **`OK (8 tests, 11 assertions)`**, deterministic across 3
  consecutive runs. Allowed **and** denied paths:
  - *Behavioural core:* with `PUSHER_APP_KEY/SECRET/ID` cleared from the environment (saved and restored
    in a `finally` block, so no real value is lost or printed), re-`require`ing the config file resolves
    **empty** for all three — pre-fix it resolved the committed literals.
  - *Behaviour preservation:* `PUSHER_APP_CLUSTER` still yields `'ap2'` with the env cleared, proving the
    fix did not strip a legitimate non-secret default.
  - *Guard denied paths:* production + pusher + no credentials → `RuntimeException`; production + pusher
    + key present but **secret missing** → `RuntimeException` (a key alone cannot sign, so it must not be
    waved through).
  - *Guard allowed paths:* production + pusher + both present → boots; production + `null` driver →
    boots (guard is conditional on pusher actually being selected); `local` → boots; `testing` → boots.
- **Causality proven** by stashing only `config/broadcasting.php` + `app/Providers/AppServiceProvider.php`
  (leaving the test in place): **3 of 8 failed** —
  `key must fall back to nothing, not a committed literal. Failed asserting that a string is empty`, and
  both guard tests failed with `Failed asserting that exception of type "RuntimeException" is thrown`.
  Restored → `OK (8/11)`. The four allowed-path tests pass in both states by design (they assert the
  guard stays out of the way). No rollback was required.
- **A self-inflicted test defect caught and corrected:** the first draft asserted the fix by
  regex-grepping `config/broadcasting.php` for `env('PUSHER_APP_KEY', '<literal>')`. That assertion
  **failed against the fixed file**, because the explanatory comment describing the old code contains
  that exact text — source-text matching cannot distinguish "literal removed" from "literal mentioned in
  prose". The brittle test was deleted and replaced by the behavioural test above, which is what actually
  characterises the vulnerability. This is recorded because it is a trap for anyone re-testing T2-8.
- **Full regression sweep, all green:** T1-1 `OK (10/47)`, T1-2 `OK (4/14)`, T2-1 `OK (10/44)`,
  T2-2 `OK (7/20)`, T2-3 `OK (13/55)`, T2-4 `OK (12/43)`, T2-6 `OK (9/211)`, T2-7 `OK (6/11)`,
  `RouteServiceProviderTest` `OK (12/14)`, and `AppServiceProviderTest` `OK (18/19)` — the last being the
  suite with the highest regression risk, since a throw-on-boot guard lives in that provider.
- Confirmed **why the guard cannot destabilise the suite**: `phpunit.xml` sets
  `BROADCAST_DRIVER=null`, so `config('broadcasting.default')` is not `'pusher'` for tests — *and* the
  environment is `testing`, which is independently exempted. Two separate protections, because the
  probe showed `phpunit.xml`'s `<env>` does **not** reliably override a real shell variable.
- `php -l` clean on all three changed PHP files. Architecture self-check on `app/`: no cycles, no
  fan-in/fan-out hotspots; the 5 oversized modules are pre-existing and untouched. Config-file sweep
  confirmed no other credential key retains a non-empty literal default. No stashes left; all temporary
  probe scripts deleted.

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations — and one required owner action:**
- **THE CREDENTIAL IS STILL VALID AND STILL IN GIT HISTORY. The code fix does NOT remediate the
  disclosure.** Removing a literal from the working tree does not un-publish it: the values remain in
  every commit that contained them, reachable from a **public** repository (T1-3). **Owner action
  required: rotate all three values in the Pusher dashboard now** (the app must be redeployed with the
  new values via env, since there is no fallback any more), then purge history with the rest of T1-3.
  Until rotated, an attacker can still authorise any private channel — and note that this is now
  **independent of T2-7**: a valid secret defeats the private-channel gate that T2-7 installed, so
  rotation is the higher priority of the two.
- Whether those three values were ever genuinely used in production, or a different Pusher app is live,
  cannot be determined from here. They match this checkout's `.env`, which is the strongest available
  evidence, but the deployed env is not observable from this machine.
- **Behaviour change that can break a deploy:** a production/staging boot that relied on the old
  fallback will now **refuse to start** with a named `RuntimeException` unless `PUSHER_APP_KEY` and
  `PUSHER_APP_SECRET` are set. That is the intended fail-fast, but it is a genuine deployment
  prerequisite — set the env values before deploying this change. Alternatively set
  `BROADCAST_DRIVER=null`.
- The boot guard was exercised by **calling `AppServiceProvider::boot()` directly** with injected config,
  not by booting a real production process. Correct for the logic under test; not a full-deploy
  integration proof.
- `.env.example` documents the now-required variables but does not enumerate every variable the app
  reads (the real `.env` was never opened beyond key-name presence checks, per the standing constraint),
  so it is a targeted template, not a complete one.
- No Pusher connection exists in this environment, so the guard's interaction with a real Pusher app was
  not observed.

## T2-9 — VERIFIED FIX (confirmed as described; two parity gates added that only became necessary because the token now works)

**Original finding (Part 1):** "Google OAuth issues a Sanctum token that no protected route accepts."
Cited `app/Http/Controllers/API/Auth/GoogleController.php:111` (`$user->createToken(...)`),
`routes/api.php:143` (protected routes use `jwt`), `app/Http/Kernel.php` (`jwt` → `JwtAuthMiddleware`),
`app/Http/Middleware/JwtAuthMiddleware.php:26-39` (expects a Bearer token decoded by `JwtService`).
Recommended fix: *"Issue a JwtService access/refresh pair from the Google callback (as LoginController
does) and drop createToken, or formally adopt Sanctum and migrate all guard definitions. Do not leave
both."* Confidence High, confirmed bug.

**Verified / current finding — CONFIRMED exactly.** At HEAD `993fab5` (`git status` on the file was empty,
so the cited line was still live):

```php
$token = $user->createToken('google-auth-token')->plainTextToken;   // GoogleController:111
```

against the reference implementation `LoginController:90` `$this->jwtService->generateTokenPair($user)`.
Both halves of the mechanism were confirmed from source rather than assumed:
`JwtService::generateAccessToken()` embeds `'type' => 'access'`, `'sub'`, `'ver'` claims, which is exactly
what `JwtAuthMiddleware` decodes and requires (`($payload['type'] ?? null) !== 'access'` → reject), while
a Sanctum token is an opaque DB-backed string with none of those claims. Grep confirmed
`GoogleController` was the **only** `createToken()` call site in `app/` and `routes/`, and the only
`auth:sanctum` usage was `Sanctum::currentApplicationUrlWithPort()` inside `config/sanctum.php` itself —
so the second token system had exactly one consumer.
**Reproduced end-to-end in the pre-fix causality run:** taking the token the callback returned and calling
the real `jwt`-protected `GET /api/user` produced **401**, matching the audit's stated trigger.
Minor line drift only: the protected group is now `routes/api.php:130` (`middleware(['jwt','throttle:api'])`),
not `:143`.

**Root cause:** two parallel token systems with no bridge; the OAuth path was written against Sanctum
while the API's actual authentication layer is the custom JWT middleware. The failure was silent because
a token *was* returned — only the next request revealed it.

**Fix applied (the audit's first option — issue the JwtService pair; no Sanctum migration):**
- `GoogleController` now injects `JwtService` and returns
  `$this->jwtService->generateTokenPair($user)`, the same pair `LoginController` issues.
- **Response shape is additive, not breaking:** the flat `token` / `token_type` keys are **kept**
  (`token` = the access token) and `tokens` is added. The consuming client for this endpoint is outside
  this repository and cannot be inspected from here, so removing the only key it ever saw would have been
  an unverifiable breaking change; a client reading `token` now receives a credential that works.
- **Two parity gates added — neither was in the original audit, and both exist only because the token
  became real.** Fixing T2-9 without them would have introduced new defects:
  1. **Banned accounts.** `JwtAuthMiddleware` rejects `status == -1`, but the callback previously could
     not issue anything usable, so the absence of a ban check was harmless. Now it would hand a suspended
     account a working access token **plus a 7-day `RefreshToken` row** on every OAuth completion. The
     callback now returns `403 ACCOUNT_BANNED` before issuing anything, mirroring `LoginController:77-83`.
  2. **Signed-out accounts.** `LogoutController:39` sets `status = 0` and `JwtAuthMiddleware:97` rejects
     `status == 0` with `USER_INACTIVE`; `LoginController:86` therefore calls
     `updateUserStatus($user->id, 1)` before minting. Completing Google OAuth is an authentication event,
     so the callback now does the same — otherwise the fix would have 401'd for any user who had ever
     logged out, i.e. the feature would remain broken in a new way.
- Verified `generateRefreshToken()` stores only `hash('sha256', $tokenString)` (the safe half of the two
  refresh-token systems, cf. T2-11), so the added persistence introduces no plaintext-token storage.

**Files changed:** `app/Http/Controllers/API/Auth/GoogleController.php` (constructor injection, ban gate,
status reactivation, token issuance, response shape); new
`tests/Feature/Auth/GoogleOauthTokenTest.php`.

**Tests / checks performed:**
- New suite `GoogleOauthTokenTest` → **`OK (10 tests, 40 assertions)`**, deterministic across 3 runs.
  Socialite is mocked (no Google round-trip), then the response is used against real routing.
  - *Allowed:* **the decisive one** — callback → `GET /api/user` with `Authorization: Bearer <returned
    token>` → **200** with the correct `user.email`; `tokens` block present in the LoginController shape;
    flat `token === tokens.access_token` and `token_type === 'Bearer'`; refresh token persisted as its
    SHA-256 digest; the issued access token **can be refreshed** via `POST /api/auth/refresh` → 200;
    an existing user is linked by `google_id` without creating a duplicate and their token works; a
    Google-provisioned user reaches protected endpoints.
  - *Denied / edge:* banned account → **403 `ACCOUNT_BANNED`** with **no** `token`, **no** `tokens`, and
    **zero** `RefreshToken` rows written; a `status = 0` account is reactivated to 1 and its token works.
  - *Side-effect check:* `personal_access_tokens` count is **0** after a successful callback, proving the
    orphaned Sanctum rows the audit flagged stop accumulating.
- **Causality proven** by stashing only `GoogleController.php`: **10 of 10 failed**, and the two decisive
  messages are the vulnerability itself —
  `Expected response status code [200] but received 401` for the callback-token-against-`/api/user` test
  (the audit's trigger, reproduced end-to-end), and `Expected response status code [403] but received
  200` for the banned-account test. Restored → `OK (10/40)`. No rollback required.
- **Pre-existing suite untouched and still green:** `GoogleControllerTest` `OK (5 tests, 12 assertions)`
  — it asserts `assertJsonStructure(['token','user'])` and `assertNotEmpty(json('token'))`, which is why
  the response change was made additive.
- **Full regression sweep, all green:** T1-1 `10/47`, T1-2 `4/14`, T2-1 `10/44`, T2-2 `7/20`,
  T2-3 `13/55`, T2-4 `12/43`, T2-6 `9/211`, T2-7 `6/11`, T2-8 `8/11`,
  `ResetPasswordControllerTest` `16/23`, `AuthTest` `14/37`.
- **Correction to a previously recorded "pre-existing failure":** `AuthTest` was recorded in T2-4 and
  T2-6 as `14 tests / 1 failure` (`test_user_can_register` → 500 from reaching real SMTP). It now passes
  **14/14 consistently**, and the T2-9 fix is **not** why — re-measured by stashing `GoogleController.php`
  and re-running: **14/14 with and without the change**. `phpunit.xml` sets `MAIL_MAILER=array` and no
  `EMAIL_OTP_MODE`, so the earlier failure was an environment artifact of those runs (shell env bleed),
  not a code defect. Recorded so it is not re-chased.
- Two test-authoring mistakes found and fixed rather than papered over: an assertion used
  `/api/refresh` when the real URI is `/api/auth/refresh` (the `auth` prefix group), and a draft asserted
  `email_verified_at` was set by Google provisioning — which is **false** (see next item). The tempting
  fix of pinning that defect with an `assertNull` test was rejected, since it would fail the moment
  someone corrected it.
- `php -l` clean on the changed file. Architecture self-check on `app/`: no cycles, no fan-in/fan-out
  hotspots; the 5 oversized modules are pre-existing and untouched. No stashes left; no temp probe files
  remain.

**Final state: VERIFIED FIX**

**Genuinely unverified / still open:**
- **The real Google round-trip was never exercised.** Socialite is mocked, so `redirect()`, the OAuth
  `state` handshake, Google's token exchange and the error branches (`InvalidStateException`,
  `ClientException`, `RequestException`) are unverified — the same limitation noted in T2-6 for those
  handlers' message echoing, which remains **not fixed** and out of scope here.
- **Whether any live client depends on the exact old response shape is unknowable from here.** The
  backward-compatible keys make that unlikely, but the mobile/Flutter consumer is outside this repo
  (same caveat as T2-7).
- **NEW FINDING (discovered while writing this test, not in the original audit — NOT fixed):**
  `UserRepository::createUser()` (`:27-40`) whitelists columns field-by-field and has **no
  `email_verified_at` entry**, so `GoogleController:90`'s `'email_verified_at' => now()` is **silently
  dropped** — a Google-provisioned user ends up with `email_verified_at = NULL` despite Google having
  proven mailbox control. This is the **same method and same class of defect already recorded for T2-4**
  (where the ignored field was `'status' => 0`). It does **not** affect T2-9: `JwtAuthMiddleware` never
  consults `email_verified_at` (only `LoginController:66` does, for password logins), so the issued JWT
  authenticates regardless — verified by the passing test. Practical effect, traced rather than assumed:
  ordinary password login fails for such an account at `LoginController:54` (its password is the random
  `bcrypt(Str::random(24))` from `GoogleController:84`), so the verification column is not the operative
  blocker there. Where the drop DOES bite: `ResetPasswordController` / the `/password/*` chain never sets
  `email_verified_at` either (grep: no occurrence in that controller), so a Google user who completes a
  full OTP-verified reset — and has therefore demonstrably controlled the mailbox — is still met with
  `403 EMAIL_NOT_VERIFIED` at `LoginController:66` on their next password login, and can only escape by
  running the separate email-verification round-trip. That is a genuine dead-end UX caused by the
  silently dropped field, plus a wrong persisted state (`EmailVerificationController::resend()` keeps
  servicing the address because its `:84` guard passes whenever the column is NULL).
  Left unfixed: it is a distinct repository-layer defect and correcting it changes `createUser()` for
  every caller.
- **The orphaned `personal_access_tokens` table and `User`'s `HasApiTokens` trait were deliberately NOT
  removed.** The audit's "do not leave both" is satisfied for the *code path* (zero `createToken` calls
  remain), but dropping a table requires a migration and removing the trait is a model change with
  unknown effects; both are cleanup, recorded rather than bundled into a bug fix.
- Not exercised through a live RoadRunner/Octane server; verified via real in-process HTTP routing
  through the actual `web`/`api` middleware stacks.

## T2-10 — VERIFIED FIX (confirmed exactly; identity bucket added *alongside* IP, not replacing it)

**Original finding (Part 1):** "Rate limiters key on user-or-IP, so every public auth endpoint is
IP-keyed only — and the config file is duplicated." Cited `app/Providers/RouteServiceProvider.php:31-32,40`
(`->by($request->user()?->id ?: $request->ip())`) and `config/rate-limiting.php` /
`config/rate_limiting.php` (byte-identical, 37 lines each). Recommended fix: *"For unauthenticated
endpoints, key on a stable identifier — normalised email/phone plus IP — so per-account and per-address
limits both apply. Delete the duplicate config file so exactly one source of truth exists. Consider
progressive delays on repeated failures."* Confidence High, confirmed configuration/design bug.

**Verified / current finding — CONFIRMED exactly, both halves, at HEAD `993fab5`.**
- `RouteServiceProvider:40` was verbatim `->by($request->user()?->id ?: $request->ip())`. The public
  `throttle:auth` groups (`routes/api.php:85,103,120,270,381` — OTP send/verify, signup, login, refresh,
  password forgot/verify/reset, admin login, staff login) run with **no authenticated user**, so
  `$request->user()` is null and the key collapses to `$request->ip()` alone. That is the IP-only bucket
  the audit describes.
- The duplicate was confirmed by hash, not by eye: both files 37 lines, SHA-256 prefixes
  `E724EDFEB4757B50` — **byte-identical** — and **both tracked in git**. Grep over `app/`, `config/`,
  `routes/`, `tests/` found exactly **two** readers, both in `RouteServiceProvider:31-32`, and both read
  `rate-limiting` (hyphen). **Nothing ever loaded `rate_limiting`** (underscore), confirming the audit's
  "an operator editing the underscore version changes nothing."
- The two comments at `:31-32` (`// ← add the hyphen`) were themselves evidence the duplication was
  a half-finished rename someone worked around instead of resolving.

**One correction to the audit's impact framing (recorded, the fix is unaffected):** the audit says
protection is "bypassable by address rotation." True for an attacker who can obtain fresh routable
addresses; for the common single-NAT egress case the *same* IP-only keying is what lets one abusive
client lock out an entire shared network. Both directions of the defect were addressed, because the fix
keys on identity (survives rotation) **and** keeps a separate looser IP bucket (bounds one address).

**Root cause:** a single `?:` fallback conflated two different principals. When no session identity
exists, an ephemeral network address is the *only* bucket, so it is simultaneously too volatile (per-
attacker) and too shared (per-victim). Plus a copy-pasted config file with no single source of truth —
Part 1 §D's "two sources of truth" pattern.

**Fix applied (the audit's recommended shape, verified against framework source before writing):**
- **Multiple limits are independently enforced** — confirmed in `ThrottleRequests::handleRequest()`:
  it `foreach`es the limits array, `tooManyAttempts`/`hit` on **each**, so returning an array from a
  named-limiter closure applies both buckets (not just one). This is what makes "per-account **and**
  per-address" expressible.
- `configureRateLimiting()` now resolves three cases:
  1. **Authenticated** → one `user:{id}` bucket (unchanged behaviour, just namespaced).
  2. **Public with a stable identity** (email / `phone_number` / staff `identifier` / admin `username`)
     → an array of two limits: a **strict** `account:{identity}` bucket at the category limit, plus a
     **looser** `ip:{addr}` flood-guard at `limit × multiplier`. The strict one is shared across every
     source address an attacker rotates through — that is the property that actually caps brute force.
  3. **Public without any identity** (e.g. `/auth/refresh`, which carries only a token) → single
     `ip:{addr}` bucket at the category limit, exactly as before.
- `identityKey()` is deliberately **cache-label only, never validation**, and **every branch is
  string-only so it cannot throw**: `strtolower`+`trim` for email, `preg_replace('/\D/','')` + last 9
  digits for phone, lowercased `identifier`/`username`. A malformed field falls through to the IP bucket.
  It must not throw because a 429 turning into a 500 on a public endpoint would be worse than the bug.
- **Phone canonicalisation matters:** `+963983337214`, `963983337214`, `0983337214`, `00963983337214`,
  and `0983 337 214` all reduce to the same last-9 national digits, so they share **one** bucket.
  Without this, respelling the number would hand a fresh allowance — the exact rotation bypass T2-10
  is about. (The existing services each normalise inline — `normalizeForCallMeBot`/`normalizeForTextMeBot`
  — and `PhoneNumber::from()` would be the canonical form, but it **throws** `InvalidArgumentException`
  on bad input, so it cannot be used inside a throttle key; a lenient local normaliser is correct here.)
- **No raw PII reaches the cache store**: verified `ThrottleRequests::$shouldHashKeys` defaults to `true`
  (vendor `ThrottleRequests.php:31`), so the composed `md5($limiterName.$limit->key)` is what is stored,
  never the email/phone.
- New config key `rate-limiting.ip_backstop_multiplier` (default 4, `max(1,…)`) drives the looser IP
  ceiling and is `.env`-overridable (`RATE_LIMIT_IP_BACKSTOP_MULTIPLIER`), mirroring how the existing
  limits are tuned. Setting it to 1 restores equal caps.
- **Deleted `config/rate_limiting.php`** — the byte-identical, unread duplicate. Confirmed dead by grep
  before removal; recoverable from git. A note in the surviving file records that it existed and why it
  was removed, so nobody re-adds it.

**Files changed:** `app/Providers/RouteServiceProvider.php` (three-case key resolution + non-throwing
`identityKey()`), `config/rate-limiting.php` (new multiplier + single-source-of-truth note), deleted
`config/rate_limiting.php`; new `tests/Feature/RateLimiting/RateLimiterIdentityKeyTest.php`.

**Tests / checks performed:**
- New suite `RateLimiterIdentityKeyTest` → **`OK (17 tests, 119 assertions)`**, deterministic across 3 runs.
  It calls the **actual registered closure** via `RateLimiter::limiter('auth')` and asserts the returned
  `Limit` bucket keys — the faithful instrument, because `tests/TestCase.php` disables
  `ThrottleRequests` globally (`withoutMiddleware(ThrottleRequests::class)`), so an HTTP-level 429
  assertion could never fire.
  - *Allowed/behaviour:* email request yields BOTH `account:email:…` and `ip:…`; the IP bucket max is
    exactly `auth × multiplier` and the account bucket max is `auth` (proving "both apply" with distinct
    ceilings); same email from two different IPv6 addresses shares one account bucket (**the exact
    pre-fix bypass**); distinct emails on one IP do not collide; authenticated request keys on
    `user:{id}` and ignores a posted email (a logged-in caller is never bucketed against a third-party
    address).
  - *Canonicalisation:* five phone spellings all collapse to the last-9-digits bucket; the
    `+963…`/`09…` pair shares a bucket.
  - *Denied/edge:* malformed identifiers (`not-an-email`, ``, `@`, `abc`, `1`, `!!!`, plus an email that
    isn't one) never throw and always yield ≥1 bucket; a non-email `email` value falls through to
    IP-only; a request with no resolvable IP still yields a bucket (`ip:noip`).
  - *Real-closure toggle:* disabling throttling re-binds through the provider's actual
    `configureRateLimiting()` via reflection and returns `Unlimited` — an earlier hand-copied-closure
    version of this test was rejected as vacuous (it tested a duplicate of the logic, not the app's),
    so it now re-invokes the real method.
  - *Single source of truth:* `config/rate_limiting.php` does not exist; `rate-limiting.php` does and
    carries the multiplier.
- **Causality proven** by stashing only `RouteServiceProvider.php` + `config/rate-limiting.php` and
  restoring the deleted duplicate from HEAD (reproducing the exact pre-fix tree): the suite failed
  **14 of 17 plus 1 error** — every identity/account-bucket test, the "same email across IPs shares a
  bucket" test (the vulnerability itself), the looser-IP-max test, the phone-canonicalisation tests,
  the authenticated-prefix and no-identity tests, the malformed-input tests, the duplicate-gone test,
  and the config-loaded test. Restored → `OK (17/119)`. The three that still pass pre-fix are
  shape-agnostic (registered-callable, and the two that don't assert identity), which is expected.
- **Full regression sweep, all green (15 suites):** T1-1 `10/47`, T1-2 `4/14`, T2-1 `10/44`, T2-2 `7/20`,
  T2-3 `13/55`, T2-4 `12/43`, T2-6 `9/211`, T2-7 `6/11`, T2-8 `8/11`, T2-9 `10/40`,
  `RouteServiceProviderTest` `12/14` (asserts via `assertStringContainsString`, and its `/api/test`
  request carries no identity so it lands in the IP bucket — unaffected by the `user:`/`ip:` prefixes),
  `ResetPasswordControllerTest` `16/23`, `GoogleControllerTest` `5/12`, `AuthTest` `14/37`.
- `php -l` clean on both changed source files and the test. `config/rate-limiting.php` re-verified valid
  UTF-8 / no BOM after edits. Architecture self-check on `app/`: no cycles, no fan-in/fan-out hotspots;
  the 5 oversized modules are pre-existing and untouched. No stashes left; no temp probe files; the
  deleted duplicate stays deleted.

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations:**
- **The fix changes throttle *keys*, which is only observable through the middleware at runtime.** The
  tests assert the bucket keys the registered closure returns; they do **not** observe an actual `429`
  because `TestCase` disables `ThrottleRequests` for the whole suite. The multi-limit enforcement was
  verified by reading `ThrottleRequests::handleRequest()` (it applies every limit in the array), not by
  triggering a real 429. A load-test against the running app is the natural follow-up.
- **Which identifier each endpoint actually posts was traced from the route handlers and FormRequests**
  (email for signup/login/forgot/verify-email; `phone_number` for OTP; `identifier` for staff;
  `email`/`username` with `required_without` for admin login), not from live traffic. If some endpoint
  posts its identity under a key not listed here, that endpoint simply keeps the IP-only bucket (safe
  default) rather than mis-bucketing — so the failure mode is under-protection, never a wrong-bucket bug.
- **The multiplier default of 4 is a judgement call, not a measured optimum.** It is `.env`-tunable and
  documented; `1` makes the two ceilings equal. Production tuning is left to the operator.
- **Per-IP IPv6 granularity is unchanged** — the backstop still keys on the raw client IP. Mitigating
  v6-subnet rotation (key an IPv6 /64 rather than the full address) is a real hardening step but expands
  scope and can itself over-share; left as a candidate improvement, not applied. The *account* bucket is
  what defeats rotation here.
- Progressive delays on repeated failures (the audit's "consider") were **not** implemented — the strict
  per-account cap already bounds a rotating attacker; time-based backoff is a separate enhancement.
- `config/rate_limiting.php` deletion is a tracked-file removal. It was byte-identical and referenced by
  nothing (grep-verified), so no reader can break; it is recoverable from git history if the team wants
  it back under a different name.
- Not exercised through a live RoadRunner/Octane server; verified via the real registered closures and
  framework-source confirmation of enforcement.

## T2-11 — VERIFIED FIX (confirmed exactly; legacy rows PURGED not re-hashed — a deliberate deviation from the audit's first suggestion)

**Original finding (Part 1):** "Staff refresh tokens are stored in plaintext while user refresh tokens
are hashed." Cited the hashed reference `app/Services/JwtService.php:281` (`hash('sha256', $tokenString)`,
looked up by digest at `:125`) and the plaintext offender
`app/Services/Staff/StaffJwtService.php:149-153` (stores `Str::random(64)` raw; `:82` looks it up with
`StaffRefreshToken::where('token', $refreshToken)`), plus `:163-179` (both sign with the same
`jwt.secret`). Recommended fix: *"Hash on write and look up by digest, mirroring JwtService. Add a
one-time migration hashing existing rows (invalidating outstanding staff sessions) and consider separate
signing secrets per token class so a leak in one system cannot forge the other."* Confidence High,
confirmed bug.

**Verified / current finding — CONFIRMED exactly at HEAD `993fab5`.** The cited lines were verbatim:
`generateRefreshToken()` created the row with `'token' => $token` (raw), and `refreshAccessToken()`
matched `where('token', $refreshToken)` by equality — a plaintext round trip. The user path had always
done the opposite: `JwtService:281` writes `hash('sha256', $tokenString)` and `:123` looks up by
`hash('sha256', $refreshToken)`. The two refresh-token systems genuinely disagreed on the one property
that matters, and the plaintext one guards the *privileged* principals (staff + admin).

**Schema verification (why no widen migration was needed):** the column was measured directly on the
production-compatible MySQL 8.2.0 scratch DB — `staff_refresh_tokens.token` is **`varchar(64)`**, exactly
the length of a lowercase hex SHA-256 digest. (The user `refresh_tokens.token` is `varchar(191)`; that
extra headroom is historical — a comment there notes "Changed from 255 to 191" — not a requirement.)
A digest fits the staff column unchanged.

**Blast radius measured before editing:** `StaffJwtService` is the **only** writer and reader of
`staff_refresh_tokens.token` (write `:151`, lookup `:82`). Both staff login (`EmployeeAuthService:69`)
and admin login (`AdminAuthService:82`) delegate to `StaffJwtService::refreshAccessToken()`. The existing
staff-token unit tests (`StaffRefreshTokenTest`, `CleanupExpiredStaffTokensCommandTest`) insert
`hash('sha256', …)` **directly through the model** and never call the service, so they already assume
hashed storage and cannot conflict with the fix — confirmed by re-running them green after the change.

**Root cause:** copy-paste divergence between two parallel token systems — Part 1 §D's "two sources of
truth" pattern applied to persistence format. Not a decision: nothing in the code or comments argues for
plaintext.

**Fix applied:**
- `StaffJwtService::generateRefreshToken()` now writes `hash('sha256', $token)` and
  `refreshAccessToken()` looks up by the same digest, via one private `hashToken()` helper so the two
  call sites cannot drift. `hash('sha256', $raw)` is **byte-identical in form to the user path**
  (lowercase hex, 64 chars), which is the point — the fix closes the divergence rather than inventing a
  third convention. The raw token is still returned to the client and persisted nowhere.
- **New migration `2026_09_25_000001_purge_plaintext_staff_refresh_tokens.php` deletes every legacy row**
  rather than hashing it in place. **This is a deliberate deviation from the audit's first phrasing**
  ("hashing existing rows"), and the reason is recorded in the migration itself: re-hashing a
  possibly-already-leaked plaintext token yields a digest the **same leaked raw value still matches**
  (attacker presents raw → `hash(raw)` → row found), so in-place hashing preserves exactly the sessions
  the exposure may have already disclosed, and forces re-login anyway *for no security benefit*. Purging
  is the only option that actually closes the hole. The cost — all staff/admins sign in once more — is
  the intended invalidation and is the same cost the audit itself acknowledged.

**Files changed:** `app/Services/Staff/StaffJwtService.php`, new
`database/migrations/2026_09_25_000001_purge_plaintext_staff_refresh_tokens.php`; new
`tests/Feature/Staff/StaffRefreshTokenHashingTest.php`.

**Tests / checks performed:**
- New suite `StaffRefreshTokenHashingTest` → **`OK (12 tests, 25 assertions)`**, deterministic across 3
  runs. It asserts storage **by shape and inequality, never by value** (no credential material is
  reproduced anywhere in the file).
  - *The core property:* a generated token pair persists a value that is **not** the raw token, is
    exactly `/^[0-9a-f]{64}$/`, equals `hash('sha256', $raw)`, and **cannot be found by querying the raw
    value**; the client still receives a usable 64-char raw token that is provably not a digest.
  - *End-to-end write path:* `POST /api/staff/login` (the real endpoint, not just the service) stores
    only the digest.
  - *Round trip still works:* presenting the raw token refreshes successfully; rotation revokes the
    consumed row; a consumed token **cannot be replayed**; an expired digest row, an inactive employee,
    and a garbage token all return `null` (the garbage case also asserts **no row is minted** on failure).
  - *Unaffected paths:* `revokeAllTokens()` still clears non-revoked rows (it filters on `employee_id`,
    not `token`).
  - *Migration:* a simulated legacy **plaintext** row is purged by `up()`, and a freshly issued digest
    token still refreshes afterwards (proving the purge is a one-time invalidation, not a permanent block).
  - Two weak assertions were caught and rewritten during authoring rather than shipped: a "parity" test
    that compared a digest to itself (vacuous — replaced by the real `POST /api/staff/login` path) and a
    garbage-token test whose name promised a row-count assertion it didn't make (assertion added).
- **Causality proven** by stashing only `StaffJwtService.php`: **4 of 12 failed** — decisively
  `test_the_stored_token_is_a_sha256_digest_not_the_raw_value` → `the raw refresh token must never be
  persisted` (pre-fix the DB literally contained the client's secret), plus
  `test_a_real_staff_login_stores_only_the_digest`, and the two tests that depend on digest lookup
  (rotation-revoke and expired-row). Restored → `OK (12/25)`. No rollback required.
- **Regression sweep — 20 suites, all accounted for.** Green: `StaffRefreshTokenTest` `16/25`,
  `CleanupExpiredStaffTokensCommandTest` `9/18`, `EmployeeAuthServiceTest` `14/32`, `EmployeeTest`
  `30/31`, T2-1 `10/44`, T2-2 `7/20`, T2-3 `13/55`, T2-4 `12/43`, T2-6 `9/211`, T2-7 `6/11`, T2-8 `8/11`,
  T2-9 `10/40`, T2-10 `17/119`, T1-1 `10/47`, T1-2 `4/14`, `AuthTest` `14/37`.
- **Two failures investigated to baseline rather than assumed:** `StaffAuthControllerTest`
  (`17 tests / 2 errors`) and `StaffJwtMiddlewareTest` (`28 tests / 1 failure / 3 skipped`). Both are
  **identical with and without the T2-11 fix** — re-measured by stashing the service: same 2 errors
  (`TypeError: adminToken(): Return value must be of type string, null returned`, the known **T3-8**
  `config('admin.*')['email']` class already recorded) and the same single pre-existing failure
  (`test_staff_refresh_token_rejected_with_token_type_invalid`, an assertion about token *type* claims,
  unrelated to storage format). **T2-11 introduced zero regressions.**
- **Migration executed against production-compatible MySQL 8.2.0** (`migrate:status` → `Ran`, batch 1);
  every `RefreshDatabase` test above ran it, so its effect on the full schema is exercised, not assumed.
- `php -l` clean on the service, migration and test. Architecture self-check on `app/`: no cycles, no
  fan-in/fan-out hotspots; the 5 oversized modules are pre-existing and untouched. No stashes left, no
  temp probe files.

**Final state: VERIFIED FIX**

**Genuinely unverified / limitations:**
- **The audit's second suggestion — separate signing secrets per token class — was deliberately NOT
  implemented, because it is not safe as a drive-by.** Verified from source: `StaffJwtService:173-178`
  states the shared `jwt.secret` is **intentional** ("return the raw secret, matching JwtService … the
  previous `base64_decode()` produced a different key … so staff tokens could never be cross-verified"),
  and `StaffJwtMiddleware:19` documents an old dual-path that relied on cross-verification. Splitting the
  secret would re-break that coupling and needs a decision about which flows may present which token
  class. It is a design change, not a bug fix, and is recorded as an open hardening item.
- **The purge invalidated every outstanding staff/admin session by design** — that is the intended cost
  of removing plaintext credentials. It has not been exercised against real production row counts (the
  scratch table was empty); on first production run it will log staff out once.
- **Whether legacy plaintext tokens were ever actually exposed is unknowable from here.** T1-3
  established the repo is public and a DB dump sits in git history; if `dump.sql` contained
  `staff_refresh_tokens` rows, those raw tokens are disclosed regardless of this fix. That is precisely
  why the rows are purged rather than preserved, but the dump itself still needs the T1-3 rotation and
  purge.
- `cleanupExpiredTokens()` and the `staff-tokens:cleanup` command are unaffected (they filter on
  `expires_at`/`revoked`), and `revokeAllTokens()` filters on `employee_id` — verified by tests, not
  audited for any other reader beyond the grep of `StaffRefreshToken::` usages (5 sites, all in this
  service).
- Not exercised through a live RoadRunner/Octane server; verified via the real HTTP staff-login route
  and direct service calls against MySQL 8.2.0.

## T2-12 — VERIFIED FIX (http_only restored and credentialed CORS off by default; the audit's `'encrypt'` premise is DISPROVED)

**Original finding (Part 1):** "Session cookies are exposed to JavaScript and CORS allows credentials
with wildcards." Cited `config/session.php:82-84` (`'http_only' => false`, comment says changed "for
Flutter web access"; `secure` defaults false), `config/session.php:26` (`'encrypt' => false`),
`config/cors.php:15,18,20-26,48` (dead `session-debug` path, `allowed_methods => ['*']`, localhost
origins, `supports_credentials => true`), and `config/auth.php:17` (default guard `web`/session).
Recommended fix: *"Restore http_only = true and force secure = true; solve the Flutter client with a
token-based flow rather than by weakening cookie flags. In CORS, drop supports_credentials unless
genuinely required, and remove the session-debug path."*

**Verified / current finding — the headline CONFIRMED, one premise DISPROVED, and the framing
materially clarified.**
- `session.php:83` was a **hardcoded literal** `'http_only' => false` — not even env-driven — so the
  audit's "primary XSS mitigation removed" is confirmed and no `.env` could restore it without a source
  edit. That is the real defect.
- **DISPROVED: "`'encrypt' => false`" is listed as part of the problem — it is not a vulnerability
  here.** Measured at runtime: the effective `SESSION_DRIVER` is **`redis`** (from `.env`), so session
  data lives server-side and the cookie carries only an opaque id. Encrypting is meaningful only for
  the `cookie` driver. Left unchanged, with a comment so nobody "fixes" a non-defect.
- **DISPROVED as stated: "`secure` … may travel over plaintext".** Verified the effective production
  resolution: `secure=true` (because `.env` sets `SESSION_SECURE_COOKIE=true`) and `same_site=lax`. The
  config *default* is false, which only matters if the env var is missing entirely; the default was left
  alone because forcing it true would silently break plain-http local dev.
- **Framing correction that changes the risk model:** the audit's impact text ("Flutter web needs to
  read the cookie"; "any XSS escalates to session theft") overlooks that **this API never sends a
  session cookie at all**. Measured from `app/Http/Kernel.php`: the `api` middleware group contains
  only `SubstituteBindings` + `CacheStatusHeader` — **no `StartSession`, no `EncryptCookies`** — and
  authentication is JWT-bearer in the `Authorization` header (`jwt` → `JwtAuthMiddleware`). Sessions
  exist solely on the `web` guard (notification routes + Google OAuth + `/up`). A grep of `resources/`
  and `public/` found **zero** clients reading `document.cookie`. So the `http_only=false` weakening
  exposed the `web` surface only, and the audit's own fix advice — "solve the Flutter client with a
  token-based flow" — **the codebase already does**; the cookie-flag downgrade was left over.
- **Additional safety proof (so the fix is known not to break CSRF):** `VerifyCsrfToken::newCookie()`
  (framework `:212`) builds `XSRF-TOKEN` with an explicitly hardcoded `httpOnly = false`, independent
  of `session.http_only`. So restoring HttpOnly affects only the session cookie; the double-submit CSRF
  token stays JS-readable. (Confirmed by a test, below.)

**Root cause:** a local-development affordance hardened into the committed default — Part 1 §D's
"development affordances reaching production shape" — compounded by a value so hardcoded it could not be
toggled, alongside a CORS block that paired credentialed cross-origin access with localhost origins.

**Fix applied:**
- `config/session.php` — `'http_only' => env('SESSION_HTTP_ONLY', true)`: **restored to true** (the safe
  default) and made env-overridable, so an unusual local need is a `.env` toggle, not a source edit.
  Comments record the disproved justifications above.
- `config/cors.php` — three changes:
  1. `supports_credentials` is now `filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), …)` — **off by
     default** (the audit's ask), operator-opt-in for genuine local web-session dev. Origins were left
     listed unchanged, **a decision taken with the maintainer** (Option A): the JWT API proves
     credentials unnecessary for `api/*`, and the residual "unknown out-of-repo web client" risk is
     managed by the env toggle rather than by editing the tracked origin list.
  2. `allowed_methods` narrowed from `['*']` to the five verbs the router actually registers (measured:
     GET 101, POST 78, PATCH 6, DELETE 5, PUT 1).
  3. The dead `'session-debug'` path removed — grep over `routes/` found no such route.
- **No migration, no route change.** These are config-only.

**Files changed:** `config/session.php`, `config/cors.php`; new
`tests/Feature/Security/SessionCookieAndCorsTest.php`.

**Tests / checks performed:**
- New suite `SessionCookieAndCorsTest` → **`OK (11 tests, 20 assertions)`**, deterministic ×3. It asserts
  the **real response artifacts**, not just the config array, so it fails if the framework stops honoring
  the values:
  - `GET /` (real `web` group) emits a session cookie whose `isHttpOnly()` is **true** — the headline
    fix, proven on the wire;
  - and the `XSRF-TOKEN` cookie's `isHttpOnly()` is **false** — the CSRF-safety parity guard above;
  - credentialed CORS: a real request with `Origin: http://localhost:3000` gets **no**
    `Access-Control-Allow-Credentials` header by default;
  - env wiring proven **behaviourally** by re-`require`ing each config file under a controlled
    environment (`Env::getRepository()` set/clear): `http_only` default true, and `true`/`false` honoured;
    `supports_credentials` default false, and `true` honoured.
  - `allowed_methods` equals exactly the five verbs, `*` absent; a real `OPTIONS` preflight advertises no
    `TRACE`/`*`; `'session-debug'` gone from paths.
  - Allowed and denied paths are both covered (cookie present + flag set; origin allowed but
    credentials withheld).
- **Causality proven** by stashing only `config/session.php` + `config/cors.php`: **9 of 11 failed**,
  including the decisive `the session cookie must be HttpOnly (T2-12: was hardcoded false)` and
  `credentialed CORS must be absent by default`. Restored → `OK (11/20)`. No rollback required.
- **A test-authoring trap caught and corrected (same one recorded under T2-8):** an earlier draft
  asserted the fix by regex-grepping `config/session.php` and failed against the *corrected* file —
  because my own explanatory comment contains the literal string `'http_only' => false` describing the
  old code. Replaced with the behavioural re-`require` approach above. (Two of my own defects fixed in
  the same pass: a non-existent `assertDoesNotMatchRegex` method, and `Illuminate\Support\Env` written as
  if it were a facade.)
- **Regression sweep — 17 suites, all green**, chosen to cover the surfaces most likely to care about
  cookies/CORS: `DebugEndpointDisclosureTest` `9/211`, `GoogleControllerTest` `5/12` and
  `GoogleOauthTokenTest` `10/40` (both run through the **web/session** guard), `AuthTest` `14/37`,
  `ResetPasswordControllerTest` `16/23`, T2-11 `12/25`, T2-10 `17/119`, T2-9 `10/40`, T2-8 `8/11`,
  T2-7 `6/11`, T2-6 `9/211`, T1-1 `10/47`, T1-2 `4/14`, T2-1/2/3/4 all green,
  `RouteServiceProviderTest` `12/14`. **Zero regressions.**
- Runtime config probes in both `production` and `testing` envs confirmed the effective values:
  production → `secure=true http_only=true same_site=lax creds=false`; testing → same safe shape.
  `php -l` clean on both config files and the test. Architecture self-check on `app/`: no cycles, no new
  hotspots. No stashes left; all temporary probe scripts deleted.

**Final state: VERIFIED FIX** — and with it **all 12 Tier-2 findings are closed.**

**Genuinely unverified / limitations:**
- **The out-of-repo client is the whole residual risk, and it cannot be checked from here.** If a
  Flutter-web (or any browser) client outside this repository genuinely *reads* `document.cookie` or
  relies on credentialed cross-origin session access, this change breaks it and it must flip
  `SESSION_HTTP_ONLY=false` / `CORS_SUPPORTS_CREDENTIALS=true` in its own `.env`. There is no such
  consumer in this repo (grep: zero `document.cookie` readers, and the API is JWT-bearer), but that is
  negative evidence, not proof. This is the item to confirm with client owners.
- **No real browser was involved.** `HttpOnly` here is the framework's emitted cookie flag; actual
  browser enforcement (cookie withheld from `document.cookie`, still sent on requests) is well-defined
  HTTP semantics, not something this suite can observe.
- The `secure` config **default** was deliberately left `false` (production is `true` via `.env`). So a
  deploy that *loses* `SESSION_SECURE_COOKIE` would fall back to non-Secure rather than hard-fail. Making
  it fail-fast is defensible but was not requested and would be a behaviour change beyond T2-12; recorded
  as an open choice, alongside the standing T1-3 rotation/purge needs.
- `same_site` remains the `lax` default (correct; only `'none'` would require `secure`), verified but
  unchanged.
- Not exercised through a live RoadRunner/Octane server or the nginx edge; verified via real in-process
  HTTP through the actual `web` and `api` middleware groups and framework cookie construction.

---

## T3 batch — 13 findings, one implementation wave (VERIFIED FIX)

Executed together at owner request: "do all t3 together but leave 3 and 17 for later".
T3-3 and T3-17 are DEFERRED (untouched, remain open). T3-4 and T3-10 were NOT STARTED
for stated reasons (see below). Because thirteen fixes landed as one wave, per-finding
stash causality was impossible; the batch used a **break-and-restore method instead**
(see "Causality" under Verification) and every mechanism that needed proof got it.

**Batch-wide verification**

- Environment: scratch **MySQL 8.2.0** on `127.0.0.1:3399` (`t3_batch` schema), every
  command carrying the explicit env template (`DB_HOST=127.0.0.1 DB_PORT=3399
  DB_DATABASE=t3_batch DB_USERNAME=... DB_REPLICA_HOST=127.0.0.1`). The production Aiven
  host was never contacted; the one probe that accidentally targeted it failed at the
  SSL handshake (no connection, no reads) — recorded honestly, and it is direct evidence
  for T3-13's class of silent-failure risk.
- New suites (7 files under `tests/Feature/T3Batch/`): `MoneyPathBatchTest` (7),
  `EnvironmentGuardsBatchTest` (10), `MigrationEffectsBatchTest` (5),
  `SeedCredentialsBatchTest` (7), `CiWorkflowBatchTest` (4), `SilentCatchBatchTest` (2),
  `DeletedArtifactsBatchTest` (2). Batch total **OK (37 tests, 437 assertions)**,
  deterministic x3, plus the Pusher suite interaction fix (see T3-14).
- Causality: three mechanisms (T3-1/15/16) were broken by needle-patch with byte-exact
  `.t3bak` restore; the money suite failed 3/7 with the exact pre-fix signatures,
  including the genuine production symptom:
  `Duplicate entry 'ADM-2-<timestamp>' for key wallet_transactions_transaction_id_unique`
  -> HTTP 500. Six more mechanisms (guards/config/migrations/seeder) were broken in a
  second pass; five were caught, **two initially were not** — my T3-9 sweep omitted the
  plain `'password'` literal, and my T3-6 test ran migrations against an empty in-memory
  SQLite schema where `Schema::hasTable()` bails before the raw SQL, making the guard
  unobservable. Both detectors were repaired and the repaired versions proven to fire
  (`near "MODIFY": syntax error` at the migration line; `still hashes the committed
  literal 'password'`). A test that passes whether the fix exists or not is not a test.
- Regression sweep (~35 suites): all prior-task suites green; seven suites red at
  diagnosis and **all seven proven pre-existing** — signatures were `Undefined array
  key "email"` (test fixtures still reading the admin credentials config deleted before
  this batch), stale `listAll()` arity, direct wallet inserts without `wallet_id`, and
  the T3-8 `adminToken()`-null class. Attribution was done by running at a baseline with
  the batch's edits reverted, byte-for-byte identical results.

**T3-1 — VERIFIED FIX** (transaction_id from timestamp)

`PassengerProfileController::chargeWallet()` minted `'ADM-'.$user->id.'-'.now()->timestamp`
against the UNIQUE `transaction_id` column: two same-second charges for one passenger threw
and rolled back a legitimate money movement. Fixed to `ADM-<id>-<UUID>` (readable prefix kept).
Proven live at the broken baseline: 500 with the exact duplicate-key message; green after
restore (both charges persist, ids distinct, balance +9,000). Honest correction during work:
the second site (`AdminWalletRequestController`, `WR-<id>-<ts>`) embeds the request's own
unique id, so it could not actually collide; it was normalised to the same UUID scheme for
consistency and the code comment now says so. The third generator found in passing
(`WalletTransactionService` `PASS_NOSHOW_<time>_<6 random>`) is collision-*prone* but keyed
on unique-per-call randomness; left alone (out of finding scope, noted as latent).

**T3-2 — VERIFIED FIX** (three precisions for one currency)

`wallets.balance` was decimal(10,2) while the ledger already accepted decimal(15,2) — a
balance could overflow its own column before the ledger would accept the debit. New migration
`2026_09_26_000002_standardise_money_column_precision` widens to 15,2 (the standard the newest
money column, `cash_ride_debt`, already set): MySQL-only guard, never shrinks, idempotent,
`down()` intentionally a no-op (re-narrowing real money is unsafe). Verified on MySQL: all six
money columns 15,2 after full migrate; `->change()` preserved NOT NULL **and** the 0.00
defaults (the known Laravel footgun, asserted in `MigrationEffectsBatchTest`); values beyond
10,2 capacity stored; suite also passes the 99,999,999.99-era rows unchanged.

**T3-5 — VERIFIED FIX** (duplicate indexes + MySQL-only guard)

Confirmed as described: the 2026_08_21 guard checked `rides_driver_status` while 2026_08_14
had created `rides_driver_status_index` — names never matched, duplicates were created.
Duplicates observed live (rides/bookings). `2026_08_21`'s `idx()` helper rewritten from raw
`SHOW INDEX` to driver-agnostic `Schema::getIndexes()`. New corrective migration
`2026_09_26_000001_collapse_duplicate_performance_indexes` drops the redundant copy of each
duplicate column-set (never the last one; guarded both ways; `down()` recreates). Beyond the
audit's three, **two more duplicates were found live** on the same sweep:
`wallet_transactions_transaction_id_index` (plain duplicate of the existing UNIQUE — pure
write amplification on the ledger) and `(wallet_id,created_at)` created twice (auto-name in
the create-table migration vs explicit `wallet_tx_wallet_created` in 2026_08_21). Both
included; `MigrationEffectsBatchTest` now fails if ANY same-column-set duplicate exists on
the three hottest tables — a standing anti-regression contract, not a point check.

**T3-6 — VERIFIED FIX** (MySQL-only raw SQL vs SQLite tests; partial-by-design)

All seven previously-bare migrations (`2025_05_22_224859`, `2025_07_21_181158`,
`2026_04_19_014226`, `2026_04_19_014626`, `2026_05_18_000000`, `2026_07_16_100000`,
`2026_08_01_000001`) now early-return when the driver is not mysql. Proven behaviorally:
on a stubbed SQLite schema with tables present, each `up()` runs clean; with a disabled
guard it reaches `ALTER TABLE ... MODIFY` and SQLite throws — the fatal the guard prevents.
The audit's other half (point tests at MySQL) is **being fixed by the owner's own staged
work**: staged `phpunit.xml` already targets mysql/127.0.0.1:3306 and `sonar.yml` defines a
`mysql:8.0` service container — the file was deliberately not touched (user-owned, MM in
status, working-tree copy points at the live Aiven DB which is a separate recorded finding).
The spatial/JSON SQL in `RideSearchService`/`RideRepository` is NOT driver-guarded: SQLite
fails at the rides create-migration long before any query could run there, so guards would
be theatre; MySQL-in-CI is the correct remedy and is in place. Recorded as such rather than
half-fixed.

**T3-7 — VERIFIED FIX** (no-op middleware with a green test)

`VerifyOtpMiddleware` (returned `$next()` unconditionally, zero route references) and its
misleading unit test **deleted**, per the finding's own remedy ("delete both files").
`DeletedArtifactsBatchTest` pins it: files must stay gone and no app/routes/config file may
reference the class again.

**T3-8 — VERIFIED FIX** (AdminUserSeeder created a null-credential user)

`config('admin.system_admin.email')` etc. resolve to null (keys removed pre-batch), so
`firstOrCreate(['email' => null], ['password' => Hash::make(null)])` produced a user with no
email and an empty-string password hash. The seeder's docblock rationale (UserObserver needs
an admin row) is stale — the observer uses `rater_id => null` now. **Deleted.** Callers
verified safe: `Atarikaktestseeder`'s three lookups are null-guarded (`if ($adminUser)`) and
its wallet precheck message was updated to stop directing people at the deleted seeder.
Admin auth genuinely lives on `Employee` (SpecialAccountSeeder/SystemAdminSeeder, both
already env-hard-stopped). Anti-reintroduction pinned in `DeletedArtifactsBatchTest`.
Consequence recorded, not fixed: the `Undefined array key "email"` failures in seven test
fixtures (WalletTest, RideTest, StaffComplaintControllerTest, ...) are the *same* removed-
credentials class and remain open debt — they are test-side, pre-existing, and out of this
batch's scope.

**T3-9 — VERIFIED FIX** (committed passwords in seeders + k6)

New trait `ResolvesSeedCredentials`: password from `SEED_USER_PASSWORD` /
`SEED_ADMIN_PASSWORD` / `SEED_AGENT_PASSWORD` / `SYSTEM_ADMIN_PASSWORD` /
`SYCASH_PASSWORD` env if set, else **generated per process and printed** — never a literal
fallback. Applied to all five test seeders (Passenger, Driver, Syride, Atarikaktest,
UserRealFlow — 14 call sites total, one shared key so a seed run still has one usable
password, still reported at the end; SyrideSeeder's summary now prints the live value via
`reportPassword()` instead of the old committed literal). k6: the five admin-login scripts now read
`__ENV.K6_ADMIN_EMAIL/K6_ADMIN_PASSWORD` with a fail-fast guard; their committed admin
email/password pair matched **no** seeder (that login was already dead — moving to env cannot break
it, and the smoke test's `password: 'wrong'` negative probe is a fixture, kept).
`Getloadtesttokens.php` now shows `-uroot -p ` (prompt) instead of embedding the old compose
root password (carried from the T2-5 work). End-to-end proven on a fresh MySQL schema: seeded
with the env override set, the env password verifies and the old committed literal does not.
Detector lesson recorded in
Causality above. Out of scope but recorded: running PassengerSeeder past the first wallet
hits `wallets_phone_number_unique` (every passenger shares the COMM_NUMBER constant,
identical at HEAD) — a pre-existing seed defect, unrelated to credentials.

**T3-11 — VERIFIED FIX** (CI was decorative)

`sonar.yml`: `|| true` removed from the test step (build now breaks on failure — the gate
the whole tier depended on); `SonarSource/sonarqube-scan-action@master` pinned to
`ba9859eae8dd6bd29e412f25ddbbef3d032000f4 # v8.2.2` (verified against the upstream tags
API at work time); the committed test-only JWT placeholder in `phpunit.xml` replaced with
`$(openssl rand -hex 32)` per run (CI DB is ephemeral, no token crosses runs). Workflow
still parses (Symfony/Yaml asserted in `CiWorkflowBatchTest`). Not verifiable here: an
actual Actions run — file contract + parse only, stated as such. HEAD's `phpunit.xml` still
commits the literal JWT secret; that file is owner-owned (see T3-6), recorded as the
finding's remaining half rather than silently edited.

**T3-12 — VERIFIED FIX** (anonymous docs + shipped spec)

New `GateDocumentation` middleware wired into all four l5-swagger middleware slots
(`api/asset/docs/oauth2_callback`): local/testing pass; everywhere else requires the client
IP in `DOCS_ALLOWED_IPS` (read via config so it survives `config:cache` — the middleware
must not call `env()` directly); denies with **404** so the endpoint does not confirm its
own existence. Generated spec untracked (`git rm --cached`, file kept on disk) and
`/storage/api-docs` gitignored. Behaviour tested both ways plus every-slot wiring and the
ignore rule. Not gated behind auth (IP allowlist chosen: docs are a dev tool, not a user
surface; documented in the middleware).

**T3-13 — VERIFIED FIX** (silent catches)

Inventory at work time: **27 real swallows converted to `Log::warning` with context**
(1 notification persist-broadcast site, 7 noshow, 6 ride controller, 4 admin-wallet-request,
2 verification, 2 complaint controllers, 2 staff controllers, 2 wallet-request-service incl.
the multi-line form the audit's grep missed) — the audit's 31 count included the
multi-line/annotated variants; every one was triaged. 2 cache-status listeners in
`AppServiceProvider` deliberately stay silent (they fire per cache event on every request;
logging would spam) but must carry the `intentionally silent` annotation —
`SilentCatchBatchTest` is a brace-counting scanner over `app/Http|Services|Providers` that
fails on any un-annotated empty catch body: a standing contract against new swallows. One
behavioural test proves the mechanism: with the notification service mocked to throw, the
domain write persists AND the warning surfaces (pre-fix: invisible).
`AdminTripService::safeCoords()`'s empty catch is a documented null-return contract, not a
swallow — left alone.

**T3-14 — VERIFIED FIX** (sync queue blocks requests)

`AppServiceProvider::boot()` now refuses to start outside local/testing when
`config('queue.default')` resolves to `sync` — the exact silent degradation (3 FCM retries +
backoff inline in the HTTP request) the finding describes; mirrors the T2-8 pusher guard
which lives in the same method. docker-compose already sets redis (verified :39/:254),
`.env.example` documents it; local/testing exempt so the suite and CI (which set sync
deliberately) boot. The guard fired for real during verification: an artisan command with a
leaked `QUEUE_CONNECTION=sync` in a production-env shell was refused at boot — exactly the
footgun. Interaction caught and fixed: `PusherCredentialFallbackTest` boots production-like
envs to probe the pusher guard; its fixture now sets `queue.default=redis` so the T3-14
guard doesn't shadow it (a correct production deploy sets both; the guard was NOT weakened).
The audit's `afterCommit()` suggestion was considered and left out — the sync guard makes
the blocking case impossible in production-shaped deployments; afterCommit changes dispatch
timing semantics and belongs with a queue-behaviour change, not here.

**T3-15 — VERIFIED FIX** (pending-withdraw race)

The check now runs **inside** the transaction with `$user->wallet()->lockForUpdate()->first()`
before reading balance/pending; a concurrent request blocks on the wallet row until the first
commits and then sees the pending amount (mirrors the in-repo pattern at
`PassengerProfileController:331`). The file was the owner's staged new service — edited
additively, not overwritten. Proven two ways: sequential guard rejects the over-commit
(DomainException), and a SQL listener asserts the actual `select ... for update` against the
`wallets` table — the sequential-only test passes pre-fix, the lock test does not
(broken-baseline: fails; restored: passes). True two-connection concurrency remains
unproven here (RefreshDatabase transaction isolation makes it untestable in-process; CI
service containers with two real connections would be the upgrade path) — stated, not hidden.

**T3-16 — VERIFIED FIX** (noshow penalty without row lock)

Inside the transaction the report is re-selected `lockForUpdate()` and its status re-checked
(`!== 'pending' -> skip`), so `applyPenalty()` (moves real escrow money) runs only under the
lock; skipped reports don't count as resolved. Money-path test proves: first pass resolves
1 + pays out (driver 47,500 on the 50,000 escrow), second pass resolves 0, pays 0, exactly
one ledger row per booking — idempotent by construction, not by scheduler topology. Because
sequential idempotency also held pre-fix (the fetch filters status anyway), the decisive
instrument is SQL ordering: the noshow_reports FOR UPDATE must appear BEFORE the first
`insert into wallet_transactions` (broken baseline: no lock at all -> fails; restored:
passes).

**Not started / deferred, with reasons**

- **T3-3** — owner instruction: leave for later.
- **T3-4** — retargeting `processed_by` FKs to `employees` directly contradicts the owner's
  recorded T2-1 decision (staff/admin resolve through the shadow-User bridge). It is their
  standing design choice, not an oversight; needs their reconsideration, not a drive-by fix.
  Left open.
- **T3-10** — deploy automation whose remaining defect is the PAT embedded in the VPS
  remote URL; rotation is owner action (T1-3), and blind YAML edits cannot be verified in
  this environment at all. Left open, explicitly skipped at batch start.
- **T3-17** — owner instruction: leave for later.

**Batch housekeeping** — no stash entries, no `.t3bak` left, all temp break/restore/verify
scripts self-deleted. `config/rate_limiting.php` deletion was already recorded under T2-10
(and is pinned by `DeletedArtifactsBatchTest`). Scratch MySQL restarted once (known
flakiness; an intermediate all-error sweep was the dead server, proven by re-run).

---

## T4-1 — VERIFIED FIX (the test environment can now see rate limiting and middleware)

**Original finding (Part 1, Tier 4):** `tests/TestCase.php:16` called
`withoutMiddleware(ThrottleRequests::class)` for **every** test, so no rate limiting was
ever exercised (leaving T2-3/T2-10 unverified); `phpunit.xml` excluded
`app/Http/Middleware`, `app/Console`, `app/Providers` and `Kernel.php` from coverage —
precisely where T2-1 and T3-7 live; the SQLite config (T3-6) hid the enum defect (T1-2).
Fix direction: a MySQL-backed integration job, stop globally disabling throttling, and
cover the middleware layer.

**Verified / current finding:** confirmed exactly, and fixing it **immediately exposed a
real latent defect** (the Handler bug below) — the strongest possible evidence for the
finding's own thesis that this blind spot was hiding true bugs.

**Root cause:** a test-time convenience (skip the flaky 429s) applied globally, plus a
coverage configuration that excluded the highest-severity directories. Neither the throttle
keys (T2-10) nor the middleware/Kernel/Providers code (T2-1, T3-7, boot guards) could ever
fail a test, because the harness disabled or excluded them by construction.

**Files changed:**
- `tests/TestCase.php` — the global `withoutMiddleware(ThrottleRequests::class)` removed.
  Middleware now runs as registered. A `disableThrottling(string $why)` helper is offered
  for the rare test that legitimately needs many calls with no 429; per-test, explicit and
  greppable, so no opt-out can ever again be silent. (No suite uses it today — see
  verification.)
- `phpunit.xml` — the `<source>` block dropped all four exclusions; coverage is now the
  whole `app/` tree, so middleware/Console/Providers/Kernel count. The user-owned `<php>`
  env block was **not** touched (it holds the Aiven-pointing DB creds — a separate
  recorded finding).
- `app/Exceptions/Handler.php` — **the new defect this fix uncovered, fixed here** (below).
- New suite `tests/Feature/RateLimiting/RateLimitEnforcementTest.php` (6 tests) including
  the permanent structural anti-regression pin.

**The bug T4-1 uncovered:** once a 429 could actually fire in tests, asserting the throttle
headers failed — the catch-all API renderer in `Handler::register()` did
`response()->json([...], $status)` and **discarded the exception's headers**.
`ThrottleRequestsException` is a 429 `HttpExceptionInterface` carrying `Retry-After`; every
throttled API client therefore received a bare 429 with no retry timing — a contract break
that had been permanently invisible because throttling never ran in the suite. Fixed by
reading `$e->getHeaders()` and passing them to the rebuilt JSON response (preserves
`Retry-After` / `X-RateLimit-Reset` on every HttpException-shaped error, not just 429s).

**Framework semantics established from source (so tests assert truth, not folklore):**
`ThrottleRequests::handleRequest()` THROWS `buildException()` on the limit-exceeded path,
so the 429 carries only `Retry-After`; the `X-RateLimit-*` counters are added by
`addHeaders()` on the *success* path only. The enforcement suite asserts each header on the
path the framework actually sets it (an earlier draft asserting the counters on the 429 was
corrected against `ThrottleRequests.php:151-167/273-295`, not against assumption).

**Tests / checks performed:**
- Enforcement suite **OK (6 tests, 32 assertions)**, deterministic x3, driving real HTTP
  through the real middleware: account bucket returns 429 at `limit+1`; the 429 carries a
  numeric `Retry-After` (pre-Handler-fix this assertion failed — causality for the new bug);
  a non-throttled response carries `X-RateLimit-Limit` = category limit with a decremented
  remaining; two distinct accounts on one IP do not starve each other (the T2-10 property,
  now observed at the HTTP layer, not just bucket keys); master toggle off ⇒ no 429 ever.
- Combined `tests/Feature/RateLimiting/` (Enforcement + the existing identity-key resolver
  suite): **OK (23 tests, 151 assertions)**.
- **Causality for T4-1 itself:** with HEAD's `TestCase` (global opt-out) restored, the
  enforcement suite fails 5 tests — the 429, the retry-after, the counters, the
  distinct-account and the blind-spot structural pin. The enforcement tests cannot pass
  while the blind spot is back: they are gated on the default being throttle-ON. Restoring
  the fix returns green. The Handler `Retry-After` fix is separately proven by stashing only
  `Handler.php` (that assertion fails; restore → green).
- **Regression-free proof:** the full suite under the new `TestCase` (throttle genuinely ON)
  produced `1903 tests, 374 errors, 57 failures`; the single occurrence of "429" anywhere in
  that output was inside the new test's own method name — **no pre-existing test received an
  unexpected rate-limit**, so removing the global opt-out changes no existing outcome. The
  374/57 are the pre-existing broad-suite failures against MySQL (config/admin `email`
  fixtures, NOT-NULL test inserts, the recorded T3-8 admin-token class) already attributed in
  the T3 batch; a scratch-MySQL death inflated the error count mid-run and was re-run clean.
  Targeted sweep of every throttle-exposed suite (Auth, Reset, Signup, StaffAuth,
  OtpAttemptLimit, PassengerConfirm, all T3Batch, Pusher, RateLimiting) under the new
  TestCase: all OK except the recorded pre-existing `StaffJwtMiddlewareTest` (28/1F/3S,
  unchanged) — zero regressions from T4-1.
- `test_the_suite_blind_spot_is_actually_gone` is a permanent anti-regression pin: it reads
  `TestCase::setUp` and fails if `withoutMiddleware` reappears there, and reads
  `phpunit.xml` and fails if the middleware coverage exclusion is re-added.

**Final state: VERIFIED FIX.**

**Genuinely unverified / open:**
- The audit's "add a MySQL-backed integration job" is **half-met by the owner's own staged
  work** (staged `phpunit.xml` targets mysql/127.0.0.1:3306; `sonar.yml` has a `mysql:8.0`
  service container), so CI runs MySQL and satisfies that clause at the pipeline level. This
  task deliberately did not modify `phpunit.xml`'s `<php>` env block (user-owned; its working
  tree copy points at the live Aiven DB — a separate recorded finding). Reconciling the
  committed HEAD copy (still sqlite + the committed JWT literal) is the owner's pending edit.
- The `disableThrottling()` helper exists but is unused; deliberately not exercised with a
  green test (proving an unused helper works is theatre). It documents the sanctioned
  opt-out path.
- True simultaneous-connection rate limiting is not observed; these are sequential HTTP
  calls against the array-cache limiter — the correct level for "is the middleware wired and
  does it reject past the limit."
- Coverage now *counts* the middleware layer, but no new unit tests were added for every
  individual middleware; that removal of the *reason such tests would have been pointless*
  is what this task delivers. Broader middleware test authoring remains open Tier-4-adjacent
  work, not part of T4-1's contract.

---

## T4 batch — T4-2, T4-3, T4-4, T4-6, T4-7 VERIFIED FIX; T4-5 ROLLED BACK

One implementation wave, six findings, T4-1 having landed earlier. **Five fixes shipped, one
was rolled back on owner instruction with the hazard proven rather than assumed.**

### T4-2 — `Ride::scopeNearLocation` built an unbindable WKT. VERIFIED FIX

**Finding re-verified at `app/Models/Ride.php:142-149`.** The scope passed
`ST_GeomFromText('POINT(? ?)', 4326)` with `[$longitude, $latitude, $radiusMeters]` as bindings.
The `?` characters sit *inside a single-quoted SQL string literal*, so they are never
placeholders: the coordinates were never bound, and the geometry argument reached MySQL as the
literal text `POINT(? ?)` — an invalid geometry. The binding array was also the wrong shape for
the two real placeholders.

While fixing it I found a **second, independent defect in the same 8 lines**: the signature
type-hinted `Illuminate\Database\Query\Builder`, but Laravel hands a local scope an
`Illuminate\Database\Eloquent\Builder`. Those two classes are unrelated siblings (neither
extends the other — verified in vendor source: both `implements BuilderContract`), so the scope
could not have executed even with correct bindings. The audit recorded only the binding bug.

**Fix.** Build the WKT in PHP exactly as `RideSearchService::getNearbyRides` (:140) already does
correctly, and bind it as one value; correct the import to the Eloquent Builder. `%F` is a
locale-independent float format, so coordinates can neither inject nor be corrupted by a
comma-decimal locale. Behaviour at the call site is unchanged because the scope had zero callers
— the fix is about making dead-but-wrong code correct.

**Verification.** A real MySQL geometry test (Damascus 36.2765/33.5138 vs Aleppo 37.1343/36.2021,
~150 km apart): the ride inside the radius is returned, the far one is excluded, the radius
widen closes the gap (10 km → none, 300 km → one), and the rendered SQL contains the bound
`POINT(36.276500 33.513800)` with no `POINT(?` literal. **Causality:** with `Ride.php` at HEAD the
four `near_location` tests fail (unusable scope); restored, all pass.

One assertion was corrected against the framework rather than trusted: WKT `POINT()` is
X-then-Y (longitude first), so my first regex expected lat-first and was wrong — the production
transpose was correct.

### T4-3 — `cleanupExpiredTokens` DELETE relied on operator precedence. VERIFIED FIX

`StaffJwtService::cleanupExpiredTokens()` emitted `where expires_at < ? or revoked = ?`. It is
only correct today because no other clause exists; adding one would turn it into
`(a) OR (b AND c)` and silently widen the DELETE across the table. Parenthesised into a single
nested `where(fn …)`. The emitted SQL is unchanged for every current caller, so this is a pure
robustness fix.

**Verification.** A behavioural test (expired-only, revoked-only, both, and live → only the live
token survives) plus a structural test that captures the real DELETE via `DB::listen` and
asserts the parenthesised group. **Causality:** at HEAD that test fails with the literal
`delete from staff_refresh_tokens where expires_at < ? or revoked = ?`, precisely the T4-3 shape.

### T4-4 — JWT lifetimes inconsistent and undocumented. VERIFIED FIX (deliberate deviation)

Two defects: `config/jwt.php` docblock claimed "Default is 15 minutes" while the code shipped
`600` (10 hours), and `StaffJwtService` hardcoded `ACCESS_TTL = 3600`, ignoring config entirely.

Fixed structurally: new `jwt.staff_ttl` (minutes), read by both `expires_in` and the `exp` claim;
its default (60 min) reproduces the old hardcoded 3600 s **exactly**, so staff behaviour is
unchanged. The user side already read `jwt.ttl`.

**Deliberate deviation, stated plainly:** the audit's direction also said "align the default with
the documented value" (600 → 15). I did **not** flip it. `JWT_TTL` is unset in `.env` and absent
from `docker-compose`, so the code default *is* the effective value in production; changing it
would have silently shortened every issued access token 40× on the next deploy, breaking any
client that does not already refresh. That is a live product decision about an external client
this repository cannot verify. Instead the docblock now states the real default and marks the
15-minute production recommendation as requiring a working client refresh (which does exist:
`/api/auth/refresh` with 14-day refresh tokens). A test pins `ttl` at 600 so the effective value
cannot drift unnoticed. **If the owner wants 15, it is a one-line default change plus a client
compatibility decision — not something to slip in as a config edit.**

### T4-5 — privileged columns in `User::$fillable`. ROLLED BACK (owner instruction)

Owner said "rollback and continue other tasks" after the analysis below was presented, so nothing
was applied — this is a clean no-op, and `app/Models/User.php` is untouched by this batch.

The hazard is worth recording because it is the reason a seemingly safe cleanup is not safe. The
audit's direction was "move privileged columns out of `$fillable` and set them explicitly."
Enumerating every write proved the opposite of routine: **all 14 privileged-column writes on a
`User` go through the instance `->update([...])` form, which respects `$fillable`** (verified in
`vendor/.../Eloquent/Model.php` → `fill()`). Removing those columns would therefore have made
ban/unban, verification approve/reject, email verification and staff-mirror account creation
**silently stop persisting** — reintroducing exactly the recorded T2-4 defect class (where a
column whitelist dropped `email_verified_at`) on the money/auth paths.

Doing it correctly means converting ~14 call sites across 9 files to `forceFill()->save()` /
`forceCreate()` plus seeder paths, each a chance to introduce a silent privilege regression. The
audit itself rates this "Potential issue (not a live vulnerability)" with no mass-assignment sink
present. Correct call: do not half-apply it. **Left open, deliberately, with the hazard recorded.**

### T4-6 — dead/duplicate artifacts. VERIFIED FIX

Deleted, each verified unreferenced first: `fix.php` (a blind `str_replace` script hardcoded to
`/var/www/html` production paths — the worst artifact in the repo, a script that rewrites app
files on the server if anyone runs it), root `UserRating.php` (byte-identical duplicate of
`app/Models/UserRating.php` under the same `App\Models` namespace; PSR-4 maps `App\Models\` to
`app/`, so it was unreachable-but-shadowing), `cacert.pem` (zero references), and
`resources/js/firebase.js` (placeholder `your-project.firebaseapp.com` keys in Laravel-Mix
`process.env` syntax inside a Vite project, imported by nothing).

Relocated `tests/Unit/Tests/Unit/Services/GeocodingServiceTest.php` (doubled path segment) to
`tests/Unit/Services/` — a **move, not a delete**: no canonical copy existed, and the file already
carried the correct `Tests\Unit\Services` namespace, so only the path was wrong. Verified
content-neutral: identical 17 tests / 11 errors / 4 failures before the move and after, so those
pre-existing failures are the file's own, not the move's.

Renamed two k6 scripts with stray filename whitespace (`" Scenario3 lb no cache.js"` →
`Scenario3 lb no cache.js`; `"Syride smoke test .js"` → `Syride smoke test.js`), preserving the
T3-9 env-guard edits (verified present after rename).

**Disproved:** the claim that `config/app.php:99` "defines `key` twice" is wrong — line 57 is
top-level `app.key` (`APP_KEY`) and line 99 is `services.openroute.key` (`OPENROUTE_API_KEY`), two
different arrays. No change made; a test pins the true structure so it is not "re-fixed" later.

### T4-7 — no-op shipped code reporting success. VERIFIED FIX

`ProcessBulkNotifications` had an empty `handle()` and **zero dispatch sites** anywhere in the
app; its only test asserted the stub was a stub (112 lines proving a class exists and does
nothing). Deleted both — the same treatment as T3-7's VerifyOtpMiddleware.

`AdminDashboardController::uploadAdminPhoto()` returned `{'status':'success','message':'Photo
uploaded'}` while uploading nothing, and the `employees` table has **no photo column**, so the
endpoint could never have worked. Route and method removed; an honest 404 replaces a false
success. A real admin photo upload is a feature (schema + storage), deliberately not invented here.

**Verification.** New suites `tests/Feature/T4Batch/MoneyAndAuthPathBatchTest.php` and
`DeadArtifactBatchTest.php` pin every item above, including `assertFileDoesNotExist` for each
deleted artifact, the route/method absence, and that `App\Models\UserRating` still autoloads.
`composer dump-autoload` re-verified after deletion: canonical classes resolve, the job is gone.

### Batch verification

- T4 batch deterministic ×3: **OK (53 tests, 486 assertions)** every run.
- Causality proven per fix by reverting each file to HEAD (details above) and re-running.
- **Full-suite regression: 1909 tests, 374 errors, 56 failures, 3 skipped, 5 risky** vs the
  recorded T4-1 baseline of 1903 / 374 / 57 / 3 / 5. Errors unchanged; failures one *fewer* (the
  header assertion fixed in T4-1). Test count +6 = +16 T4Batch, −10 for the deleted stub test and
  tests covering endpoints/routes removed earlier. **No new error and no new failure.**
- `RideSearchServiceTest` (touched by the `Ride.php` edit) is 3 failures / 3 risky both with and
  without my change — pre-existing, not a regression.
- Combined targeted run of every suite this batch touches: **OK (122 tests, 915 assertions)**.

### Genuinely unverified / open

- **T4-5** remains open by owner instruction (hazard above).
- **Staff/user TTL default** is unchanged at 600 by deliberate decision; aligning to 15 needs an
  explicit owner decision plus client-compatibility confirmation.
- `config/rate_limiting.php` duplicate was already removed under T2-10; the T4-6 row mentioning it
  is historical.
- `Noshowservice.php` non-standard casing is *cosmetic only* — the class name matches the file
  name, so PSR-4 resolves it. A rename on a case-insensitive filesystem risks breakage for no
  functional gain, so it was left alone (this is the one T4-6 sub-item not addressed).
- `uploadAdminPhoto` removal is a client-visible API change: an external admin UI calling
  `POST /api/admin/photo` now gets 404. It previously got a lie, but if such a client exists it
  needs a real implementation, which is out of audit scope.
