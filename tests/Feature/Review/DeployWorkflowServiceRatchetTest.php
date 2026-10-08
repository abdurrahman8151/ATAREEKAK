<?php

namespace Tests\Feature\Review;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `R2 sec 118`, RV-08: the deploy workflow names a docker compose service that does not exist.
 *
 * ## Why a dead path needs a ratchet
 *
 * `deploy-to-vps.yml` was disarmed on 2026-10-03 (decision 7 moved production to Render) and is now
 * `workflow_dispatch`-only. That is exactly why the defect survived: nothing ran the file, so nothing
 * noticed that `docker compose exec -T app` names a service `docker-compose.yml` never defines. The
 * step could not have succeeded if anyone had dispatched it.
 *
 * The owner ruled (2026-10-11) to KEEP the file rather than delete it - AGENTS.md reserves deleting code
 * for the owner, and "deprecated" is not the same instruction. That makes this ratchet the load-bearing
 * part of the fix: the two-line edit stops being correct the moment someone adds a sixth step, and a
 * deprecated file is precisely where nobody looks.
 *
 * This is deliberately NOT a test that the VPS path works. It cannot be - there is no VPS, production is
 * on Render, and the sandbox has no docker daemon. It asserts the one thing that is checkable and was
 * wrong: that every compose service a workflow targets is a service the compose file defines.
 */
class DeployWorkflowServiceRatchetTest extends TestCase
{
    /**
     * Repository root.
     *
     * A plain path rather than `base_path()`: this test reads two YAML files and makes no assertion
     * that needs the framework, so it deliberately stays a pure unit test - no app boot, no database.
     */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<int, string>
     */
    private function workflows(): array
    {
        $files = glob($this->root().'/.github/workflows/*.yml') ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * The service names `docker-compose.yml` actually defines.
     *
     * @return array<int, string>
     */
    private function definedServices(): array
    {
        $compose = Yaml::parseFile($this->root().'/docker-compose.yml');

        $this->assertIsArray($compose, 'docker-compose.yml did not parse as a mapping');
        $this->assertArrayHasKey('services', $compose, 'docker-compose.yml has no services key');

        $services = array_keys((array) $compose['services']);
        $this->assertNotEmpty($services, 'docker-compose.yml defines no services at all');

        return $services;
    }

    /**
     * THE ASSERTION. Every `docker compose exec|run` target in every workflow must be a real service.
     *
     * @test
     */
    public function every_compose_service_a_workflow_targets_is_actually_defined(): void
    {
        $defined = $this->definedServices();
        $referenced = [];

        foreach ($this->workflows() as $workflow) {
            $name = basename($workflow);
            $referenced[$name] = $this->targetsIn((string) file_get_contents($workflow));
        }

        $offenders = [];

        foreach ($referenced as $workflowName => $targets) {
            foreach (array_unique($targets) as $target) {
                if (! in_array($target, $defined, true)) {
                    $offenders[] = sprintf(
                        '%s execs/runs against "%s", which docker-compose.yml does not define (it has: %s)',
                        $workflowName,
                        $target,
                        implode(', ', $defined)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A workflow targets a docker compose service that does not exist. Such a step fails at\n"
            ."runtime and - on a deprecated workflow_dispatch-only path - fails unnoticed:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * The negative control. Without it this test could pass on a parse that simply found nothing,
     * which is the difference between "no bug" and "no test".
     *
     * @test
     */
    public function the_scanner_actually_finds_the_exec_targets_in_the_deploy_workflow(): void
    {
        $targets = $this->targetsIn((string) file_get_contents($this->root().'/.github/workflows/deploy-to-vps.yml'));

        $this->assertContains(
            'app1',
            $targets,
            'the scanner found nothing, so the ratchet above would pass for the wrong reason'
        );
        $this->assertNotContains(
            'app',
            $targets,
            'THE ORIGINAL DEFECT: `app` is not a service in docker-compose.yml'
        );
    }

    /**
     * The deploy workflow is `workflow_dispatch`-only by owner decision (7, 2026-10-03). If a push
     * trigger is ever added back, production behaviour changes silently and this row's premise - that
     * fixing this file cannot affect production - stops being true.
     *
     * @test
     */
    public function the_deprecated_workflow_still_cannot_fire_on_its_own(): void
    {
        $parsed = Yaml::parseFile($this->root().'/.github/workflows/deploy-to-vps.yml');

        // symfony/yaml parses the bare key `on` as the YAML 1.1 boolean `true`, so accept either key.
        $triggers = $parsed['on'] ?? $parsed[true] ?? null;

        $this->assertIsArray($triggers, 'could not read the trigger block');
        $this->assertArrayHasKey(
            'workflow_dispatch',
            $triggers,
            'the deploy workflow must remain manual-only (owner decision 7)'
        );

        foreach (['push', 'schedule'] as $automatic) {
            $this->assertArrayNotHasKey(
                $automatic,
                $triggers,
                "the {$automatic} trigger was re-added; this would redeploy production and invalidate the"
                .' premise that this row cannot affect production'
            );
        }
    }

    /**
     * Every compose target named in a workflow's inline shell script.
     *
     * Handles both the modern (`docker compose`) and legacy (`docker-compose`) spellings, and the
     * optional `-T` / `--no-TTY` flags, which is what the original call used.
     *
     * @return array<int, string>
     */
    private function targetsIn(string $source): array
    {
        $pattern = '/docker[\s-]compose\s+(?:exec|run)\s+((?:-{1,2}[A-Za-z-]+\s+)*)([A-Za-z0-9_.-]+)/';

        if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $targets = [];

        foreach ($matches as $match) {
            // Group 2 is the last positional token, i.e. the service; group 1 was the flags.
            $targets[] = $match[2];
        }

        return $targets;
    }
}
