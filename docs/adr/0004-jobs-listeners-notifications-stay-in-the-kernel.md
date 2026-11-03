# 0004 - Jobs, listeners and notifications stay in the kernel

**Status:** accepted (stage 1 phase 2, 2026-11; codified here in stage 1.5)

## Context

After the modules were extracted, the obvious next step was to move every queued job, listener and
notification into the module that dispatches or handles it. That is what a "clean" modular monolith
usually does, and it is what `ModuleBoundaryTest` rule D's ceiling would eventually reward.

But queue payloads in Laravel are **class names**: a job pushed today is stored in the `jobs` table (or
in Redis) as `App\Jobs\SendPushNotification` together with its serialized properties, and a worker
running last week's code has to be able to unserialize it. Moving that class changes the payload of
every job already queued, and a deploy that renames a queued job class while old payloads are still in
the queue loses those jobs - silently, because the worker just logs a "could not be instantiated"
exception that nobody reads.

## Decision

`app/Jobs`, `app/Listeners`, `app/Notifications` and `app/Observers` stay in the kernel, and the few
that need a module capability take it through that module's `Contracts`:

- `Jobs/SendPushNotification.php`, `Jobs/SendPushNotificationJob.php` -> `Communication/Contracts/PushNotifierInterface`
- `Listeners/SendUserVerifiedNotification.php` -> `Communication/Contracts/NotificationPort`
- `Mail/OtpMailer.php` -> `Identity/Contracts/OtpMailInterface`
- `Observers/UserObserver.php` -> `Communication/Contracts/NotificationPort`

Those four files (plus the kernel's own wiring) are the `KERNEL_CONTRACTS_CEILING = 5` in
`ModuleBoundaryTest`: the number is a ceiling, it may only shrink, and each commit that lowers it says so.
Stage 1.5 item 4 moved the fifth binding (`OtpMailInterface` -> `App\Mail\OtpMailer`) out of a module
provider into the kernel catalogue for exactly this reason.

## Consequences

- The kernel keeps a small, enumerated, ratcheted surface instead of a clean one. That is the trade:
  four files of kernel -> module Contracts instead of a silent queue-payload hazard.
- A job that needs new module data gets it through an existing port or the port is widened deliberately -
  never by importing the module's service.
- The sample Jobs in `app/Jobs` that a future module might want are placeholders for this ADR: moving
  them is a payload migration, not a file move, and it needs a migration plan (drain the queue, deploy,
  then rename) that no stage-1 commit should improvise.

## Alternatives rejected

- **Move them into modules and accept the breakage.** Rejected: the queue is not a cache, and a lost job
  is a lost push notification or OTP; the failure is silent by construction.
- **Keep the class name in the kernel and delegate to a module service from inside the job.** That is
  what these files do today - the job is the stable name, the port is the module edge. It is the same
  decision, just described from the other end.
- **Stable queue-payload aliases (`class_alias` or a custom payload resolver).** A real technique, and
  the right one if jobs ever do need to move. It is a framework-level change with its own tests, parked
  rather than improvised.
