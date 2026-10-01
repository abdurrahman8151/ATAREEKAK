<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RV-14 — routes-integrity ratchet.
 *
 * R1 RV-14's evidence was found by grepping one endpoint by hand:
 *   `POST /api/rides` → `RideController@createRide`, and no such method exists, so
 *   the documented create endpoint returned 500 for every caller. A reflection probe
 *   over `Route::getRoutes()` found exactly ONE such route in the whole app.
 *
 * That is the whole argument for this test: a route pointing at a method that does
 * not exist is a 500 at runtime that nothing in the suite notices, because the test
 * suite calls the endpoints that work. This asserts the invariant structurally, so
 * the next mismatch fails here instead of in production.
 *
 * The second direction — every public controller method is routed — is what found
 * the two dead duplicates (`cancel`, `finish`) removed in this same task. Anything
 * legitimately unrouted must be listed in UNROUTED_BY_DESIGN with a reason, so a new
 * orphan cannot be added silently.
 */
class RoutesIntegrityTest extends TestCase
{
    /**
     * Public controller methods that are intentionally not routed.
     *
     * Each entry must say WHY, so this list is a decision and not a dumping ground.
     */
    private const UNROUTED_BY_DESIGN = [
        'App\Http\Controllers\API\ScoreController@formatScore' => 'Static formatter, called internally by RideController::create to render driver_score.',
        'App\Http\Controllers\API\RideController@autocomplete' => 'Unwired by decision: the Flutter client uses /api/rides/search address fields instead. '
            .'Kept for a future /places/autocomplete route — do NOT route under this name (it would '
            .'collide with /api/rides/{rideId} ordering).',
        'App\Http\Controllers\API\RideController@index' => 'Unwired by decision: /api/rides is served by getRides(). index() is a per-user listing '
            .'with no public route; delete it under RV-31 if it stays unwired.',
        'App\Http\Controllers\API\PushNotificationController@testNotification' => 'RV-27: a local-only debug sender (it self-gates to '
            .'app()->environment("local") and 403s elsewhere). The push routes added in RV-27 make this '
            .'class scanned; kept unrouted so a debug endpoint does not become permanent API surface. '
            .'Delete under RV-31 if it stays unwired.',
    ];

    /** @test */
    public function every_route_action_resolves_to_an_existing_public_method(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure routes have nothing to resolve.
            if (! str_contains($action, '@') || str_starts_with($action, 'Closure')) {
                continue;
            }

            [$class, $method] = array_pad(explode('@', $action, 2), 2, null);
            $uri = implode('|', $route->methods()).' '.$route->uri();

            if (! $class || ! $method) {
                $broken[] = $uri.' => '.$action.' (unparseable action)';

                continue;
            }

            if (! class_exists($class)) {
                $broken[] = $uri.' => '.$action.' (class does not exist)';

                continue;
            }

            if (! method_exists($class, $method)) {
                // This is the V10 case: a documented endpoint that 500s for everyone.
                $broken[] = $uri.' => '.$action.' (method does not exist)';

                continue;
            }

            $reflection = new ReflectionMethod($class, $method);
            if (! $reflection->isPublic()) {
                $broken[] = $uri.' => '.$action.' (method is not public)';
            }
        }

        $this->assertSame(
            [],
            $broken,
            'RV-14: these routes point at a method that cannot be invoked, so each returns 500 at '
            ."runtime and no test notices (the suite only calls the endpoints that work):\n  "
            .implode("\n  ", $broken)
        );
    }

    /** @test */
    public function every_public_controller_method_is_routed_or_explained(): void
    {
        $routed = [];
        $classes = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@') || str_starts_with($action, 'Closure')) {
                continue;
            }
            [$class, $method] = array_pad(explode('@', $action, 2), 2, null);
            if (! $class || ! class_exists($class)) {
                continue;
            }
            $routed[$class][] = $method;
            $classes[$class] = true;
        }

        $orphans = [];

        foreach (array_keys($classes) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Only methods declared on this class, not inherited ones.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if ($method->isConstructor() || str_starts_with($method->getName(), '__')) {
                    continue;
                }
                if (in_array($method->getName(), $routed[$class] ?? [], true)) {
                    continue;
                }

                $key = $class.'@'.$method->getName();
                if (! array_key_exists($key, self::UNROUTED_BY_DESIGN)) {
                    $orphans[] = $key;
                }
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'RV-14: these public controller methods are not routed and are not explained in '
            .'RoutesIntegrityTest::UNROUTED_BY_DESIGN. An unrouted duplicate is dead code that '
            ."silently drifts from the routed one (this is how cancel/ and finish() became stale):\n  "
            .implode("\n  ", $orphans)
        );
    }

    /** @test */
    public function every_unrouted_allowlist_entry_still_exists(): void
    {
        // Guards the allowlist itself: if one of these methods is deleted or routed,
        // the entry must be removed rather than left to rot.
        $stale = [];

        foreach (self::UNROUTED_BY_DESIGN as $key => $reason) {
            [$class, $method] = array_pad(explode('@', $key, 2), 2, null);

            if (! $class || ! class_exists($class) || ! method_exists($class, $method)) {
                $stale[] = $key.' (no longer exists)';

                continue;
            }

            if (trim($reason) === '') {
                $stale[] = $key.' (empty reason)';
            }
        }

        $this->assertSame(
            [],
            $stale,
            'RV-14: these UNROUTED_BY_DESIGN entries are stale — remove them when the method is '
            ."routed or deleted:\n  ".implode("\n  ", $stale)
        );
    }
}
