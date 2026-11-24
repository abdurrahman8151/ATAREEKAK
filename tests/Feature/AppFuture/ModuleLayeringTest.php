<?php

namespace Tests\Feature\AppFuture;

use ReflectionClass;
use Tests\Support\Boundary\LayeringScanner;
use Tests\TestCase;

/**
 * Stage 5 B0: the LAYERING ratchet (owner decision D1 = APPROVED O1, 2026-11-24).
 *
 * `ModuleGraphTest` pins which edges exist; this test pins which DIRECTION is legal. The approved order is
 * bottom-to-top
 *
 *     Communication < Identity < Money < Ride < Console
 *
 * so a dependency may point DOWN the list and never up it. Every upward edge that exists today is PINNED in
 * `UPWARD_EDGES`, and the pinned map may only SHRINK: Phase B removes them one commit at a time, and a new upward
 * edge fails here even though the graph test would accept it with a line.
 *
 * The probe is a pure self-test of `LayeringScanner`: a synthetic upward edge must be classified, a synthetic
 * downward edge must not, and the real sweep must cover the whole module tree - otherwise an empty pinned list
 * would look like a layered codebase instead of a broken scanner.
 */
final class ModuleLayeringTest extends TestCase
{
    /**
     * The owner-approved order (D1 = APPROVED O1). Index 0 is the BOTTOM of the stack.
     *
     * @var array<string, int>
     */
    private const LAYERS = [
        'Communication' => 0,
        'Identity' => 1,
        'Money' => 2,
        'Ride' => 3,
        'Console' => 4,
    ];

    /**
     * The upward edges that exist on the approved order, with their port-reference counts. SHRINK-ONLY: remove a
     * line when Phase B cuts that reference; never add one. The two that must never be "cut" by flipping or by
     * moving a route are `Money -> Ride`'s remaining `BookingEscrowLedger` and the `Identity -> Console` pair -
     * they are parked under D2/D3 and stay here until the owner rules otherwise.
     *
     * @var array<string, int>
     */
    private const UPWARD_EDGES = [
        'Identity -> Console' => 3,
        'Identity -> Money' => 4,
        'Identity -> Ride' => 3,
        'Money -> Ride' => 4,
    ];

    /** The sweep must see the whole graph, or an empty pinned list would prove nothing. */
    private const MIN_EDGES = 14;

    /** @return array<string, int> the graph edges, from the ratchet that pins them */
    private function graphEdges(): array
    {
        $reflection = new ReflectionClass(ModuleGraphTest::class);

        return $reflection->getConstant('EDGES');
    }

    /** @test */
    public function every_upward_edge_is_the_pinned_one(): void
    {
        $edges = $this->graphEdges();

        $this->assertGreaterThanOrEqual(
            self::MIN_EDGES,
            count($edges),
            'The layering sweep saw fewer edges than the graph has - the scan is broken, not the codebase.'
        );

        $this->assertSame(
            [],
            LayeringScanner::unplaced($edges, self::LAYERS),
            'An edge references a module the approved order does not place. Add it to LAYERS before adding the edge.'
        );

        $upward = LayeringScanner::upward($edges, self::LAYERS);

        $this->assertSame(
            self::UPWARD_EDGES,
            $upward,
            "The upward edges changed.\nMeasured: ".json_encode($upward, JSON_PRETTY_PRINT)
            ."\nPinned:   ".json_encode(self::UPWARD_EDGES, JSON_PRETTY_PRINT).
            "\nThe approved order is bottom-to-top Communication < Identity < Money < Ride < Console.\n"
            .'A new upward edge is a design decision, not a line to add: pass the value in from the caller, or move '
            .'the orchestration to the module that owns the decision. Remove pinned lines only as Phase B cuts them.'
        );
    }

    /** @test */
    public function the_approved_order_places_every_module_and_nothing_else(): void
    {
        $onDisk = [];
        foreach (scandir(base_path('app/Modules')) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir(base_path('app/Modules/'.$entry))) {
                $onDisk[] = $entry;
            }
        }
        sort($onDisk);

        $placed = array_keys(self::LAYERS);
        sort($placed);

        $this->assertSame($onDisk, $placed, 'The approved layer order must list exactly the modules on disk.');
        $this->assertSame(
            range(0, count(self::LAYERS) - 1),
            array_values(self::LAYERS),
            'The layer indexes must be a contiguous 0..n-1 range, so a missing layer cannot hide a gap.'
        );
    }

    /** @test */
    public function the_classifier_detects_a_new_upward_edge_and_ignores_downward_ones(): void
    {
        $layers = self::LAYERS;

        // a synthetic graph: one legal downward edge and one genuinely new upward edge
        $synthetic = [
            'Console -> Identity' => 10,       // Console is top, Identity is below: legal
            'Communication -> Console' => 2,   // Communication is bottom, Console is top: a NEW upward edge
        ];

        $this->assertSame(
            ['Communication -> Console' => 2],
            LayeringScanner::upward($synthetic, $layers),
            'the classifier missed an upward edge - the ratchet would pass a broken layer order'
        );

        // a same-module edge and an edge whose target is not placed are not "upward" findings
        $this->assertSame([], LayeringScanner::upward(['Ride -> Ride' => 1, 'Money -> Kernel' => 3], $layers));
        $this->assertSame(['Kernel'], LayeringScanner::unplaced(['Money -> Kernel' => 3], $layers));
    }
}
