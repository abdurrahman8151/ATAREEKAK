<?php

namespace Tests\Feature\AppFuture;

use Tests\TestCase;

/**
 * AF-2' (app-future audit) — bounded-context boundary ratchet.
 *
 * The audit's verdict was that Syride is a *layered* monolith but not a *modular*
 * one: one concept lives in 2-3 places and the Domain/ island is silently
 * entangled with everything below it. Folder structure cannot fix that — only an
 * automated rule can. This file IS that rule: it scans every PHP file in app/ and
 * counts references per forbidden edge. Each edge has a BASELINE; a new violation
 * fails the suite. Lowering a baseline is a deliberate edit to the BASELINES const
 * — and the file refuses to run if counts are below the recorded baseline, so
 * progress must be *claimed*, never accidental.
 *
 * The edges (see docs/audit/ARCHITECTURE_MAP.md for the why):
 *   R1 Domain -> App\Services      grandfathered 2 (payment strategies reach into
 *                                  WalletTransactionService; AF-6 removes them)
 *   R2 Repositories -> Services    grandfathered 2 (PasswordReset->JwtService,
 *                                  RideRepository->Geocoding)
 *   R3 Request below Http layer    HARD 0 (nothing outside the HTTP boundary may
 *                                  take Illuminate\Http\Request)
 *   R4 Request inside Services     budget 1 (AdminAuthService; AF-4 kills it)
 *   R5 Async -> App\Http           HARD 0 (Events/Listeners/Jobs/Notifications/Mail
 *                                  must never reach into the HTTP layer)
 *   R6 Controllers -> App\Models   budget 21 of 38 controllers (the extraction
 *                                  target of AF-6/7; may only shrink)
 *   R7 Models -> App\Enums         budget 2 (Complaint, Employee)
 *   R8 Domain -> App\Models        grandfathered 10 (strategies/policies type-hint
 *                                  Eloquent models; AF-6 moves them to DTOs)
 *   R9 Domain -> App\Http          HARD 0
 *
 * Matching is substring on 'App\<Namespace' and the exact Request import — no
 * regex-escaping traps (a previous analysis script failed twice on them; this one
 * is deliberately dumb and therefore honest).
 */
class BoundaryDependencyTest extends TestCase
{
    private const BASELINES = [
        'domain_to_services' => 2,
        'repos_to_services' => 2,
        'request_below_http' => 0,
        'request_in_services' => 1,
        'async_to_http' => 0,
        // RV-19 item 2 / AF-7, owner-approved 2026-10-08: 21 -> 20. LOWERED ONCE, after the reduction was
        // MEASURED, never assumed - the run above printed "only 20 violations remain ... lower the
        // number in BASELINES to claim the improvement" and listed the twenty files, so the claim
        // rests on the test's own output rather than on a doc saying so.
        //
        // What removed one: `ProfileController` no longer imports `App\Models\*` after the duplicated
        // rides-as-driver / bookings-as-passenger status rollup it shared verbatim with
        // `StaffOperationsController` was consolidated into `UserRideStatsService` (`R2 sec 93/95`).
        //
        // This number MAY ONLY SHRINK. Its purpose is to make a ratchet that bites: while the budget
        // sat at 21 with 20 real violations, a NEW controller reaching for a model could be added
        // without turning the suite red, which is the opposite of what a baseline is for. At 20 it
        // bites again.
        //
        // The remaining twenty are NOT treated as defects: `AGENTS.md`'s own reading is that a
        // `User::findOrFail($id)` in a controller is ordinary Laravel, and wrapping each one to satisfy
        // a number would add indirection with no benefit. The money-path sites are reserved by
        // `AGENTS.md` and were deliberately not touched.
        'controllers_to_models' => 20,
        // RV-11-B, owner decision D10 = A (2026-10-04): 2 -> 3. Raised ONCE, on instruction, with
        // the edges named rather than a bare number - a bare raise is indistinguishable from drift.
        //
        // CORRECTION WORTH KEEPING: this edge is counted PER FILE that references `App\Enums`, not
        // per enum, so the owner's list of four model/enum PAIRS maps to THREE counted edges. The
        // three files are exactly:
        //     Complaint  -> ComplaintStatus AND ComplaintType   (two enums, one edge)
        //     Employee   -> StaffRole
        //     Wallet     -> WalletKind
        // The old budget was 2 "because Complaint, Employee" (see the R7 note above); `Wallet` arrived
        // later. All three are genuine domain enums, not violations - the baseline predates them.
        // Still a CEILING, not a target: a fourth file referencing Enums fails again.
        'models_to_enums' => 3,
        'domain_to_models' => 10,
        'domain_to_http' => 0,
    ];

    /** Namespaces below the HTTP boundary that must never import the Request. */
    private const NO_REQUEST_NAMESPACES = [
        'Domain', 'Enums', 'DTOs', 'Models', 'Repositories', 'Events',
        'Listeners', 'Jobs', 'Notifications', 'Mail', 'Observers', 'Broadcasting',
    ];

    /** Async half of the app — must not reach into App\Http. */
    private const ASYNC_NAMESPACES = ['Events', 'Listeners', 'Jobs', 'Notifications', 'Mail'];

    /** @return array<string, string[]> violation buckets keyed by rule id */
    private function scan(): array
    {
        $hits = array_fill_keys(array_keys(self::BASELINES), []);
        $app = app_path();

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($app) + 1));
            $parts = explode('/', $rel);
            $top = $parts[0];
            $sub = $parts[1] ?? '';
            $src = (string) file_get_contents($f->getPathname());

            $refs = static fn (string $ns): bool => strpos($src, 'App\\'.$ns) !== false;
            $takesRequest = strpos($src, 'use Illuminate\Http\Request;') !== false;

            if ($top === 'Domain') {
                if ($refs('Services')) {
                    $hits['domain_to_services'][] = $rel;
                }
                if ($refs('Models')) {
                    $hits['domain_to_models'][] = $rel;
                }
                if ($refs('Http')) {
                    $hits['domain_to_http'][] = $rel;
                }
            }
            if ($top === 'Repositories' && $refs('Services')) {
                $hits['repos_to_services'][] = $rel;
            }
            if ($top === 'Http' && $sub === 'Controllers' && $refs('Models')) {
                $hits['controllers_to_models'][] = $rel;
            }
            if ($top === 'Models' && $refs('Enums')) {
                $hits['models_to_enums'][] = $rel;
            }
            if (in_array($top, self::NO_REQUEST_NAMESPACES, true) && $takesRequest) {
                $hits['request_below_http'][] = $rel;
            }
            if ($top === 'Services' && $takesRequest) {
                $hits['request_in_services'][] = $rel;
            }
            if (in_array($top, self::ASYNC_NAMESPACES, true) && $refs('Http')) {
                $hits['async_to_http'][] = $rel;
            }
        }

        return $hits;
    }

    public static function boundaryProvider(): array
    {
        $out = [];
        foreach (self::BASELINES as $rule => $limit) {
            $out[$rule] = [$rule, $limit];
        }

        return $out;
    }

    /**
     * @dataProvider boundaryProvider
     */
    public function test_a_boundary_edge_never_grows_beyond_its_baseline(string $rule, int $baseline): void
    {
        $scan = $this->scan();
        $found = array_values(array_unique($scan[$rule]));

        // IMPROVEMENT DETECTED: counts below baseline must be *claimed* by editing
        // BASELINES, so drift can never silently loosen the ratchet.
        $this->assertGreaterThanOrEqual(
            $baseline,
            count($found),
            "Rule '$rule': baseline is $baseline but only ".count($found).' violations remain — '
                .'lower the number in BASELINES to claim the improvement. Found: '.implode(', ', $found)
        );

        $this->assertLessThanOrEqual(
            $baseline,
            count($found),
            "Rule '$rule' (max $baseline): new boundary violation — ".implode(', ', $found)
                .'. Fix the boundary (see docs/audit/ARCHITECTURE_MAP.md); do not raise the baseline.'
        );
    }
}
