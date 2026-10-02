<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * T4-1: this class used to call withoutMiddleware(ThrottleRequests::class)
     * for EVERY test, so rate limiting was structurally invisible to the whole
     * suite — the exact "test environment cannot see the truth" defect (it also
     * meant T2-3/T2-10 enforcement could never be asserted end-to-end).
     *
     * Middleware now runs as registered. The throttle store is the array cache
     * (phpunit.xml CACHE_DRIVER=array), which is rebuilt per test method, so a
     * test only sees 429s when it makes more than the category limit of calls
     * with the SAME bucket key within one method. Tests that legitimately do
     * that (negative probes, OTP attempt-limit loops) opt out explicitly via
     * disableThrottling() below — visible, per-test, and greppable, instead of
     * a global blind spot.
     *
     * RV-37 (hermeticity half): `Http::preventStrayRequests()` makes an outgoing
     * request that no `Http::fake()` stub matched a hard, named failure instead of
     * a silent real call. Without it a stray provider call went to the network and
     * only failed (or passed) by whatever the provider happened to answer — the
     * acceptance criterion is "no test can reach the network", not "few strays".
     *
     * Scope, stated so it is not over-read: this intercepts the `Http` FACADE only.
     * `WhatsAppOtpService`, `TextMeBotOtpService` and `GoogleController` build a
     * Guzzle client directly and bypass it; those are gated by configuration
     * instead (see the config keys asserted in the hermeticity ratchet).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Opt out of throttling for this test only, with a stated reason. */
    protected function disableThrottling(string $why): void
    {
        // The reason parameter is deliberate: it forces every opt-out to be
        // self-documenting and greppable, which is the point of T4-1.
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
