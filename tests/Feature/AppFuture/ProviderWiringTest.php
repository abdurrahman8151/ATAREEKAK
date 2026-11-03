<?php

declare(strict_types=1);

namespace Tests\Feature\AppFuture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1.5 item 1(b): the five module providers and `Support/Bindings` must name real classes and
 * every registration must resolve through the container.
 *
 * Stage 1 moved 80 registrations into five providers by hand. A typo in one of them is invisible to
 * the suite until something happens to resolve that binding - `RideServiceProvider`'s dangling
 * `ScorePolicyFactory` import is exactly that shape of bug, and `DanglingImportTest` now catches the
 * name half of it. This test catches the wiring half: the abstract is bound, it resolves, the
 * instance is the concrete it was bound to, and a `singleton` really is shared.
 */
final class ProviderWiringTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> the files whose registrations are checked */
    private const PROVIDERS = [
        'app/Modules/Communication/Providers/CommunicationServiceProvider.php',
        'app/Modules/Money/Providers/MoneyServiceProvider.php',
        'app/Modules/Ride/Providers/RideServiceProvider.php',
        'app/Modules/Identity/Providers/IdentityServiceProvider.php',
        'app/Modules/Console/Providers/ConsoleServiceProvider.php',
        'app/Support/ContainerBindings.php',
    ];

    public function test_every_provider_names_only_real_classes_and_each_registration_resolves(): void
    {
        $registrations = [];
        foreach (self::PROVIDERS as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path);

            foreach ($this->registrationsIn((string) file_get_contents($path), $relative) as $registration) {
                $registrations[] = $registration;
            }
        }

        // 75 registrations moved out of ContainerBindings in phase 1, plus the ones that were already
        // in the module providers. A floor keeps a broken parser from passing on an empty list.
        $this->assertGreaterThanOrEqual(
            70,
            count($registrations),
            'Parsed only '.count($registrations).' registrations - the provider format changed and this '
            .'test is no longer reading it.'
        );

        $missing = [];
        $unresolved = [];
        $wrongConcrete = [];
        $notShared = [];

        foreach ($registrations as $r) {
            foreach ([$r['abstract'], $r['concrete']] as $fqcn) {
                if ($fqcn === null) {
                    continue;
                }
                if (! class_exists($fqcn) && ! interface_exists($fqcn)) {
                    $missing[] = $r['source'].' -> '.$fqcn;
                }
            }

            if ($r['kind'] === 'instance' || $r['kind'] === 'alias') {
                continue;
            }

            $abstract = $r['abstract'];
            if (! class_exists($abstract) && ! interface_exists($abstract)) {
                continue; // already reported above
            }

            if (! app()->bound($abstract)) {
                $unresolved[] = $r['source'].' -> '.$abstract.' (not bound)';

                continue;
            }

            try {
                $first = app()->make($abstract);
                $second = app()->make($abstract);
            } catch (\Throwable $e) {
                $unresolved[] = $r['source'].' -> '.$abstract.' ('.$e::class.': '.$e->getMessage().')';

                continue;
            }

            if ($r['concrete'] !== null && ! $first instanceof $r['concrete']) {
                $wrongConcrete[] = $r['source'].' -> '.$abstract.' is '.$first::class.' not '.$r['concrete'];
            }

            if ($r['kind'] === 'singleton' && $first !== $second) {
                $notShared[] = $r['source'].' -> '.$abstract;
            }
        }

        $this->assertSame([], $missing, "Registrations that name a class that does not exist:\n  ".implode("\n  ", $missing));
        $this->assertSame([], $unresolved, "Registrations that do not resolve through the container:\n  ".implode("\n  ", $unresolved));
        $this->assertSame([], $wrongConcrete, "Registrations that resolve to the wrong concrete:\n  ".implode("\n  ", $wrongConcrete));
        $this->assertSame([], $notShared, "Singletons that are not shared:\n  ".implode("\n  ", $notShared));
    }

    public function test_the_parser_reads_an_abstract_and_a_concrete_out_of_one_line(): void
    {
        // Non-vacuity: if this parser silently returned nothing, the assertions above would pass on an
        // empty list and the floor would be the only thing standing between us and nothing at all.
        $source = <<<'PHP'
        <?php
        use App\Models\User;
        use App\Modules\Ride\Contracts\RideRepositoryInterface;
        use App\Modules\Ride\Repositories\RideRepository;
        final class Probe
        {
            public function register(): void
            {
                $this->app->singleton(User::class);
                $this->app->bind(RideRepositoryInterface::class, RideRepository::class);
            }
        }
        PHP;

        $registrations = $this->registrationsIn($source, 'probe');

        $this->assertCount(2, $registrations);
        $this->assertSame(['singleton', 'App\Models\User', null], [$registrations[0]['kind'], $registrations[0]['abstract'], $registrations[0]['concrete']]);
        $this->assertSame(
            ['bind', 'App\Modules\Ride\Contracts\RideRepositoryInterface', 'App\Modules\Ride\Repositories\RideRepository'],
            [$registrations[1]['kind'], $registrations[1]['abstract'], $registrations[1]['concrete']]
        );
    }

    /**
     * @return array<int, array{kind: string, abstract: string, concrete: ?string, source: string}>
     */
    private function registrationsIn(string $source, string $label): array
    {
        $imports = [];
        if (preg_match_all('/^\s*use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+(\w+))?\s*;/m', $source, $uses, PREG_SET_ORDER)) {
            foreach ($uses as $use) {
                $fqcn = ltrim($use[1], '\\');
                $alias = ($use[2] ?? '') !== '' ? $use[2] : substr($fqcn, strrpos($fqcn, '\\') + 1);
                $imports[$alias] = $fqcn;
            }
        }

        $resolve = function (string $name) use ($imports): string {
            $name = ltrim($name, '\\');
            if (str_contains($name, '\\')) {
                return $name;
            }

            return $imports[$name] ?? $name;
        };

        $registrations = [];
        if (preg_match_all(
            '/->(singleton|bind|scoped|instance|alias)\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class\s*(?:,\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class)?/',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $registrations[] = [
                    'kind' => $m[1],
                    'abstract' => $resolve($m[2]),
                    'concrete' => ($m[3] ?? '') !== '' ? $resolve($m[3]) : null,
                    'source' => $label,
                ];
            }
        }

        return $registrations;
    }
}
