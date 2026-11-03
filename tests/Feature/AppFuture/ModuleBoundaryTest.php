<?php

namespace Tests\Feature\AppFuture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * STAGE 1 (owner's goal, 2026-11) - the module boundary ratchet.
 *
 * `app/Modules/{Communication,Money,Ride,Identity,Console}` is the real module layout. A folder
 * move means nothing unless something refuses to let the boundaries rot again, so this file is the
 * rule:
 *
 *   A. MODULE FILES (anything under app/Modules/<M>/) may name their OWN module freely, may name
 *      any module's `Contracts` (the published ports), and may name the kernel (Models, Enums,
 *      DTOs, Events, Exceptions, ValueObjects, Middleware, Requests, Resources, Support, ...).
 *      They may NOT name another module's implementation. Baseline: HARD 0.
 *
 *   B. KERNEL FILES (anything else in app/) may name module `Contracts` - that is what ports are
 *      for - but may NOT name module implementation. Baseline: HARD 0.
 *
 *   C. KERNEL FILES that name module IMPLEMENTATION code (anything outside `Contracts`) are a real
 *      exemption, so they are counted and listed explicitly - never silently absorbed. As of stage
 *      1.5 item 3 that list is EMPTY: the six production entrypoints that used to name
 *      implementation classes (four artisan commands and the two JWT middleware) now type-hint
 *      ports, and `ENTRYPOINT_ALLOWLIST` is `[]`. One directory is exempt wholesale instead -
 *      `Console/Testing/`, the dev-only tools that `App\Console\Kernel::commands()` loads only when
 *      the environment is `local` or `testing`, pinned by `DevCommandRegistrationTest`. An
 *      entrypoint that starts naming module implementation must be listed here deliberately, or
 *      converted to a port, in a commit that says which and why.
 *
 *   D. The kernel's use of module Contracts is a transitional number, not a target: in stage 1
 *      phase 1 it was 87 files, because the ports moved to their modules while their current
 *      callers were still kernel files (controllers, services, repositories). Phase 2 moved those
 *      callers into their own modules, where naming another module's Contracts is the sanctioned
 *      edge, and the number is now the handful of kernel files that legitimately remain (the
 *      queued Jobs, the verified-user listener, the user observer, the OTP commander). It is a
 *      CEILING: it may only be lowered, and each commit that lowers it records the new number.
 *
 *   E. Every scan asserts a file floor, so a move that quietly empties a module (or the whole scan)
 *      cannot make this test pass vacuously. Matching is substring on 'App\Modules\' - deliberately
 *      dumb, like BoundaryDependencyTest, because regex escaping has burned this codebase before.
 */
class ModuleBoundaryTest extends TestCase
{
    private const MODULES = ['Communication', 'Money', 'Ride', 'Identity', 'Console'];

    /**
     * Files per module, measured 2026-11-02. Phase 2 raises each floor as the module's code arrives
     * - the floor may never be lowered, so a module cannot silently lose files.
     * Communication: 13 code files + 5 port files (4 moved in phase 1 + PushNotifierInterface) + its
     * provider.
     */
    private const MODULE_FILE_FLOORS = [
        'Communication' => 19,
        // Money: 26 code files + 8 ports + its provider.
        'Money' => 35,
        // Ride: 39 code files + 6 ports + its provider.
        'Ride' => 46,
        // Identity: 41 code files + 13 ports (incl. EmployeeAuthInterface) + its provider.
        'Identity' => 55,
        // Console: 42 code files + 4 ports + its provider.
        'Console' => 47,
    ];

    /**
     * app/ holds 352 PHP files today. Phase 2 moves files between the kernel and the modules, never
     * out of both, so the total is stable and this floor is what makes the scan provably total.
     */
    private const MIN_SCANNED_FILES = 353;

    /** Measured kernel -> module Contracts file count. Ceiling: lower it in phase 2, never raise it. */
    private const KERNEL_CONTRACTS_CEILING = 5;

    /**
     * Entrypoints that name module IMPLEMENTATION code. Measured 2026-11-02, emptied 2026-11-03.
     *
     * History, because the empty list is the point: after the Communication move this list was
     * already empty (the console files touching that module all went through its Contracts). The
     * Ride and Identity moves then put twelve files on it - four artisan commands, the two JWT
     * middleware, and the six dev-only tools under `app/Console/Testing` - because the surfaces they
     * called (`Noshowservice::resolveExpiredReports`, `JwtService::decodeToken`/`findUserCached`,
     * `StaffJwtService::cleanupExpiredTokens`/`revokeAllTokens`, `BanService::liftExpiredBan`) were
     * named by no port. Stage 1.5 item 3 added a port per surface - six new interfaces in the owning
     * modules' Contracts, each bound to the same singleton the concrete was - and all six production
     * entrypoints now type-hint a port, so this list is empty again.
     *
     * The six dev-only tools did not get ports, and should not: they are not production entrypoints
     * and the exemption that covers them is the directory one below.
     *
     * The list is shrink-only, and it is at zero: an entry may only be added together with a port
     * that removes it.
     */
    private const ENTRYPOINT_ALLOWLIST = [];

    /**
     * The one directory-level exemption (stage 1.5 item 3). `Console/Testing/` holds the
     * state-fabricating tools - `Testfullrideflow`, `Testridecompletionflow`,
     * `Testridegatedinteractioncommand`, `TestNotificationCommand`, `Getloadtesttokens` and the
     * `Support/` scenario classes. They name module implementation code freely, and that is correct:
     * they are not production entrypoints at all. `App\Console\Kernel::commands()` loads the
     * directory only inside `if ($this->app->environment(['local', 'testing']))`, so on a production
     * box the classes are never registered and no route, command or queue payload can reach them.
     * `DevCommandRegistrationTest` pins that guard, so this exemption cannot quietly become a
     * production hole.
     *
     * One exemption for the directory rather than six file entries: what is being exempted is a
     * property of how the directory is registered, not of any individual command, and a per-file list
     * would have to grow every time a dev tool is added without anything actually changing.
     */
    private const ENTRYPOINT_DIRECTORY_EXEMPTIONS = [
        'Console/Testing/' => 'dev-only tools, loaded only when the environment is local or testing',
    ];

    /** @var array{files: array<string, string>, refs: array<string, array<int, string>>}|null */
    private static ?array $scan = null;

    /**
     * Scan app/ once: every PHP file, the module it belongs to (or null for the kernel), and every
     * `App\Modules\...` symbol the file names.
     *
     * @return array{files: array<string, string>, refs: array<string, array<int, string>>}
     */
    private static function scan(): array
    {
        if (self::$scan !== null) {
            return self::$scan;
        }

        $appDir = dirname(__DIR__, 3).'/app';
        $files = [];
        $refs = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/App\\\\Modules\\\\[A-Za-z0-9_\\\\]+/', $source, $matches);
            $files[$relative] = self::moduleOf($relative) ?? '';
            $refs[$relative] = $matches[0];
        }

        return self::$scan = ['files' => $files, 'refs' => $refs];
    }

    private static function moduleOf(string $relativePath): ?string
    {
        if (! str_starts_with($relativePath, 'Modules/')) {
            return null;
        }
        $parts = explode('/', $relativePath);

        return in_array($parts[1] ?? '', self::MODULES, true) ? $parts[1] : null;
    }

    private static function isContracts(string $reference): bool
    {
        return str_contains($reference.'\\', '\\Contracts\\');
    }

    /**
     * True when the path sits under a directory that is exempt wholesale, with the reason recorded
     * next to the prefix so the exemption and its justification cannot drift apart.
     */
    private static function isDirectoryExempt(string $relativePath): bool
    {
        foreach (self::ENTRYPOINT_DIRECTORY_EXEMPTIONS as $prefix => $reason) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isEntrypoint(string $relativePath): bool
    {
        $prefixes = [
            'Console/Commands/',
            'Console/Testing/',
            'Http/Middleware/',
            'Providers/',
            'Support/ContainerBindings.php',
            'Console/Kernel.php',
            'Http/Kernel.php',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * E. Anti-vacuity: the scan must cover the whole tree and every module must still hold files.
     */
    public function test_the_scan_covers_every_module_and_the_whole_app_tree(): void
    {
        $scan = self::scan();

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            count($scan['files']),
            'The module boundary scan found fewer app/ files than it must. Either files were deleted '
            .'or the scan no longer walks app/ - a scan that sees nothing passes every rule vacuously.'
        );

        $perModule = array_count_values(array_filter($scan['files']));
        foreach (self::MODULE_FILE_FLOORS as $module => $floor) {
            $count = $perModule[$module] ?? 0;
            $this->assertGreaterThanOrEqual(
                $floor,
                $count,
                "app/Modules/{$module} holds {$count} PHP files, below its floor of {$floor}. "
                .'A module that loses files to a move (or to a glob that stopped matching) must fail here.'
            );
        }

        foreach (self::MODULES as $module) {
            $this->assertFileExists(
                dirname(__DIR__, 3)."/app/Modules/{$module}/Providers/{$module}ServiceProvider.php",
                "app/Modules/{$module} must keep its own service provider."
            );
        }
    }

    /**
     * A. A module may reach another module only through that module's Contracts.
     */
    public function test_module_files_may_only_reach_other_modules_through_their_contracts(): void
    {
        $scan = self::scan();
        $violations = [];

        foreach ($scan['files'] as $relative => $module) {
            if ($module === '') {
                continue;
            }
            foreach ($scan['refs'][$relative] as $reference) {
                $target = explode('\\', $reference)[2] ?? null;
                if ($target === null || $target === $module || self::isContracts($reference)) {
                    continue;
                }
                $violations[] = "app/{$relative} ({$module}) -> {$reference}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "A module named another module's implementation:\n  ".implode("\n  ", $violations)
            ."\nMove the call behind the target module's Contracts, or move the file into the module that owns it."
        );
    }

    /**
     * B. The kernel may not name module implementation - only module Contracts.
     */
    public function test_kernel_files_may_not_name_module_implementation(): void
    {
        $scan = self::scan();
        $violations = [];

        foreach ($scan['files'] as $relative => $module) {
            if ($module !== '' || self::isEntrypoint($relative)) {
                continue;
            }
            foreach ($scan['refs'][$relative] as $reference) {
                if (self::isContracts($reference)) {
                    continue;
                }
                $violations[] = "app/{$relative} -> {$reference}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Kernel code named a module's implementation:\n  ".implode("\n  ", $violations)
            ."\nKernel code may use a module only through its Contracts (ports)."
        );
    }

    /**
     * C. The entrypoint exemption is a named list, checked against what the tree actually does. It
     * counts IMPLEMENTATION references only: a Contracts reference is the sanctioned edge that rule B
     * already allows, so counting it here would nag about the boundary working as designed.
     */
    public function test_entrypoints_that_name_module_code_are_exactly_the_named_exemption(): void
    {
        $scan = self::scan();
        $measured = [];

        foreach ($scan['files'] as $relative => $module) {
            if ($module !== '' || ! self::isEntrypoint($relative) || self::isDirectoryExempt($relative)) {
                continue;
            }
            $implementations = array_filter($scan['refs'][$relative], static fn ($r) => ! self::isContracts($r));
            if ($implementations !== []) {
                $measured[$relative] = count($implementations);
            }
        }

        // Non-vacuity: the directory exemption must actually cover the dev-only tools it names.
        // Measured 2026-11-03: 8 files under app/Console/Testing (6 commands, 2 Support classes).
        $exempted = array_filter(array_keys($scan['files']), [self::class, 'isDirectoryExempt']);
        $this->assertGreaterThanOrEqual(
            6,
            count($exempted),
            'The Console/Testing directory exemption covered only '.count($exempted).' files.'
        );

        $this->assertSame(
            self::ENTRYPOINT_ALLOWLIST,
            $measured,
            "The entrypoint exemption list no longer matches the tree.\n"
            .'Measured: '.json_encode($measured)."\n"
            .'Declared: '.json_encode(self::ENTRYPOINT_ALLOWLIST)."\n"
            .'If an entrypoint started (or stopped) naming module code, update ENTRYPOINT_ALLOWLIST in the same commit.'
        );
    }

    /**
     * D. The kernel's Contracts usage may only shrink, and each phase-2 commit lowers this ceiling.
     */
    public function test_kernel_use_of_module_contracts_does_not_grow(): void
    {
        $scan = self::scan();
        $users = [];

        foreach ($scan['files'] as $relative => $module) {
            if ($module !== '' || self::isEntrypoint($relative)) {
                continue;
            }
            $contracts = array_filter($scan['refs'][$relative], [self::class, 'isContracts']);
            if ($contracts !== []) {
                $users[] = $relative;
            }
        }

        $this->assertLessThanOrEqual(
            self::KERNEL_CONTRACTS_CEILING,
            count($users),
            count($users).' kernel files name module Contracts, above the ceiling of '.self::KERNEL_CONTRACTS_CEILING
            .". New kernel use of a module port must be reviewed, not absorbed:\n  ".implode("\n  ", $users)
        );

        $this->assertNotEmpty(
            $users,
            'No kernel file names a module Contracts any more. That is either the end state of phase 2 '
            .'(then delete this test and say so in the audit) or the scan stopped working.'
        );
    }

    /**
     * The ports moved in phase 1(b); nothing may quietly re-add a port to the old home.
     */
    public function test_the_ports_no_longer_live_in_app_interfaces(): void
    {
        $legacy = [];
        $dir = dirname(__DIR__, 3).'/app/Interfaces';
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $legacy[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                }
            }
        }

        $this->assertSame(
            [],
            $legacy,
            'app/Interfaces must be empty - every port lives in the Contracts namespace of the module that '
            ."implements it:\n  ".implode("\n  ", $legacy)
        );
    }
}
