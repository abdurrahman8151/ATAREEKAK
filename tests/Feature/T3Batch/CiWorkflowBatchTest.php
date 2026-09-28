<?php

namespace Tests\Feature\T3Batch;

use Tests\TestCase;

/**
 * T3-11 — CI signal must be real:
 *   the test step must fail the build (no `|| true`),
 *   the Sonar action must be pinned to a commit SHA (not @master),
 *   no plaintext JWT secret may be written into the generated CI .env.
 *
 * Asserted against the actual workflow YAML text — it is a file contract, not
 * runtime behaviour. YAML parsing via yaml_parse (ext-yaml) is optional; the
 * regexes work on raw text either way.
 */
class CiWorkflowBatchTest extends TestCase
{
    private function sonar(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/sonar.yml'));
    }

    public function test_the_test_step_no_longer_swallows_failures(): void
    {
        $this->assertStringNotContainsString(
            'php artisan test --coverage-clover=coverage.xml || true',
            $this->sonar(),
            '`|| true` made every CI run green regardless of test failures'
        );

        $this->assertMatchesRegularExpression(
            '/run:\s*php artisan test --coverage-clover=coverage\.xml\s*\n/',
            $this->sonar(),
            'the test step must exist and fail the build on non-zero exit'
        );
    }

    public function test_the_sonar_action_is_pinned_to_a_commit_sha(): void
    {
        $src = $this->sonar();

        $this->assertStringNotContainsString(
            'SonarSource/sonarqube-scan-action@master',
            $src,
            '@master is a moving ref — a supply-chain risk'
        );

        $this->assertMatchesRegularExpression(
            '/SonarSource\/sonarqube-scan-action@[0-9a-f]{40}\s*#/',
            $src,
            'the action must be pinned to a full commit SHA with a version comment'
        );
    }

    public function test_no_plaintext_jwt_secret_is_written_into_ci_env(): void
    {
        $src = $this->sonar();

        $this->assertStringNotContainsString(
            'JWT_SECRET=test_secret_key_for_testing_only_32chars',
            $src,
            'a committed literal secret must not reappear'
        );

        $this->assertMatchesRegularExpression(
            '/JWT_SECRET=\$\(openssl rand/',
            $src,
            'the CI secret must be generated per run'
        );
    }

    public function test_the_workflow_yaml_still_parses(): void
    {
        $data = \Symfony\Component\Yaml\Yaml::parseFile(base_path('.github/workflows/sonar.yml'));

        $this->assertIsArray($data);
        $this->assertSame('SonarQube Analysis', $data['name'] ?? null);
        $steps = $data['jobs']['sonarcloud']['steps'] ?? [];
        $this->assertNotEmpty($steps);
    }
}
