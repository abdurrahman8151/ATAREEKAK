<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-08 / T3-10, owner decision D9 = C (2026-10-04): target-agnostic deploy hygiene.
 *
 * Two defects, both found by reading `.github/workflows/deploy-to-vps.yml` rather than trusting the
 * row's label:
 *
 * 1. The workflow REWROTE `origin` to an authenticated HTTPS URL, which persists the deploy token
 *    into the clone's `.git/config` ON THE VPS. It outlives the job and is readable by anything that
 *    can read that file. This is the real "token in the remote URL" - not a local git remote.
 *
 * 2. It deployed `origin/samer`, a personal feature branch, regardless of which ref triggered the
 *    run. What shipped was therefore not necessarily what was reviewed.
 *
 * These are CI files, so they are not covered by any behavioural test - which is exactly why they
 * need a structural one. Deploy work is also currently paused pending the owner's target choice
 * (D9 = C), so a regression here would sit unnoticed for a long time.
 */
class DeployWorkflowHygieneTest extends TestCase
{
    private function workflow(): string
    {
        $path = base_path('.github/workflows/deploy-to-vps.yml');
        $this->assertFileExists($path, 'the deploy workflow must exist - this test is not optional coverage');

        return (string) file_get_contents($path);
    }

    /**
     * The token must never be written into a config file on the deploy host.
     *
     * @test
     */
    public function it_never_persists_a_credential_into_the_remote_url(): void
    {
        $this->assertStringNotContainsString(
            'git remote set-url',
            $this->workflow(),
            'git remote set-url origin with a token WRITES that token into .git/config on the VPS, '
            .'where it persists after the job ends'
        );
    }

    /**
     * Auth must be command-scoped instead. `git -c` takes the header for one command and writes
     * nothing, which is the whole difference between a token that lives for 10 seconds and one that
     * lives on disk.
     *
     * @test
     */
    public function it_authenticates_with_a_command_scoped_header_instead(): void
    {
        $src = $this->workflow();

        $this->assertStringContainsString('git -c http.extraheader=', $src);
        $this->assertStringNotContainsString(
            'https://x-access-token:${GITHUB_TOKEN}@',
            $src,
            'an interpolated token must not appear in any committed URL'
        );
    }

    /**
     * It must deploy the ref that triggered the run, never a hard-coded branch.
     *
     * @test
     */
    public function it_deploys_the_triggering_ref_not_a_fixed_branch(): void
    {
        $src = $this->workflow();

        $this->assertStringContainsString(
            '${{ github.ref_name }}',
            $src,
            'the deployed ref must come from the trigger, so what ships is what was reviewed'
        );
        $this->assertStringNotContainsString(
            'reset --hard origin/samer',
            $src,
            'deploying a personal feature branch is the original defect'
        );
    }

    /**
     * No `reset --hard origin/<literal>` at all - the shape of the bug, not just this instance.
     *
     * @test
     */
    public function no_hard_reset_targets_a_literal_remote_branch(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/git\s+reset\s+--hard\s+origin\/[A-Za-z0-9._-]+/',
            $this->workflow(),
            'resetting to a literal remote branch re-couples the deploy to whatever that branch is'
        );
    }

    /**
     * The workflow self-checks that no credential landed in .git/config. If this assertion is
     * removed the test above stops proving anything on a real host.
     *
     * @test
     */
    public function it_asserts_after_the_fetch_that_no_credential_reached_the_config(): void
    {
        $src = $this->workflow();

        $fetch = strpos($src, 'git -c http.extraheader=');
        $guard = strpos($src, 'DEPLOY_PATH/.git/config');

        $this->assertIsInt($fetch);
        $this->assertIsInt($guard);
        $this->assertTrue(
            $guard > $fetch,
            'the .git/config check must come AFTER the authenticated fetch, or it checks nothing'
        );
    }
}
