<?php

namespace Tests\Feature\AppFuture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * STAGE 1.5 ITEM 4 - the module dependency graph, pinned as data.
 *
 * `ModuleBoundaryTest` says what MAY cross a boundary (Contracts, never implementation). This file says
 * what DOES cross it, so that the graph cannot grow or close a new loop by accident. The measured graph
 * on 2026-11-03, counting only code (comments and docblocks are stripped first, so history notes about
 * old namespaces cannot register as edges), is 14 directed edges and every one of them is a Contracts
 * edge:
 *
 *   from \ to        Comm   Money   Ride   Identity   Console
 *   Communication      -       -      -        -          2
 *   Money              1       1      -        -          -
 *   Ride               1       5      1        1          -
 *   Identity           1       1      2        -          1
 *   Console            3       3      2        6          -
 *
 * Four loops close in it. One was cut in this item (below). The other four are declared, each with the
 * reason it could not be cut without moving an endpoint or contradicting the stage 1 ownership map:
 *
 *   Communication <-> Console
 *       Communication's `ContactController` (`POST /api/contact`) puts a passenger into the support
 *       chat, so it needs Console's `SupportAgentRoutingInterface` (which agent is least loaded) and
 *       `EmployeeManagementInterface` (that agent's shadow user) to build its RESPONSE. An event
 *       cannot return the agent, and a kernel listener would be a sixth kernel file naming module
 *       Contracts - the ceiling is 5 and it is shrink-only. Rejected alternative: move the controller
 *       into Console, which would hand a passenger-facing endpoint to the back-office module; or move
 *       the two port declarations into Communication, which would launder Console's ownership of
 *       employees and support routing.
 *
 *   Console <-> Identity
 *       Console reaches Identity to authenticate staff (`EmployeeAuthInterface`, `StaffJwtInterface`);
 *       Identity reaches Console for `EmployeeRepositoryInterface` because batch 233's context map puts
 *       the staff AUTHENTICATION path in Identity while employee RECORDS stay in Console. Cutting it
 *       means moving either the port (Identity would then own employee records) or the service
 *       (Console would then own staff auth); both contradict the map.
 *
 *   Identity <-> Money
 *       `WalletController` (Money) verifies a withdrawal with Identity's `EmailOtpServiceInterface`;
 *       `PassengerProfileController` (Identity) tops a wallet up through Money's
 *       `PassengerWalletChargerInterface`. Both directions need a return value, so no event can carry
 *       them, and cutting either means moving an HTTP endpoint between modules - endpoint ownership,
 *       not a port move.
 *
 *   Identity <-> Ride
 *       `RideValidationService` (Ride) checks a driver's documents through Identity's
 *       `DocumentVerificationInterface`; `ProfileController` (Identity) renders ride stats and score
 *       through Ride's `ScoreServiceInterface` and `UserRideStatsInterface`. Same shape as
 *       Identity <-> Money.
 *
 * THE CUT: Communication -> Identity was one edge in that matrix, created by a single line - the
 * `OtpMailInterface` binding in `CommunicationServiceProvider`, placed there because mail delivery is
 * Communication's transport. A port is bound by the module that publishes it, so the binding moved to
 * `IdentityServiceProvider`, which names the kernel `App\Mail\OtpMailer` (allowed: a module may name the
 * kernel). Same binding, same `bind()` lifetime, and the edge - and with it the
 * Communication <-> Identity cycle - is gone.
 *
 * Shrink-only. The declared numbers are a CEILING, not a target: a new edge, a new port on an existing
 * edge, or a new cycle fails this test and must be argued for in the commit that adds it.
 */
class ModuleGraphTest extends TestCase
{
    private const MODULES = ['Communication', 'Money', 'Ride', 'Identity', 'Console'];

    /** Directed Contracts edges, with the number of distinct ports each one uses. Shrink-only. */
    private const EDGES = [
        'Communication -> Console' => 2,
        'Console -> Communication' => 3,
        'Console -> Identity' => 6,
        'Console -> Money' => 3,
        'Console -> Ride' => 2,
        'Identity -> Communication' => 1,
        'Identity -> Console' => 1,
        'Identity -> Money' => 1,
        'Identity -> Ride' => 2,
        'Money -> Communication' => 1,
        'Money -> Identity' => 1,
        'Ride -> Communication' => 1,
        'Ride -> Identity' => 1,
        'Ride -> Money' => 5,
    ];

    /** Loops that are declared uncut, with the reason. Adding one here is a design decision. */
    private const UNCUT_CYCLES = [
        'Communication <-> Console' => 'a passenger-facing contact endpoint must reach the back office to build its response; an event cannot return the agent and a kernel listener would breach the 5-file Contracts ceiling',
        'Console <-> Identity' => 'staff authentication lives in Identity and employee records in Console (batch 233 context map); cutting it would move one or the other',
        'Identity <-> Money' => 'OTP-verified withdrawal versus wallet top-up: both need a return value and cutting either moves an HTTP endpoint between modules',
        'Identity <-> Ride' => 'driver-document checks versus ride stats and score: same shape as Identity <-> Money',
    ];

    /**
     * The longer loops, in the rotation the enumerator produces. Every one of them is a CONSEQUENCE of
     * the mutual pairs above: listed here so the whole cycle surface is declared data rather than a
     * number, and asserted below as reachable only through a declared pair. If an edge is added, the
     * edge test fails first and this list tells you exactly which new loop it closed.
     */
    private const DERIVED_CYCLES = [
        'Communication -> Console -> Identity',
        'Communication -> Console -> Identity -> Money',
        'Communication -> Console -> Identity -> Ride',
        'Communication -> Console -> Identity -> Ride -> Money',
        'Communication -> Console -> Money',
        'Communication -> Console -> Money -> Identity',
        'Communication -> Console -> Money -> Identity -> Ride',
        'Communication -> Console -> Ride',
        'Communication -> Console -> Ride -> Identity',
        'Communication -> Console -> Ride -> Identity -> Money',
        'Communication -> Console -> Ride -> Money',
        'Communication -> Console -> Ride -> Money -> Identity',
        'Console -> Money -> Identity',
        'Console -> Ride -> Identity',
        'Console -> Ride -> Money -> Identity',
        'Identity -> Ride -> Money',
    ];

    /** Floors: 208 module PHP files measured 2026-11-03. */
    private const MIN_SCANNED_FILES = 200;

    /** The graph itself may only lose edges; 14 measured after the OtpMailer cut. */
    private const MIN_EDGES = 14;

    /** Files that carry at least one cross-module Contracts reference: ~43 measured. */
    private const MIN_CROSS_MODULE_FILES = 30;

    public function test_the_module_graph_is_exactly_the_declared_edge_set(): void
    {
        $scan = self::scan();

        $measured = [];
        foreach ($scan['edges'] as $key => $ports) {
            $measured[$key] = count($ports);
        }
        ksort($measured);

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            $scan['scanned'],
            'The module graph scan read only '.$scan['scanned'].' PHP files under app/Modules.'
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_EDGES,
            count($measured),
            'The module graph has only '.count($measured).' edges; either a module stopped naming its ports or the scan broke.'
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_CROSS_MODULE_FILES,
            $scan['crossModuleFiles'],
            'Only '.$scan['crossModuleFiles'].' module files reference another module; the graph is thinner than the tree it describes.'
        );

        $declared = self::EDGES;
        ksort($declared);

        $this->assertSame(
            $declared,
            $measured,
            "The module graph changed.\n"
            .'Measured: '.json_encode($measured)."\n"
            .'Declared: '.json_encode($declared)."\n"
            .'Edges and their port counts are shrink-only: a new edge (or a new port on an old edge) is an '."\n"
            .'architecture decision and needs its own line here, with the reason, in the commit that adds it.'
        );
    }

    public function test_every_cross_module_reference_is_a_contracts_reference(): void
    {
        $scan = self::scan();

        $offenders = [];
        foreach ($scan['implementations'] as $key => $classes) {
            foreach ($classes as $class => $files) {
                foreach (array_keys($files) as $file) {
                    $offenders[] = $file.' names '.$class.' ('.$key.')';
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_CROSS_MODULE_FILES,
            $scan['crossModuleFiles'],
            'The Contracts check saw only '.$scan['crossModuleFiles'].' cross-module files.'
        );

        $this->assertSame(
            [],
            $offenders,
            "A module names another module's implementation, not its Contracts:\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_the_only_cycles_are_the_declared_uncut_ones(): void
    {
        $scan = self::scan();

        $cycles = self::cycles($scan['edges']);
        $mutual = array_values(array_filter($cycles, static fn (string $c): bool => str_contains($c, ' <-> ')));
        $longer = array_values(array_filter($cycles, static fn (string $c): bool => ! str_contains($c, ' <-> ')));
        $declared = array_keys(self::UNCUT_CYCLES);
        sort($declared);

        $this->assertGreaterThanOrEqual(
            self::MIN_EDGES,
            count($scan['edges']),
            'The cycle check ran on only '.count($scan['edges']).' edges.'
        );

        $this->assertSame(
            $declared,
            $mutual,
            "The declared mutual pairs and the measured ones differ.\n"
            .'Measured: '.json_encode($mutual)."\n"
            .'Declared: '.json_encode($declared)."\n"
            .'A new mutual pair means two modules now need each other; cut it with a port move or an event, or '
            .'declare it in UNCUT_CYCLES with the reason it cannot be cut.'
        );

        $derived = self::DERIVED_CYCLES;
        sort($derived);

        $this->assertSame(
            $derived,
            $longer,
            "The longer loops changed.\n"
            .'Measured: '.json_encode($longer)."\n"
            .'Declared: '.json_encode($derived)
        );

        // The declaration is only honest if the longer loops really are consequences of the mutual
        // pairs: every one of them must contain at least one declared pair as adjacent modules.
        $unexplained = [];
        foreach ($longer as $cycle) {
            $nodes = explode(' -> ', $cycle);
            $closed = false;
            for ($i = 0, $n = count($nodes); $i < $n; $i++) {
                $a = $nodes[$i];
                $b = $nodes[($i + 1) % $n];
                if (isset(self::UNCUT_CYCLES[$a.' <-> '.$b]) || isset(self::UNCUT_CYCLES[$b.' <-> '.$a])) {
                    $closed = true;

                    break;
                }
            }
            if (! $closed) {
                $unexplained[] = $cycle;
            }
        }

        $this->assertSame(
            [],
            $unexplained,
            'These loops are not explained by any declared mutual pair, so they are NEW design decisions, not '
            ."consequences:\n  ".implode("\n  ", $unexplained)
        );
    }

    /**
     * Every simple cycle of the module graph, as `A <-> B` for a 2-cycle and `A -> B -> C` (rotated to
     * start at the alphabetically first module) otherwise. Five nodes, so enumerating all simple cycles
     * by DFS is cheap and exact - cheaper to reason about than a condensation check.
     *
     * @param  array<string, array<string, array<string, bool>>>  $edges
     * @return list<string>
     */
    private static function cycles(array $edges): array
    {
        $adjacency = [];
        foreach (array_keys($edges) as $key) {
            [$from, $to] = explode(' -> ', $key);
            $adjacency[$from][] = $to;
        }

        $found = [];
        foreach (self::MODULES as $start) {
            self::walkCycles($adjacency, $start, $start, [], $found);
        }

        $labels = [];
        foreach ($found as $path) {
            // Rotate so a cycle is named from its smallest module, then drop the repeated head.
            $nodes = $path;
            $min = min($nodes);
            while ($nodes[0] !== $min) {
                $nodes[] = array_shift($nodes);
            }
            $labels[] = count($nodes) === 2
                ? $nodes[0].' <-> '.$nodes[1]
                : implode(' -> ', $nodes);
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  list<string>  $path  the nodes visited so far, without the start repeated
     * @param  list<list<string>>  $found
     */
    private static function walkCycles(array $adjacency, string $start, string $node, array $path, array &$found): void
    {
        $path[] = $node;

        foreach ($adjacency[$node] ?? [] as $next) {
            if ($next === $start) {
                $found[] = $path;

                continue;
            }
            if (in_array($next, $path, true)) {
                continue;
            }
            self::walkCycles($adjacency, $start, $next, $path, $found);
        }
    }

    /**
     * Walk `app/Modules/<Module>` and record, per module pair, which of the other module's Contracts are
     * named by which files. Comments and docblocks are stripped before matching: this repository keeps
     * its history in docblocks, and a note about an old namespace must not read as a live edge.
     *
     * @return array{
     *     scanned: int,
     *     crossModuleFiles: int,
     *     edges: array<string, array<string, array<string, bool>>>,
     *     implementations: array<string, array<string, array<string, bool>>>
     * }
     */
    private static function scan(): array
    {
        $root = dirname(__DIR__, 3).'/app/Modules';
        $prefix = 'App'.'\\'.'Modules'.'\\';

        $scanned = 0;
        $crossModuleFiles = [];
        $edges = [];
        $implementations = [];

        foreach (self::MODULES as $module) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$module, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $scanned++;
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($root, 2)) + 1));
                $source = self::codeOnly((string) file_get_contents($file->getPathname()));

                preg_match_all(
                    '#'.preg_quote($prefix, '#').'([A-Za-z]+)\\\\([A-Za-z\\\\]+)#',
                    $source,
                    $refs,
                    PREG_SET_ORDER
                );

                foreach ($refs as $ref) {
                    $to = $ref[1];
                    if ($to === $module || ! in_array($to, self::MODULES, true)) {
                        continue;
                    }

                    $tail = $ref[2];
                    $key = $module.' -> '.$to;
                    $crossModuleFiles[$relative] = true;

                    if (str_starts_with($tail, 'Contracts\\')) {
                        $parts = explode('\\', $tail);
                        $edges[$key][$parts[1]][$relative] = true;
                    } else {
                        $parts = explode('\\', $tail);
                        $implementations[$key][$parts[0]][$relative] = true;
                    }
                }
            }
        }

        ksort($edges);
        ksort($implementations);

        return [
            'scanned' => $scanned,
            'crossModuleFiles' => count($crossModuleFiles),
            'edges' => $edges,
            'implementations' => $implementations,
        ];
    }

    /** Strip comments and docblocks, so only live code can register an edge. */
    private static function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
