<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-33 — boundary / dependency ratchet (count-only-decreases).
 *
 * These rules police ARCHITECTURAL BOUNDARIES the audit cares about. Each is pinned to the
 * CURRENT real count in the tree (measured, not invented) and may only ever go DOWN — a fix
 * reduces a count, nothing legitimate raises one. That makes the ratchet non-vacuous (it fails
 * the moment a boundary is crossed) without pretending the backlog is clean: several ceilings are
 * still non-zero and represent owner-gated work recorded in the audit (e.g. RV-01's KYC public
 * storage, which needs the streaming-route decision).
 *
 * Rules and why each matters:
 *  - env( / getenv( outside config/ → returns NULL under `config:cache`, silently disabling
 *    features regardless of .env. Already 0 (RV-28 fixed it); pinned so it cannot return.
 *  - catch (\Throwable) in Controllers → swallows every error (including DB/security failures)
 *    into a generic 500, hiding real causes. Ceiling 43; only decreases as handlers get specific.
 *  - getMessage() inside controller JSON → leaks internal exception text (paths, SQL) to clients.
 *    Ceiling 120; must only fall.
 *  - withoutVerifying( → disables TLS verification. Already 0 (RV-22 fixed it); pinned.
 *  - public-disk stores in KYC/document code → IDOR/exposure surface (RV-01, owner-gated). Ceiling
 *    from the measured count; must only fall.
 *  - payment_method === outside Domain/Payment → payment branching leaking out of the domain
 *    layer. Ceiling from the measured count; must only fall.
 *
 * Each rule is a separate, named assertion so a regression names itself.
 */
class RV33BoundaryDependencyTest extends TestCase
{
    /** @return array<int, string> app PHP files */
    private function appPhpFiles(): array
    {
        $files = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }

    /** @return array<int, string> significant (non-comment, non-empty) lines per file */
    private function significantLines(string $file): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#')) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    private function assertCeiling(string $rule, int $actual, int $ceiling): void
    {
        $this->assertLessThanOrEqual(
            $ceiling,
            $actual,
            "RV-33 [{$rule}]: count rose to {$actual}, ceiling is {$ceiling}. This boundary must "
            .'only ever shrink — a legitimate change reduces it. If this is a false positive, the '
            .'ceiling must be lowered deliberately, never raised.'
        );
    }

    /** @test */
    public function env_is_never_read_outside_config(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            foreach ($this->significantLines($file) as $line) {
                if (preg_match('/(?<![\w:>\-])(env|getenv)\s*\(/', $line)) {
                    $n++;
                }
            }
        }
        // Measured 0 after RV-28; must stay 0.
        $this->assertCeiling('env-outside-config', $n, 0);
    }

    /** @test */
    public function catch_throwable_in_controllers_only_decreases(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            if (! str_contains(str_replace('\\', '/', $file), '/Http/Controllers/')) {
                continue;
            }
            foreach ($this->significantLines($file) as $line) {
                if (preg_match('/catch\s*\(\s*\\\\?Throwable/', $line)) {
                    $n++;
                }
            }
        }
        $this->assertCeiling('catch-Throwable-in-controllers', $n, 43);
    }

    /** @test */
    public function getmessage_in_controller_json_only_decreases(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            if (! str_contains(str_replace('\\', '/', $file), '/Http/Controllers/')) {
                continue;
            }
            foreach ($this->significantLines($file) as $line) {
                if (preg_match('/getMessage\(\)/', $line)) {
                    $n++;
                }
            }
        }
        $this->assertCeiling('getMessage-in-controller-json', $n, 120);
    }

    /** @test */
    public function tls_verification_is_never_disabled(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            foreach ($this->significantLines($file) as $line) {
                if (preg_match('/withoutVerifying\s*\(/', $line)) {
                    $n++;
                }
            }
        }
        // Measured 0 after RV-22; must stay 0.
        $this->assertCeiling('withoutVerifying', $n, 0);
    }

    /** @test */
    public function public_disk_stores_in_kyc_document_code_only_decrease(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            if (! preg_match('/Document|Verification/i', $file)) {
                continue;
            }
            foreach ($this->significantLines($file) as $line) {
                // Either the public-disk facade or a store(..., 'public') disk argument.
                if (preg_match("/Storage::disk\(\s*'public'/", $line)
                    || preg_match("/store\([^)]*'public'/", $line)) {
                    $n++;
                }
            }
        }
        // Measured 3 (DocumentController store + a delete/exists/size trio path). RV-01's
        // storage fix is owner-gated; this only stops the surface from growing.
        $this->assertCeiling('public-disk-in-kyc', $n, 3);
    }

    /** @test */
    public function payment_method_branching_outside_the_domain_only_decreases(): void
    {
        $n = 0;
        foreach ($this->appPhpFiles() as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/Domain/Payment/')) {
                continue; // the domain layer is allowed to branch on payment_method
            }
            foreach ($this->significantLines($file) as $line) {
                if (preg_match('/payment_method\s*===/', $line)) {
                    $n++;
                }
            }
        }
        // Measured 18 outside Domain/Payment; only decreases as logic moves into the domain.
        $this->assertCeiling('payment-method-outside-domain', $n, 18);
    }
}
