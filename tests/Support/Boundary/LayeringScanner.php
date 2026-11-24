<?php

namespace Tests\Support\Boundary;

/**
 * Stage 5 B0: the layering classifier.
 *
 * The graph ratchet (`ModuleGraphTest`) pins WHICH edges exist. This class answers the layering question the owner
 * approved in stage 5: for a given bottom-to-top order of the modules, an edge `A -> B` is DOWNWARD when A sits
 * above B (A may depend on B) and UPWARD when A sits below B (the lower module is reaching up).
 *
 * It is a pure function on purpose - the test can feed it a synthetic graph to prove it still detects an upward
 * edge, which is the probe the phase requires. A scanner that stopped seeing anything would make an empty pinned
 * list look like success.
 */
final class LayeringScanner
{
    /**
     * Classify edges against a layer order.
     *
     * @param  array<string, int>  $edges  edge => port references, keys in the form "A -> B"
     * @param  array<string, int>  $layers  module => index, 0 = bottom
     * @return array<string, int> the UPWARD edges, with their reference counts
     */
    public static function upward(array $edges, array $layers): array
    {
        $upward = [];

        foreach ($edges as $edge => $references) {
            $parts = array_map('trim', explode('->', (string) $edge));
            if (count($parts) !== 2) {
                continue;
            }

            [$from, $to] = $parts;

            if (! isset($layers[$from], $layers[$to])) {
                continue;
            }

            if ($layers[$from] < $layers[$to]) {
                $upward[$edge] = (int) $references;
            }
        }

        return $upward;
    }

    /**
     * Edges that reference a module the order does not place - a declaration bug, not a layering finding.
     *
     * @param  array<string, int>  $edges
     * @param  array<string, int>  $layers
     * @return array<int, string>
     */
    public static function unplaced(array $edges, array $layers): array
    {
        $missing = [];

        foreach (array_keys($edges) as $edge) {
            $parts = array_map('trim', explode('->', (string) $edge));
            foreach ($parts as $module) {
                if (! isset($layers[$module])) {
                    $missing[] = $module;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
