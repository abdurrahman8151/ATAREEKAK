<?php

namespace Tests\Feature\AppFuture;

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Tests\TestCase;

/**
 * AF-1 (app-future audit) — TLS honesty and Octane upload hygiene.
 *
 * The app-future audit found outbound provider calls shipped with TLS peer
 * verification disabled (`verify => false`) — a WAMP dev-box CA workaround that
 * rode along to the 5 production replicas, where the OpenRoute response drives
 * ride distance and distance drives fare (MITM target), and the CallMeBot
 * request carries an API key. Verified HTTPS from this machine was proven
 * working against both hosts before the flags were removed.
 *
 * config/octane.php also had FlushUploadedFiles commented out: under
 * RoadRunner's resident workers that leaks one temp file per uploaded request
 * for the worker's lifetime.
 *
 * These tests pin both, comment-aware: a re-added `verify => false` in CODE
 * fails them, while this file's own prose cannot trip the detector (comments
 * are stripped via the tokenizer before scanning).
 */
class TlsAndOctaneHygieneTest extends TestCase
{
    /** Every PHP file under app/, source with comments stripped. */
    private function codeOnlySources(): array
    {
        $out = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src   = file_get_contents($f->getPathname());
            $clean = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue; // strip comments: explanations may quote the pattern
                }
                $clean .= is_array($t) ? $t[1] : $t;
            }
            $out[$f->getPathname()] = $clean;
        }

        return $out;
    }

    public function test_no_outbound_http_call_disables_tls_verification(): void
    {
        $offenders = [];
        foreach ($this->codeOnlySources() as $path => $clean) {
            if (preg_match("/(['\"]verify['\"]\s*=>\s*false)/i", $clean)) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
            }
            if (preg_match('/CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*(false|0)\b/i', $clean)) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path) . ' (VERIFYPEER=false)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'AF-1: TLS peer verification must never be disabled in app code. Offenders: '
                . implode(', ', $offenders)
        );
    }

    public function test_octane_flushes_uploaded_files_when_a_request_terminates(): void
    {
        $listeners = config('octane.listeners', []);

        $key = 'Laravel\Octane\Events\RequestTerminated';
        $this->assertArrayHasKey($key, $listeners, 'octane.listeners must register a RequestTerminated group');
        $this->assertContains(
            FlushUploadedFiles::class,
            $listeners[$key],
            'AF-1: FlushUploadedFiles must stay enabled or uploads leak temp files per request on resident workers'
        );
    }

    public function test_the_whatsapp_service_uses_one_verified_client(): void
    {
        // AF-1 root cause: the service built a SECOND, insecure Guzzle client
        // per send despite already owning a verified one in its constructor.
        // Pin: exactly one `new Client(` in the file — the verified constructor
        // client — and no client construction inside the send method.
        $src = (string) file_get_contents(app_path('Services/WhatsAppOtpService.php'));

        $this->assertSame(
            1,
            substr_count($src, 'new Client('),
            'WhatsAppOtpService must construct one (verified) client, not per-call insecure ones'
        );
    }
}
