<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

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
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** Opt out of throttling for this test only, with a stated reason. */
    protected function disableThrottling(string $why): void
    {
        // The reason parameter is deliberate: it forces every opt-out to be
        // self-documenting and greppable, which is the point of T4-1.
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
