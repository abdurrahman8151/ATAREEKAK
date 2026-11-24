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
 *   Communication      -       -      -        -          -
 *   Money              1       1      1        4          -
 *   Ride               1       5      1        3          -
 *   Identity           2       4      3        -          3
 *   Console            5       7     12        9          -
 *
 * Four loops close in it. One was cut in this item (below). The other four are declared, each with the
 * reason it could not be cut without moving an endpoint or contradicting the stage 1 ownership map:
 *
 *   Console -> Communication (4 ports as of stage 2 C1; was 3)
 *       Communication's `ContactController` (`POST /api/contact`) puts a passenger into the support
 *       chat, so it needs Console's `SupportAgentRoutingInterface` (which agent is least loaded) and
 *       `EmployeeManagementInterface` (that agent's shadow user) to build its RESPONSE. An event
 *       cannot return the agent, and a kernel listener would be a sixth kernel file naming module
 *       Contracts - the ceiling is 5 and it is shrink-only. Rejected alternative: move the controller
 *       into Console, which would hand a passenger-facing endpoint to the back-office module; or move
 *       the two port declarations into Communication, which would launder Console's ownership of
 *       employees and support routing.
 *       Stage 2 C1 added the fourth port, `MessageBroadcaster`: the real-time `MessageSent` event
 *       names a Communication model, so it moved into Communication with its owner, and Console's
 *       `StaffChatController` can no longer construct it. Same event, same payload, one import fewer.
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
 *       Stage 2 C1 added the second Identity -> Money port, `WalletChargeHistory`: the admin
 *       passenger profile's wallet-charge rows used to be built in Identity from Money's
 *       `WalletTransaction` model, which stops being legal once that model moves into the Money
 *       namespace. The rows are produced by Money now, in the same 12-key shape, and the frozen
 *       response snapshots prove the JSON did not change.
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
        // Stage 4 D-1: `Communication -> Console` is GONE. The contact endpoint asked Console two questions
        // through two ports; Communication now declares ONE contract (`SupportDeskDirectory`) and Console
        // implements and binds it, so the cycle and the edge both went - and the reference count with them.
        'Console -> Communication' => 5,
        // Stage 4 D-1: the fifth port is the inverted `SupportDeskDirectory` (see where `Communication ->
        // Console` used to be). One method in, two Console ports out: 15 edges -> 14, 65 references -> 64.
        'Console -> Identity' => 10,
        // Stage 2 C3: the tenth port is `UserDirectory`. The staff complaint lists used to eager-load the
        // complainant's `users` row for its name and email; the same ONE query now arrives from Identity's
        // directory, read by property, with the DTO class never named here. A new port on an edge that already
        // existed (staff auth, employees, ratings, photos, the profile directory), not a new edge.
        // Stage 2 C2: the ninth port is `ProfileCommentDirectory`. The staff review-moderation screen
        // browsed and deleted Identity's `profile_comments` through the model; the page, its filters and
        // the delete now run in Identity and Console keeps the response shape. A new port on an edge
        // that already existed, not a new edge - and it is the last Console file that named
        // `ProfileComment`.
        // Stage 2 C2: the eighth port is `ProfileDirectory`. Console's admin screens read a driver's
        // profile row and the admin's profile photo, and `profiles` moves into Identity, so those reads
        // became one port method returning the stored path. A new port on an EDGE THAT ALREADY EXISTED
        // (staff auth, the employee record port, the rating reader and the photo read were already here),
        // not a new edge - and it removes seven Console files' worth of `App\Modules\Identity\Models\Profile` coupling
        // one file at a time, which is why it is a port and not a wider one-shot allowance.
        // Stage 2 C2: the admin charge and wallet-request paths record their transaction through
        // Money's WalletTransactionRecorder port instead of inserting the row themselves.
        // Stage 2 C2: the admin driver dashboard's earnings sum is Money's query, run by Money.
        // Stage 2 C2: the admin wallet-transaction page is Money's query, run by Money.
        // Stage 2 C2: the admin financial report's four ledger totals are Money's queries and Money's
        // ledger type spellings, so Console calls the aggregate reader.
        // Stage 2 C2: the admin report reads system wallets through Money's directory.
        // Stage 2 C2: the admin credit is Money's write path, called through its contract.
        'Console -> Money' => 7,
        // Stage 2 C2 (sec 372): the wallet-request approval workflow moved INTO Money, so two Console ports left
        // this edge (`LedgerServiceInterface` and `CashRideFeeServiceInterface` were only there because the
        // controller ran the money movement) and one joined it (`WalletRequests`, the desk that now owns both
        // the review reads and the approve/reject body). 9 -> 7 is a shrink: the edge lost more than it gained.
        // Stage 2 C2: the admin driver screens embed two ride aggregates in their own statement,
        // so Console asks Ride for the expressions instead of building them from the model; the same
        // screen's recent-rides page and contact number are a second, row-returning port, which the
        // driver DASHBOARD then reuses for its counters, recent rides and favourite destination.
        // Stage 2 C2: the admin report's fleet-wide trip counters are a third Ride contract; the
        // admin/staff trip screens' four queries are a fourth; the staff cancellation flow's trip writes
        // are a fifth (the workflow itself stays in Console); the report's three booking-based money
        // projections are a sixth; the recent-activity feed is a seventh; the staff bookings list is
        // an eighth; the staff booking-cancel path's lookup and write are a ninth.
        'Console -> Ride' => 12,
        // Stage 3 A1: the twelfth port is the inverted `ComplaintRecorder` (see the note where `Ride ->
        // Console` used to be). Stage 4 A3 moved the admin DRIVER LIST's statement into Identity (the owner
        // of `users`) but left this port where it was: Console still BUILDS Ride's latest-communication-number
        // expression and hands the fragment to Identity, so the owner of each table keeps owning its SQL and
        // no port reference moved.
        'Identity -> Communication' => 2,
        // Stage 2 C2: the second port is `ProfilePhotoDirectory`, and it is declared by the CONSUMER.
        // Communication's chat read path needs the senders' stored `profile_photo`; Identity owns
        // `profiles` but phase 1.5 deliberately cut `Communication -> Identity`, so re-declaring it from
        // Identity's own `ProfileDirectory` would re-create that edge AND the `Communication <-> Identity`
        // cycle this table does not declare. Instead Communication publishes the narrow port it needs and
        // Identity implements and binds it. The graph grows on the EXISTING `Identity -> Communication`
        // edge (the `NotificationPort` was already here), no new edge and no new cycle.
        // Stage 2 C2: staff-session invalidation writes Console's employees.token_version, so
        // Identity calls Console's EmployeeTokenVersion port instead of running the increment itself.
        'Identity -> Console' => 3,
        // Stage 2 C2: the third port is `ComplaintDirectory`. Identity's passenger-profile panels read a
        // user's complaints, and `complaints` is Console's table, so the page, the recent list and the
        // status counts run in Console and Identity keeps the Arabic labels and the response keys. A new
        // port on the existing `Identity -> Console` edge (staff auth and the employee-record port were
        // already here), not a new edge - and the `Console <-> Identity` pair is already declared uncut.
        'Identity -> Money' => 4,
        // Stage 2 C2: Identity's admin passenger profile reads the passenger's booking history
        // through Ride's PassengerBookingHistory port.
        'Identity -> Ride' => 3,
        // Stage 2 C2: escrow is stored on the booking row, so the guarded decrement runs in Ride -
        // the table's owner - and Money calls it. Ride already calls Money for payouts and charges, so
        // this closes a mutual pair rather than opening a one-way one.
        // Stage 2 C2: Money's cash-creation-fee tiers count the driver's cash rides through Ride.
        'Money -> Ride' => 1,
        // Stage 5 B1(c): the cash-ride COUNT is now passed in by Ride (which owns `rides`), so Money asks Ride
        // for nothing but the escrow ledger. That last port stays on purpose - the guarded decrement is inside
        // Money's atomic settlement, and moving it is parked under D2/D3.
        // Stage 5 B1(b): `BookingView` and `RideView` are NOT Ride's service contracts any more - they are the
        // shared read-only vocabulary of the kernel (`app/Contracts`), where a view of a row belongs. Money
        // still depends on Ride for the escrow ledger and the cash-ride count; what left the edge is the two
        // TYPES, which were never a service call (4 ports -> 2, references 64 -> 62).
        // Stage 4 A2 added a METHOD to `BookingEscrowLedger` (`recordMoneySnapshot`) rather than a fifth port:
        // Money computes a booking's money snapshot and Ride - which owns `bookings` - writes it, so the one
        // cross-module WRITE the stage 4 census found now lives with the owner. Same values, same single
        // UPDATE, same transaction, and no new port reference (the ceiling stays where it was).
        // Stage 3 A2: the two new ports are `BookingView` and `RideView` - the READ-ONLY contracts that let
        // Money type its 111 previously-untyped booking/ride parameters again (Stage 2 C2 had dropped the
        // hints because naming the moved models would have been an ownership violation). Same calls, same
        // queries, same JSON: the dependency is visible to the type system again, and the one cross-module
        // WRITE in these paths stays untyped and parked.
        'Money -> Communication' => 1,
        'Money -> Identity' => 4,
        // Stage 3 A4: the fourth port is `PasswordVerifier`. Wallet provisioning used to read the
        // `users.password` hash itself (and compare it with `Hash::check`) - the hash left Identity for the
        // length of one comparison. Money now asks for the boolean; same ONE read on the write PDO, same 401.
        // Stage 2 C3: the third port is `UserDirectory`. The admin wallet-charge history eager-loaded the
        // charging admin's `users` row for a name; Identity's directory now supplies it in the same ONE query,
        // read by property. A new port on the existing `Money -> Identity` edge, not a new edge.
        // Stage 2 C2: the second port is `ProfileDirectory`. The admin wallet-charge rows carry the
        // charging admin's photo, which Money used to read by eager-loading `User::profile`; the column now
        // arrives batched from Identity (the same ONE query for the page), and `profiles` stays Identity's.
        // A new port on the existing `Money -> Identity` edge, not a new edge.
        // Stage 3 A1: `Ride -> Console` is GONE. Its single port, `ComplaintRecorder`, is now declared by
        // its CONSUMER (Ride) and implemented here, so the no-show conflict still opens exactly the same
        // complaint through exactly the same service - one edge and the `Console <-> Ride` cycle fewer.
        'Ride -> Communication' => 1,
        'Ride -> Identity' => 3,
        // Stage 2 C2: the third port is `UserRatingReader`. The kernel `User::receivedRatings()` relation and
        // its `average_rating` accessor were removed (owner ruling 2026-11-17), so the ride and booking
        // resources take a batched rating map from Identity instead - one query per page, and the raw-model
        // endpoints get their nested `received_ratings` rows from the same port.
        // Stage 2 C2: the second port is `ProfileDirectory`. Ride's ride views render the driver's (and, on
        // the driver's passenger list, every passenger's) stored photo, which used to come from the
        // `driver.profile` / `user.profile` eager loads; the paths now arrive batched from Identity, and the
        // kernel resources take a plain array so they stay outside the kernel port ceiling. A new port on the
        // existing `Ride -> Identity` edge (`DocumentVerificationInterface` was already here).
        'Ride -> Money' => 5,
    ];

    /** Loops that are declared uncut, with the reason. Adding one here is a design decision. */
    private const UNCUT_CYCLES = [
        'Console <-> Identity' => 'staff authentication lives in Identity and employee records in Console (batch 233 context map); cutting it would move one or the other',
        'Identity <-> Money' => 'OTP-verified withdrawal versus wallet top-up: both need a return value and cutting either moves an HTTP endpoint between modules',
        'Money <-> Ride' => 'escrow is a COLUMN ON THE BOOKING ROW, so the module owning the table owns the guarded decrement; Money keeps the semantics and Ride keeps the storage. Cutting it means moving escrow off bookings, which the frozen schema forbids',
        'Identity <-> Ride' => 'driver-document checks versus ride stats and score: same shape as Identity <-> Money',
    ];

    /**
     * The longer loops, in the rotation the enumerator produces. Every one of them is a CONSEQUENCE of
     * the mutual pairs above: listed here so the whole cycle surface is declared data rather than a
     * number, and asserted below as reachable only through a declared pair. If an edge is added, the
     * edge test fails first and this list tells you exactly which new loop it closed.
     */
    private const DERIVED_CYCLES = [
        'Console -> Money -> Identity',
        'Console -> Money -> Ride -> Identity',
        'Console -> Ride -> Identity',
        'Console -> Ride -> Money -> Identity',
        'Identity -> Money -> Ride',
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
