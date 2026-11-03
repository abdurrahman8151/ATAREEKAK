# 0005 - `app/Console/Testing` is exempt as a directory, not per file

**Status:** accepted (stage 1.5 item 3, 2026-11-03)

## Context

`app/Console/Testing` holds the state-fabricating tools the project uses to exercise ride flows by hand:
`Testfullrideflow`, `Testridecompletionflow`, `Testridegatedinteractioncommand`,
`TestNotificationCommand`, `Getloadtesttokens`, and their `Support/` scenario classes. They create rides,
complete them, fabricate no-show reports and mint tokens - by design they name module implementation code
directly, because their job is to drive the real services.

They were previously listed one by one in `ModuleBoundaryTest::ENTRYPOINT_ALLOWLIST` (six of the twelve
entries). That list is a per-file exemption with a measured reference count, which is right for a
production entrypoint that has not been ported yet: it makes the debt visible and countable. It is wrong
for these six, because what makes them safe is not a property of any individual command - it is that the
whole directory is never registered on a production box.

## Decision

One directory-level exemption, with its reason stored next to the prefix:

```php
private const ENTRYPOINT_DIRECTORY_EXEMPTIONS = [
    'Console/Testing/' => 'dev-only tools, loaded only when the environment is local or testing',
];
```

`App\Console\Kernel::commands()` loads `__DIR__.'/Testing'` only inside
`if ($this->app->environment(['local', 'testing']))`. `tests/Feature/AppFuture/DevCommandRegistrationTest`
pins that guard from both sides: the environment check must test exactly `local` and `testing`, must be
the only environment guard in the file, must be the single load of that directory, and the five dev
commands must actually be registered in the testing environment (with a floor on the directory's files).
The test does not boot a second application as production - re-bootstrapping the console kernel inside a
test re-runs `routes/console.php` and re-registers commands - so the guard's *shape* is asserted instead,
and the audit says so rather than claiming a production boot was run.

The production half of the old allowlist was not exempted at all: those six files were converted to
ports (ADR 0003), and `ENTRYPOINT_ALLOWLIST` is now empty.

## Consequences

- A seventh dev tool needs no allowlist edit. That is the point: the exemption follows the directory's
  registration, not its contents.
- If someone ever loads that directory unconditionally, `DevCommandRegistrationTest` fails - the
  exemption's justification and its test move together.
- The exemption does **not** cover `app/Console/Commands`: a production command naming module
  implementation still needs a port or an allowlist entry, and stage 1.5 item 3 converted the four that
  were there.

## Alternatives rejected

- **Per-file entries (the status quo).** Six entries that all say the same thing, growing by one every
  time a dev tool is added, and silently implying that each command was individually reviewed.
- **Moving the tools into `tests/`.** They are artisan commands, not test classes; `Getloadtesttokens` is
  used against a local server and the others are used by hand during manual QA. `tests/` is not
  autoloaded for artisan.
- **Registering the directory for every environment and gating each command on `app()->isProduction()`.**
  More code, more places to forget the check, and a production box that still autoloads classes that
  fabricate rides.
