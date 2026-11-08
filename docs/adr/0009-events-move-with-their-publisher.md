# 0009 - Domain events move with their publisher; listeners, jobs and notifications stay in the kernel

**Status:** accepted (stage 2). Supersedes the events half of [0004](0004-jobs-listeners-notifications-stay-in-the-kernel.md).

## Context

ADR 0004 put jobs, listeners, notifications and events all in the kernel, for one reason: a queued class that
is serialised into a payload must keep its name, and moving it is a deploy-time hazard rather than a code
change. That reasoning holds for anything a queue payload can name - and it is exactly why events needed a
second look: `App\Events\MessageSent` is dispatched by Communication and by nobody else, so leaving it in the
kernel made the kernel the publisher of a domain fact the kernel does not own.

## Decision

**Domain events move with the module that dispatches them.** Communication's `MessageSent` and
`NotificationSent` now live in `App\Modules\Communication\Events`.

**Listeners, jobs and notifications stay in the kernel** (ADR 0004 stands for them): they are pulled from the
container by class name, and several are shared entrypoints rather than any one module's code.

## Consequences

- **Deploy note: drain the queue before deploying.** `MessageSent` and `NotificationSent` both use
  `SerializesModels`, so a job or listener payload that serialised the old class name will fail to deserialise
  after the move. Deploy only with an empty queue (or drain it first). This is the one item in stage 2 that
  cannot be proved safe by a test suite.
- The kernel's `Events` directory keeps the framework-level events; module facts live with their module.
- A future move of a queued class repeats this note. It is a property of `SerializesModels`, not of this
  particular pair.

## Alternatives rejected

- **Move jobs and listeners too.** Loses the deploy safety ADR 0004 was written for, for no ownership gain:
  listeners are wired by the framework, not by a module's public surface.
- **Leave events in the kernel.** Keeps the kernel publishing a Communication domain fact.
