<?php

namespace Tests\Feature\T4Batch;

use App\Http\Controllers\API\AdminDashboardController;
use App\Models\UserRating;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T4 batch — dead artifacts removed, and the no-op surface that reported
 * success while doing nothing.
 *
 *   T4-6  Dead/duplicate root artifacts: a blind str_replace script bound to
 *         /var/www/html, a root-level UserRating.php that byte-duplicated
 *         app/Models/UserRating.php under the same App\Models namespace, an
 *         unreferenced cacert.pem, an unreferenced firebase.js placeholder,
 *         a test file filed at tests/Unit/Tests/Unit/... (doubled path, already
 *         carrying the correct Tests\Unit\Services namespace), and two k6
 *         filenames with a stray leading/trailing space.
 *
 *   T4-7  ProcessBulkNotifications had an empty handle() and no dispatch site
 *         anywhere; its only test asserted the stub was a stub. The
 *         uploadAdminPhoto() endpoint returned "Photo uploaded" without
 *         uploading anything (no photo column exists), so its route and
 *         method are gone — a 404 is the honest response.
 *
 * NOTE: the root UserRating.php was NOT merely a duplicate name: it declared
 * `namespace App\Models` while living outside app/, and composer's PSR-4 map
 * sends App\Models\ to app/ — so the file was only ever reachable by a
 * direct include, and if it ever had been it would have shadowed the real
 * model with a second copy of the class.
 */
class DeadArtifactBatchTest extends TestCase
{
    /** @return array<string,string> repo-relative path => why it was dead */
    private function removedArtifacts(): array
    {
        return [
            'fix.php' => 'blind str_replace against /var/www/html production paths',
            'UserRating.php' => 'root-level duplicate of app/Models/UserRating.php (same App\Models namespace)',
            'cacert.pem' => 'unreferenced CA bundle',
            'resources/js/firebase.js' => 'unreferenced placeholder using Laravel-Mix process.env syntax in a Vite project',
            'app/Jobs/ProcessBulkNotifications.php' => 'empty handle(), zero dispatch sites (T4-7)',
            'tests/Unit/Jobs/ProcessBulkNotificationsTest.php' => 'asserted the stub was a stub (T4-7)',
        ];
    }

    public function test_dead_root_and_job_artifacts_are_deleted(): void
    {
        foreach ($this->removedArtifacts() as $rel => $why) {
            $this->assertFileDoesNotExist(base_path($rel), "$rel must stay deleted ($why)");
        }
    }

    public function test_the_root_userrating_duplicate_is_gone(): void
    {
        // The real model is the one composer can autoload (PSR-4 App\Models\ -> app/).
        $this->assertFileExists(base_path('app/Models/UserRating.php'));
        $this->assertFileDoesNotExist(base_path('UserRating.php'));
        $this->assertTrue(class_exists(UserRating::class), 'the canonical model must still autoload');
    }

    public function test_the_misfiled_geocoding_test_now_sits_at_its_canonical_path(): void
    {
        $moved = base_path('tests/Unit/Services/GeocodingServiceTest.php');
        $this->assertFileExists($moved, 'the doubled tests/Unit/Tests/Unit path is gone; the class now lives at its real PSR-4 location');
        $this->assertFileDoesNotExist(base_path('tests/Unit/Tests/Unit/Services/GeocodingServiceTest.php'));

        $src = (string) file_get_contents($moved);
        $this->assertStringContainsString('namespace Tests\Unit\Services;', $src, 'the file already carried the correct namespace; only its path was wrong');
    }

    public function test_k6_scripts_have_no_stray_leading_or_trailing_spaces(): void
    {
        foreach (glob(base_path('k6-load/*.js')) as $path) {
            $name = basename($path);
            $this->assertSame(
                $name,
                trim($name),
                "k6-load/$name has stray whitespace in its filename"
            );
        }
    }

    public function test_config_app_key_is_defined_once_per_array(): void
    {
        // The audit claimed config/app.php:99 "defines 'key' twice". Checked: that
        // line lives inside services.openroute — a different array — so there is
        // no duplicate. This test pins the real structure so the claim cannot be
        // "re-fixed" by deleting a legitimate key.
        $config = require base_path('config/app.php');

        $this->assertSame(env('APP_KEY'), $config['key'] ?? null);
        $this->assertArrayHasKey('openroute', $config['services']);
        $this->assertArrayHasKey('key', $config['services']['openroute'], 'the openroute service key is unrelated to app.key and must remain');

        $raw = (string) file_get_contents(base_path('config/app.php'));
        // app.key must be the APP_KEY line, not the openroute one: if the two
        // arrays were ever merged or the key duplicated, app.key would resolve
        // to the wrong value.
        $this->assertMatchesRegularExpression(
            "/^\s*'key'\s*=>\s*env\('APP_KEY'\)/m",
            $raw,
            "app.key must read env('APP_KEY')"
        );
        $this->assertMatchesRegularExpression(
            "/'openroute'\s*=>\s*\[\s*'key'\s*=>\s*env\('OPENROUTE_API_KEY'\)/s",
            $raw,
            'the openroute key is a distinct entry inside services.openroute, not a duplicate of app.key'
        );
    }

    // ── T4-7 ────────────────────────────────────────────────────────────────
    public function test_the_no_op_photo_endpoint_is_gone(): void
    {
        $uris = array_map(
            static fn ($r) => $r->uri(),
            Route::getRoutes()->getRoutes()
        );

        $this->assertNotContains(
            'api/admin/photo',
            $uris,
            'the success-reporting no-op upload route must stay removed (T4-7)'
        );
    }

    public function test_the_no_op_photo_method_is_gone(): void
    {
        $this->assertFalse(
            method_exists(AdminDashboardController::class, 'uploadAdminPhoto'),
            'a method that returns "Photo uploaded" without uploading must not come back (T4-7)'
        );
    }
}
