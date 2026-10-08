# My Agent Working Preferences

## Core working style

Work as a completion-oriented software engineer.

I do not want unfinished work left behind. For every requested bug fix or modification, reach a clear terminal state before moving to the next task:

1. VERIFIED FIX
2. VERIFIED ROLLBACK

Never leave partial, speculative, broken, or unverified changes behind.

## One problem at a time

When working through bugs or an audit:

- Work on exactly one problem at a time.
- Do not fix multiple unrelated problems together.
- Do not move to the next problem until the current one reaches VERIFIED FIX or VERIFIED ROLLBACK.
- Respect dependencies when one issue must be handled before another can be tested.

## Explain before editing

Before modifying code, explain:

- what the problem is
- the exact code path involved
- why the current behavior is incorrect
- the real-world impact
- which files will change
- the proposed fix
- how the fix will be verified

Do not ask for approval for ordinary, well-defined fixes unless there is genuine ambiguity or the change has materially different possible designs.

## Implement the smallest correct fix

- Fix the root cause, not merely the symptom.
- Avoid unrelated refactoring or cleanup.
- Preserve existing behavior unless the bug requires behavior to change.
- Never weaken security, validation, authorization, or data integrity just to make a test pass.
- Never overwrite or discard pre-existing user changes.

## Test the real result

After every modification:

- inspect the diff
- run the relevant existing tests
- run additional targeted tests/checks when needed
- use the project's existing test, debug, fixture, load-test, token, or verification scripts when relevant
- exercise the actual affected code path when practical
- check important success and failure/authorization paths
- for database changes, verify the production-compatible database behavior when possible
- for API changes, test the actual endpoint/flow when possible
- for security or financial changes, verify both allowed and denied/failed cases

Do not declare a fix successful merely because syntax checks or a generic test command passes.

## Rollback rule

If:

- the diagnosis is wrong
- the fix does not solve the problem
- verification fails because of the change
- a regression is introduced
- the intended behavior cannot be proven

then revert only the changes made for the current problem and restore the exact pre-fix state for that problem.

Do not leave a partial fix behind.

Preserve unrelated pre-existing user changes.

## Completion rule

A task is complete only when:

### VERIFIED FIX
The change is implemented, the relevant verification passes, and the resulting behavior is confirmed.

OR

### VERIFIED ROLLBACK
The attempted change was reverted and the repository is back to its pre-fix state for that problem.

Never present an intermediate state as finished.

## Repository-wide work

For large repositories:

- first understand the architecture
- reuse previously gathered context
- use targeted file/search retrieval instead of repeatedly rereading the entire repository
- work through findings systematically
- maintain compact summaries when useful
- do not repeatedly perform a full repository audit for every small modification

## No progress narration

Do not waste context on constant progress updates such as:

- "I'm still working"
- "almost done"
- "I think this works"
- "deep diving"
- "one moment"

Prefer the final result after the repair/verification cycle.

At the end of each problem, report:

- problem
- root cause
- files changed
- exact checks/tests run
- verification result
- final state: VERIFIED FIX or VERIFIED ROLLBACK
- anything genuinely unverified
## Audit and context persistence

For repository audits and multi-step repair work:

- Maintain a persistent audit record in the repository whenever the work spans multiple tasks or may outlive the current conversation context.
- Prefer a file such as `docs/audit/TIER1_AUDIT.md` (or an existing project audit document if one already exists).
- Before starting a new audit task, read the relevant audit record rather than relying on conversation memory.
- Record each task's:
    - original finding
    - verified/current finding
    - root cause
    - files changed
    - fix applied
    - tests/checks performed
    - causality or rollback evidence when relevant
    - final state: `VERIFIED FIX` or `VERIFIED ROLLBACK`
    - genuinely unverified items
- Never guess or reconstruct a missing audit finding from memory. If the original definition cannot be recovered, stop and ask for the exact finding or source document.
- Update the audit record immediately when a task reaches its terminal state, before moving to the next task.
- Keep the audit record compact and factual so it remains useful after context compaction.
- Keep a small progress table showing completed tasks, current task, and next task.
- Never store passwords, API keys, tokens, or other secrets in the audit record.

### Context continuity

Treat repository files, the audit record, and Git history as the durable source of truth. Treat conversation context as supplemental.

When context has been compacted or partially lost:
1. Read the audit record.
2. Inspect the current repository/Git state.
3. Continue only from verified recorded state.
4. Never invent missing prior decisions, findings, or requirements.

---

# Speed & verification protocol (added)

Goal: finish each problem faster without lowering the bar. Nothing here relaxes
VERIFIED FIX / VERIFIED ROLLBACK. It changes which checks run and how they run.

## Test selection: scoped, derived from the diff, never the full suite

- Do not run the full suite (`phpunit` or `php artisan test` with no path and no filter)
  unless I explicitly say "run the full suite". CI (`tests.yml`, `architecture.yml`,
  `pint.yml`) is the full gate.
- Standard command: `php vendor/bin/phpunit <path(s)> [--filter <name>] --no-coverage`.
- Select tests from the diff, in this order:
    1. `git grep -l "<ChangedClassOrRouteOrConfigKey>" tests/` for every changed symbol.
       Run those files. This also catches pinned audit tests in `tests/Feature/Review`,
       `T3Batch` and `T4Batch`.
    2. Add the area floor from the map below. It is the minimum even if grep finds less.
    3. If any `use` line was added or removed, or a file under `app/` was added, moved or
       deleted: also run `tests/Feature/AppFuture/BoundaryDependencyTest.php`.
    4. If a changed class has no referencing test: write a targeted test (new tests go in
       `tests/Feature/Review/`) or list it under "genuinely unverified". Never skip silently.
- Shared kernel changes (`app/Models`, `app/Enums`, `app/DTOs`, `app/Interfaces`) are the
  only case where a wider run is justified. Select by `git grep` on the symbol and run the
  area floors of every context that references it. Still not the full suite.
- Never edit an existing test to make it pass.
- Before tests, run the cheap static checks on changed files only: `php -l <file>` and
  `php vendor/bin/pint --test <changed php files>`.

### Area floors (starting map; if a directory is missing, grep decides)

| Changed code | Minimum test paths |
|---|---|
| Ride lifecycle: `Services/Ride`, `Repositories/RideRepository`, `Domain/Score`, `RideController`, `RideResource` | `tests/Feature/Rides`, `tests/Feature/Bookings`, `tests/Unit/Domain` |
| Money: `Services/Wallet`, `Services/Payment`, `Domain/Payment` | `tests/Feature/Wallet`, `tests/Feature/Payment`, `tests/Unit/Domain` |
| Identity: `Services/Auth`, `Services/Staff`, `Services/Verification`, `Services/Otp`, `Middleware` | `tests/Feature/Auth`, `tests/Feature/Staff`, `tests/Feature/Otp`, `tests/Feature/Security`, `tests/Unit/Middleware` |
| Communication: Notification, PushNotification, Chat, Listeners, Events, Jobs | `tests/Feature/Notifications` |
| Console: `Admin*` controllers, `Services/Admin` | `tests/Feature/Admin` |
| `routes/`, rate limiting | `tests/Feature/RateLimiting` plus the feature directory of the affected route |
| `config/`, `app/Providers` | `tests/Feature/Config`, `tests/Unit/Providers` |

Money and Identity floors are never reduced, even for a "trivial" change. The existing
rule still applies: for security or financial changes, verify both allowed and denied cases.

## Production database safety (hard rule)

The real database (Aiven) must never be touched by tests, probes or artisan commands.
Tests use `RefreshDatabase`, so a test run against the real database would wipe it.
`.env` and the current `phpunit.xml` can both point at it. A bare `php` command that does not
set the scratch variables has already once fallen back to the Aiven host.

- Every command that boots Laravel (`phpunit`, `php artisan ...`, any `php script.php`) must
  run in the same shell, after setting the scratch DB variables from `AGENTS.local.md`.
- Before the first such command in a session, check that `DB_HOST` is `127.0.0.1` and
  `DB_PORT` is `3399`. If either differs, or if you are unsure, stop. Do not run anything.
- Never run `migrate`, `migrate:fresh`, `db:wipe` or seeders without that check.
- Never print, log or write a password, token or connection string. Redact them in output.
  If a failed connection could have put a credential into `storage/logs/laravel.log`, tell me.
- Never copy real credentials into `phpunit.xml`, the audit record or any committed file.

## Run discipline (prevents silent hangs)

- Before any DB-backed run, confirm the scratch variables are set (see the rule above), then ping the scratch DB with a 3 second timeout. Its settings are in
  `AGENTS.local.md`. Read that file. Never ask me for them and never search old logs.
  If the ping fails, restart it once as written there. If it fails again, stop and report.
  Do not wait.
- Every run has a hard wall-clock timeout: 180 s for a scoped run, 600 s for anything
  larger. On expiry, kill it and treat it as an infrastructure failure, not a test result.
  A killed or hung run proves nothing; re-run the same scoped selection.
- Never poll a background job more than twice. Prefer one blocking call. If CPU time
  barely moves for 2 minutes, check the DB and the process instead of waiting.
- Print only the summary line and failure details (about the last 40 lines). Write the full
  output to a temp file.
- Connection refused, timeout or MySQL-gone-away is infrastructure. Do not edit app code in
  response to it.

## Edit hygiene (Windows / PowerShell)

- View with line numbers before editing. Anchor on ASCII-only text or line ranges.
  Em-dashes and other non-ASCII characters in anchors have repeatedly failed to match.
- If an edit matches 0 or more than 1 times, narrow the anchor once. If it fails again,
  read the exact bytes instead of guessing.
- Do not patch a tracked file to arm a probe or flag. Use an env var or config switch. If a
  temporary patch is unavoidable, restore it in the same step and prove with
  `git diff --stat` that only the intended files changed.

## Proportional process

A change is **mechanical** only if it is behavior-preserving, about 30 changed lines or
fewer, within one context, covered by existing tests, and touches none of: money or ledger,
auth or permissions, migrations or schema, routes, config, public response shape.

- Mechanical: explain in 2-3 lines (problem, files, verify command), then proceed. No A/B/C
  menu. The end-of-problem report is unchanged.
- Anything else: the full "Explain before editing" section above applies.
- Stop and ask me before: changing money or ledger semantics; auth, token or permission
  rules; migrations or schema; deleting code or tests; changing a public API response
  shape; any change to the boundary `BASELINES`. If a change removes a violation, report
  the new number and propose lowering the baseline. Never raise one.

## Session handoff protocol (one task per session, state lives in files)

Each session starts with no memory. The only things that carry over are the repo, git
history, `docs/audit/STATE.md`, `docs/audit/BACKLOG.md` and the audit record.
"Do the next task" means follow this.

Where things live:
- `docs/audit/STATE.md`: the in-progress block, owner decisions, owner actions, and the rule
  for what is next.
- `docs/audit/BACKLOG.md`: the status authority. One table, every task, one Status per row.
  Section 4 holds acceptance criteria for open rows.
- `docs/audit/App future audit review r2.md`: the per-task log. New records go at the bottom
  as the next section number.
- Older audit files are history only. If one of them disagrees, STATE.md and BACKLOG.md win.

### Start (no code yet)
1. Read this file, then `docs/audit/STATE.md`, then the table in `docs/audit/BACKLOG.md`.
2. Run `git status --short` and `git log -10 --oneline`. Compare with the "In progress"
   block in STATE.md.
    - Changes listed there are yours from an interrupted task: finish and verify them, or
      roll them back, before anything else.
    - Changes not listed there are the owner's. Do not touch them. This is what "pre-existing
      user changes" means in the rules above.
    - If you cannot tell whose a change is, stop and ask.
3. The next task is the rule in STATE.md: the first BACKLOG.md row with Status `OPEN` and
   Blocked by `none`, at the lowest Order. A `PARTIAL` row is taken only if I name it. Before
   starting, confirm the row is still open (search the audit record and `git log` for its ID).
   If it is already done or gated, correct its BACKLOG.md row and take the next one.
4. If no row qualifies, stop. Show the "Blocked on owner" list and which decision unlocks
   which task. Do not invent work and do not guess a decision.
5. Read only the sections the task needs: its BACKLOG.md row and acceptance criteria, and
   the audit sections it cites. Never read a whole audit file.

### During
6. Before the first edit, write the task's "In progress" block in STATE.md: task ID, files
   you expect to change, and any temporary probe, backup or scratch file you create.
7. Remove every probe and temporary file before reporting. Prove it with `git status`.

### End (terminal state only)
8. Append the task record to `docs/audit/App future audit review r2.md` as a new section at
   the bottom.
9. Update `docs/audit/BACKLOG.md`: set the task's row Status (VERIFIED FIX, PARTIAL, BLOCKED,
   ROLLED BACK) and fill its Evidence (audit section and commit hash). If you skip this, the
   next session will pick the same task again. Then update STATE.md: clear "In progress", add
   one line under "Done recently", and add any new owner decisions. Never write a "next task"
   name into STATE.md; the rule derives it.
10. Commit locally on the current branch with message `<ID>: <summary>`. Include only this
    task's files, the audit record, BACKLOG.md and STATE.md. Never push. Never commit
    `phpunit.xml`, `.env` or `AGENTS.local.md`. If the task is not at VERIFIED FIX / VERIFIED
    ROLLBACK, do not commit; leave the In-progress block in place instead.
11. Report using the existing format, plus one line: `Next task: <ID derived by the rule, or
    "none unblocked">`.
12. Stop. Do not start the next task unless I ask. After a very long conversation (about 25+
    turns), say so and suggest a fresh session.
