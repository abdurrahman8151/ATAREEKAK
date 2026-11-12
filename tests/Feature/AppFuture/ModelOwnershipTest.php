<?php

namespace Tests\Feature\AppFuture;

use Tests\TestCase;

/**
 * Stage 2 C2: the data-ownership ratchet.
 *
 * WHAT IT ASSERTS
 *
 * A module may name models it owns (`App\Modules\<Self>\Models\*`) and the authenticated principal
 * (`App\Models\User`). It may not name any other model. Today every model still lives in `App\Models`,
 * so "owns" is aspirational: the pairs below are the violations that exist, and the list may only
 * SHRINK. A new pair fails the build; the fix is a Reader contract in the owning module, not a new
 * line in this file.
 *
 * WHY A COUNT OF PAIRS AND NOT OF OCCURRENCES
 *
 * A pair is (module, foreign model). Ten services in Console touching `Wallet` is one architectural
 * fact, not ten. Counting pairs means moving a query from one service to another inside the same
 * module does not churn the baseline, while a genuinely new dependency always shows up.
 *
 * NON-VACUOUS BY CONSTRUCTION
 *
 * The sweep asserts it actually found modules and files before comparing anything, and the baseline
 * asserts it is not empty. A broken glob or a moved directory fails loudly instead of reporting zero
 * violations against zero files.
 *
 * SCOPE - what this first version does NOT cover yet, deliberately:
 *
 *  - relations declared without an import (`Employee::class` from a same-namespace model), because
 *    that only appears once models start moving between namespaces;
 *  - `DB::table()` / raw SQL against another module's tables - that needs the table-to-owner map
 *    Phase D writes;
 *  - the Console reporting allowlist.
 *
 * Those are the next three ratchets, added in the commits that create the need for them.
 */
final class ModelOwnershipTest extends TestCase
{
    /** Models that belong to the kernel rather than a module. Only `User` is allowed as a type. */
    private const PRINCIPAL = 'User';

    /**
     * Ratchet 4: the Console reporting surface, named file by file.
     *
     * Console legitimately aggregates across contexts for dashboards and reports. That is a real need, so
     * those files are named here rather than pretended away - but the list is per FILE, which is stricter
     * than the pair baseline: a new Console service cannot start reading a foreign model just because some
     * other service already does. Shrink-only, reported not enforced, like the pair baseline.
     *
     * @var array<string, array<int, string>>
     */
    private const CONSOLE_FOREIGN_MODEL_FILES = [
        'app/Modules/Console/Contracts/ComplaintRepositoryInterface.php' => ['Complaint'],
        'app/Modules/Console/Contracts/EmployeeManagementInterface.php' => ['Employee'],
        'app/Modules/Console/Contracts/EmployeeRepositoryInterface.php' => ['Employee'],
        'app/Modules/Console/Contracts/SupportAgentRoutingInterface.php' => ['Employee'],
        'app/Modules/Console/Repositories/ComplaintRepository.php' => ['Complaint'],
        'app/Modules/Console/Repositories/EmployeeRepository.php' => ['Employee'],
        'app/Modules/Console/Services/AdminAuthService.php' => ['Employee'],
        'app/Modules/Console/Services/AdminDashboardActivityService.php' => ['Photo', 'Profile', 'UserRating'],
        'app/Modules/Console/Services/AdminDriverDashboardService.php' => ['Booking', 'Photo', 'Profile', 'UserRating'],
        'app/Modules/Console/Services/AdminDriverPresenter.php' => ['Profile'],
        'app/Modules/Console/Services/AdminDriverQueryService.php' => ['Booking', 'Profile', 'UserRating'],
        'app/Modules/Console/Services/AdminDriverService.php' => ['Photo', 'Profile'],
        'app/Modules/Console/Services/AdminReportService.php' => ['Booking', 'Complaint', 'Profile'],
        'app/Modules/Console/Services/AdminUserService.php' => ['Profile'],
        'app/Modules/Console/Services/AdminWalletRequestService.php' => ['Wallet', 'WalletRequest'],
        'app/Modules/Console/Services/AdminWalletService.php' => ['Wallet'],
        'app/Modules/Console/Services/ComplaintService.php' => ['Complaint', 'ComplaintAttachment', 'Employee'],
        // Stage 2 C2: the bumper for Identity's session invalidation - it writes Console's own table.
        'app/Modules/Console/Services/EmployeeTokenVersionBumper.php' => ['Employee'],
        // Stage 2 C2: Ride's no-show conflict opens its complaint through this Console file.
        'app/Modules/Console/Services/ComplaintRecorderService.php' => ['Complaint'],
        'app/Modules/Console/Services/EmployeeManagementService.php' => ['Employee'],
        'app/Modules/Console/Services/ReviewModerationService.php' => ['ProfileComment'],
        'app/Modules/Console/Services/StaffBookingQueryService.php' => ['Booking'],
        'app/Modules/Console/Services/StaffCancellationService.php' => ['Booking'],
        'app/Modules/Console/Services/StaffComplaintService.php' => ['Complaint', 'Employee'],
        'app/Modules/Console/Services/StaffUserProfileService.php' => ['Profile', 'UserRating'],
        'app/Modules/Console/Services/SupportAgentRoutingService.php' => ['Employee'],
    ];

    /**
     * Tables a module has already taken ownership of, and the module owning them.
     *
     * Grows as Money, Ride, Identity and Console move their models. A table that has not been taken over
     * is not policed, so this ratchet describes reality instead of blocking the moves that create it.
     *
     * @var array<string, string>
     */
    private const TABLE_OWNERS = [
        'conversations' => 'Communication',
        'conversation_participants' => 'Communication',
        'messages' => 'Communication',
        'notifications' => 'Communication',
        'user_notifications' => 'Communication',
        'push_notification_tokens' => 'Communication',
        // Stage 2 C1: the first two Ride tables whose class moved into the module.
        'score_transactions' => 'Ride',
        'user_scores' => 'Ride',
    ];

    /**
     * The violations that exist today: (module -> foreign kernel model) => the tables the model owns.
     * Shrink-only. Never add a line; remove one when the module stops naming that model.
     *
     * @var array<string, string>
     */
    private const KERNEL_MODEL_IMPORTS = [
        'Communication -> Profile' => 'profiles',
        'Console -> Booking' => 'bookings',
        'Console -> Complaint' => 'complaints',
        'Console -> ComplaintAttachment' => 'complaint_attachments',
        'Console -> Employee' => 'employees',
        'Console -> Photo' => 'photos',
        'Console -> Profile' => 'profiles',
        'Console -> ProfileComment' => 'profile_comments',
        'Console -> Wallet' => 'wallets',
        'Console -> WalletRequest' => 'wallet_requests',
        'Identity -> Booking' => 'bookings',
        'Identity -> Complaint' => 'complaints',
        'Identity -> Employee' => 'employees',
        'Identity -> Photo' => 'photos',
        'Identity -> Profile' => 'profiles',
        'Identity -> ProfileComment' => 'profile_comments',
        'Identity -> UserRating' => 'user_ratings',
        'Money -> Booking' => 'bookings',
        'Money -> LedgerEntry' => 'ledger_entries',
        'Money -> Wallet' => 'wallets',
        'Money -> WalletRequest' => 'wallet_requests',
        'Money -> WalletTransaction' => 'wallet_transactions',
        'Ride -> Booking' => 'bookings',
        'Ride -> NoshowReport' => 'noshow_reports',
        'Ride -> Ride' => 'rides',
    ];

    /** @test */
    public function a_module_may_only_name_models_it_owns(): void
    {
        $modules = $this->moduleDirectories();
        $this->assertGreaterThanOrEqual(
            5,
            count($modules),
            'The module sweep found fewer modules than exist - the scan is broken, not the codebase.'
        );

        $found = $this->foreignModelPairs($modules);
        $this->assertNotEmpty($found, 'The scan found no model imports at all - it is not looking where modules are.');

        $declared = self::KERNEL_MODEL_IMPORTS;
        $this->assertNotEmpty($declared, 'A empty baseline would make this ratchet vacuous.');

        $new = array_values(array_diff(array_keys($found), array_keys($declared)));
        sort($new);
        $this->assertSame(
            [],
            $new,
            "A module started naming a model it does not own:\n  ".implode("\n  ", $new)
            ."\nAdd a Reader contract in the owning module's Contracts instead of a line here."
        );
    }

    /**
     * Shrink-only, reported rather than enforced the way the query budgets are: when a pair disappears,
     * this prints it so the baseline can be lowered in the commit that removed it. It never fails, so a
     * legitimate improvement is never blocked by a stale line.
     *
     * @test
     */
    public function the_baseline_is_shrink_only_and_reports_what_can_be_removed(): void
    {
        $found = array_keys($this->foreignModelPairs($this->moduleDirectories()));
        $gone = array_values(array_diff(array_keys(self::KERNEL_MODEL_IMPORTS), $found));

        if ($gone !== []) {
            sort($gone);
            fwrite(STDERR, "\n  [model-ownership] baseline can shrink - remove: ".implode(', ', $gone)."\n");
        }

        // Nothing to enforce: the assertion above already fails on a NEW pair. This test exists to make
        // the stale entries visible instead of letting the list rot until someone re-derives it.
        $this->assertTrue(true);
    }

    /** @return array<int, string> */
    private function moduleDirectories(): array
    {
        $base = base_path('app/Modules');
        if (! is_dir($base)) {
            return [];
        }

        $modules = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($base.'/'.$entry)) {
                $modules[] = $entry;
            }
        }

        return $modules;
    }

    /**
     * Ratchet 4: no Console file starts reading a foreign model unless it is on the named list.
     *
     * The pair baseline says "Console may read Wallet". This says WHICH files may - so moving a query into
     * a new service, or a new reporting endpoint quietly importing a model, fails even though the pair
     * already exists. Every entry also records which models that file touches, so the list documents the
     * reporting surface instead of just exempting it.
     *
     * @test
     */
    public function console_only_touches_foreign_models_from_named_files(): void
    {
        $this->assertNotEmpty(self::CONSOLE_FOREIGN_MODEL_FILES, 'An empty allowlist would make this ratchet vacuous.');

        $scan = $this->consoleForeignModelFiles();
        $this->assertNotEmpty($scan, 'The Console scan found no foreign model references - it is not reading the module.');

        $new = array_values(array_diff(array_keys($scan), array_keys(self::CONSOLE_FOREIGN_MODEL_FILES)));
        sort($new);
        $this->assertSame(
            [],
            $new,
            "A Console file not on the reporting allowlist names a foreign model:\n  ".implode("\n  ", $new)
            ."\nAdd a Reader contract in the owning module's Contracts, or justify the file here in the same commit."
        );

        // Per-file AND per-model: a listed file may not pick up a model it did not have.
        $growth = [];
        foreach ($scan as $file => $classes) {
            $allowed = self::CONSOLE_FOREIGN_MODEL_FILES[$file] ?? [];
            foreach (array_diff($classes, $allowed) as $class) {
                $growth[] = $file.' -> '.$class;
            }
        }
        sort($growth);
        $this->assertSame([], $growth, "A listed Console file grew a new foreign model:\n  ".implode("\n  ", $growth));

        $gone = array_values(array_diff(array_keys(self::CONSOLE_FOREIGN_MODEL_FILES), array_keys($scan)));
        if ($gone !== []) {
            fwrite(STDERR, "\n  [model-ownership] Console allowlist can shrink - remove: ".implode(', ', $gone)."\n");
        }
    }

    /**
     * @return array<string, array<int, string>> relative path => foreign models it names
     */
    private function consoleForeignModelFiles(): array
    {
        $files = [];
        $root = base_path('app/Modules/Console');
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/App\\\\Models\\\\([A-Za-z0-9_]+)/', (string) file_get_contents($file->getPathname()), $matches);
            $classes = array_values(array_unique(array_filter(
                $matches[1],
                fn ($c) => $c !== self::PRINCIPAL && $c !== 'Concerns'
            )));

            if ($classes === []) {
                continue;
            }

            sort($classes);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $files[$relative] = $classes;
        }

        ksort($files);

        return $files;
    }

    /**
     * Ratchet 3: a module may not read or write another module's tables, even through the query builder.
     *
     * The import ratchets police *model* references. A raw `DB::table('messages')` from inside Console
     * names no model at all, so it slips past both of them while being exactly the coupling the ownership
     * rule forbids - a join across an ownership boundary that no contract can see and no DTO can shape.
     *
     * Scope: only tables whose owning module has already taken them over. Today that is Communication's six.
     * As Money, Ride, Identity and Console take their tables, entries are added to TABLE_OWNERS and the
     * ratchet starts guarding them too. That keeps the list honest - it states what is true, not what is
     * planned - and means this test never blocks a move it is not yet describing.
     *
     * @test
     */
    public function a_module_does_not_reach_into_another_modules_tables(): void
    {
        $this->assertNotEmpty(self::TABLE_OWNERS, 'An empty owner map would make this ratchet vacuous.');

        $usages = $this->rawTableUsages();
        $this->assertNotEmpty($usages, 'The raw-SQL scan found no DB::table calls at all - it is not reading the modules.');

        $violations = [];
        foreach ($usages as $usage) {
            $owner = self::TABLE_OWNERS[$usage['table']] ?? null;

            // Only tables that have actually been taken over are policed.
            if ($owner === null || $owner === $usage['module']) {
                continue;
            }

            $violations[$usage['module'].' -> '.$usage['table'].' ('.$owner.')'] = true;
        }

        ksort($violations);
        $this->assertSame(
            [],
            $violations,
            "A module reached into another module's table:\n  ".implode("\n  ", array_keys($violations))
            ."\nAdd a Reader contract in the owning module's Contracts instead of joining across the boundary."
        );
    }

    /**
     * Every `DB::table('<name>')` in a module, with the module that issued it.
     *
     * @return array<int, array{module: string, table: string}>
     */
    private function rawTableUsages(): array
    {
        $usages = [];
        $pattern = "/DB::table\\(\\s*'([a-z_]+)'/";

        foreach (glob(base_path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
                foreach (array_unique($matches[1]) as $table) {
                    $usages[] = ['module' => $module, 'table' => $table];
                }
            }
        }

        return $usages;
    }

    /**
     * Ratchet 2: a module model's relations must point at its own module or at the principal.
     *
     * This is the check that the import ratchet cannot make: `WalletRequest::processorEmployee()` declared
     * `belongsTo(Employee::class)` with **no import**, because both classes lived in `App\Models`. Nothing
     * text-matched it; it only surfaced when the move would have turned it into a `Money -> Console` edge.
     * Resolving each `X::class` through the file's own `use` block and namespace is what closes that hole.
     *
     * @test
     */
    public function a_module_model_only_relates_to_its_own_module_or_the_principal(): void
    {
        $scanned = $this->moduleModelRelations();
        $this->assertGreaterThanOrEqual(
            6,
            $scanned['relations'],
            'Found fewer relations than the Communication models declare - the relation scan is broken.'
        );

        $violations = $scanned['foreign'];
        ksort($violations);
        $this->assertSame(
            [],
            $violations,
            "A module model relates to something it does not own:\n  ".implode("\n  ", array_keys($violations))
            ."\nUse a Reader contract in the owning module's Contracts instead."
        );
    }

    /**
     * Every relation declared by a module model, resolved to a fully-qualified target class.
     *
     * @return array{relations: int, foreign: array<string, true>}
     */
    private function moduleModelRelations(): array
    {
        $relations = 0;
        $foreign = [];
        $relationPattern = '/->(?:belongsTo|hasMany|hasOne|belongsToMany|morphMany|morphTo)\(\s*([A-Za-z0-9_]+)::class/';

        foreach (glob(base_path('app/Modules/*/Models/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            preg_match('/namespace\s+([^;]+);/', $source, $nsMatch);
            $namespace = trim($nsMatch[1] ?? 'App');

            $imports = [];
            preg_match_all('/^use\s+([^;]+);/m', $source, $importMatches);
            foreach ($importMatches[1] as $import) {
                $import = trim($import);
                if (str_contains($import, ' as ')) {
                    [$import] = explode(' as ', $import);
                    $import = trim($import);
                }
                $short = substr($import, (int) strrpos($import, '\\') + 1);
                $imports[$short] = $import;
            }

            preg_match_all($relationPattern, $source, $matches);

            foreach ($matches[1] as $short) {
                $relations++;
                $target = $imports[$short] ?? $namespace.'\\'.$short;

                if ($target === 'App\Models\User') {
                    continue;
                }

                // A model of the same module: App\Modules\<Module>\Models\*
                // The path is matched separator-agnostically: glob() hands back forward slashes even on
                // Windows, so comparing against DIRECTORY_SEPARATOR silently reported every sibling.
                if (preg_match('#^App\\\\Modules\\\\([^\\\\]+)\\\\Models\\\\#', $target, $targetMatch)
                    && preg_match('#Modules[/\\\\]([^/\\\\]+)[/\\\\]Models#', $path, $selfMatch)
                    && $targetMatch[1] === $selfMatch[1]) {
                    continue;
                }

                $foreign[basename($path).' -> '.$target] = true;
            }
        }

        return ['relations' => $relations, 'foreign' => $foreign];
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<string, true> pair => true
     */
    private function foreignModelPairs(array $modules): array
    {
        $pairs = [];
        $pattern = '/App\\\\Models\\\\([A-Za-z0-9_]+)/';

        foreach ($modules as $module) {
            $root = base_path('app/Modules/'.$module);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
                foreach (array_unique($matches[1]) as $model) {
                    if ($model === self::PRINCIPAL || $model === 'Concerns') {
                        continue;
                    }

                    $pairs[$module.' -> '.$model] = true;
                }
            }
        }

        return $pairs;
    }
}
