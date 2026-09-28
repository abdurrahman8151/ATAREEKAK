<?php

namespace Tests\Feature\T3Batch;

use Tests\TestCase;

/**
 * T3-7 / T3-8 — the batch DELETED two files rather than repairing them,
 * because both were structurally broken by design:
 *
 *   app/Http/Middleware/VerifyOtpMiddleware.php (+ its unit test): the
 *   middleware passed every request through unconditionally, was referenced by
 *   no route, and its test asserted the stub did nothing (T3-7).
 *
 *   database/seeders/AdminUserSeeder.php: it read config('admin.system_admin
 *   .email/password'), keys that were removed from config — so it "succeeded"
 *   by creating a user with email NULL and a hash of an empty string. Admin
 *   auth now lives on Employee (SpecialAccountSeeder/SystemAdminSeeder). Its
 *   docblock justification ("UserObserver needs an admin user row") is stale:
 *   the observer uses rater_id => null now (T3-8).
 *
 * These tests pin the deletions: if any of the files reappears, this fails and
 * forces a re-read of why they went away.
 */
class DeletedArtifactsBatchTest extends TestCase
{
    public static function deletedFiles(): array
    {
        return [
            'app/Http/Middleware/VerifyOtpMiddleware.php',
            'tests/Unit/Middleware/VerifyOtpMiddlewareTest.php',
            'database/seeders/AdminUserSeeder.php',
            'config/rate_limiting.php',
        ];
    }

    public function test_dead_artifacts_stay_deleted(): void
    {
        foreach (self::deletedFiles() as $rel) {
            $this->assertFileDoesNotExist(base_path($rel), "$rel must stay deleted (T3-7/T3-8/T2-10)");
        }
    }

    public function test_no_route_or_alias_still_references_the_otp_middleware(): void
    {
        $needle = 'VerifyOtpMiddleware';

        foreach (['app', 'routes', 'bootstrap', 'config'] as $tree) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($tree), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $needle,
                    (string) file_get_contents($f->getPathname()),
                    $f->getPathname() . ' still references the deleted stub (T3-7)'
                );
            }
        }

        $this->assertTrue(true);
    }
}
