<?php

namespace App\Providers;

use App\Support\BootGuards;
use App\Support\ContainerBindings;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The binding catalogue is a contiguous run of the original body, called in the
        // order its bindings appeared, so container binding order is unchanged.
        (new ContainerBindings($this->app))->register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardJwtSecret();
        $this->guardOtpTestingModes();

        $this->configureSchemaAndModelStrictness();
        $this->listenForCacheEvents();
        $this->ensureScheduledLogDirectory();
        $this->guardBroadcastCredentials();
        $this->guardQueueDriver();
    }

    /**
     * Schema defaults and the RV-38 Eloquent strictness flags.
     */
    private function configureSchemaAndModelStrictness(): void
    {
        Schema::defaultStringLength(191);

        // RV-38 — Eloquent data-integrity strictness, OUTSIDE PRODUCTION.
        //
        // WHY: silent data loss is invisible by construction — `Model::create([...
        // 'not_fillable' => x])` drops the value with no error, and reading a column that
        // wasn't selected returns null as if it were legitimately absent. This session alone
        // found several such bugs (RV-23: complaint ride_id/complained_id silently dropped;
        // RV-38's own measurement surfaced a PushTokenManager one that silently stopped
        // reassigning token ownership). Turning them into loud exceptions in tests/dev is how
        // the class gets caught at the source instead of in production data.
        //
        // WHY NOT production: an exception in prod is a 500; production keeps today's
        // lenient behavior. This is R1's prescription verbatim (`shouldBeStrict(!
        // isProduction())`), and the "roll out tests first, fix what surfaces" step is done —
        // measured: enabling these two flags across the full suite surfaced EXACTLY 2
        // offenders (both now fixed: PushTokenManager's redundant updated_at mass-assign, and
        // a stale UntangleBatch fixture with 4 phantom keys).
        //
        // preventLazyLoading() is now armed too (RV-37 / un9, owner ruling 2026-10-02). It was
        // held back here because "the lazy-loading half is large (N+1 from RV-24)" - so the sites
        // were found by arming it LOCALLY and running the whole suite (86 failures / 27 logged
        // violations), then fixed at the fetch sites, which is where the N+1s actually cost a
        // query each: BookingService (booking fetches, idempotent replay, refresh returns),
        // RideService (cancel/finish/confirm), CashRideFeeService, ScoreService,
        // RideValidationService, WalletController, AdminDriverService, AdminReportService, the
        // backfill command, and four test fixtures that rendered a resource without loading the
        // relations it reads. Measured after the fixes: arming the flag adds **zero** failing
        // tests versus the flag-off baseline (identical 87-entry red set both ways). The guard
        // stays off in PRODUCTION only - a violation there would be a 500, and production is
        // monitored for performance, not for eager-load correctness.
        if (! $this->app->isProduction()) {
            Model::preventSilentlyDiscardingAttributes();
            Model::preventAccessingMissingAttributes();
            Model::preventLazyLoading();
        }
    }

    /**
     * Per-request cache-status instrumentation.
     */
    private function listenForCacheEvents(): void
    {
        Event::listen(CacheHit::class, function () {
            try {
                request()->attributes->set('cache_status', 'HIT');
            } catch (\Throwable) {
            } // intentionally silent: fires per cache event on every request
        });

        Event::listen(CacheMissed::class, function () {
            try {
                $req = request();
                if ($req->attributes->get('cache_status') !== 'HIT') {
                    $req->attributes->set('cache_status', 'MISS');
                }
            } catch (\Throwable) {
            } // intentionally silent: fires per cache event on every request
        });
    }

    /**
     * Make sure the scheduled-task log directory exists.
     */
    private function ensureScheduledLogDirectory(): void
    {
        $scheduledLogPath = storage_path('logs/scheduled');

        if (! is_dir($scheduledLogPath)) {
            mkdir($scheduledLogPath, 0755, true);
        }
    }

    /**
     * T2-8: refuse to boot with pusher configured but no credentials.
     */
    private function guardBroadcastCredentials(): void
    {
        // T2-8: the pusher credentials used to be in-code literals, so a deploy
        // that lost its env still booted "successfully" on a shared, publicly
        // committed credential. With no defaults left in config/broadcasting.php
        // the correct behaviour is a hard stop, mirroring SpecialAccountSeeder's
        // existing env()-missing -> refuse pattern. Exempted in local AND
        // testing, so it can never brick a developer machine or the suite
        // (which legitimately runs without real broadcast credentials); every
        // real deployment environment — production, staging, or any environment
        // added later — is still covered.
        if (! app()->environment('local', 'testing')
            && config('broadcasting.default') === 'pusher'
            && (empty(config('broadcasting.connections.pusher.key'))
                || empty(config('broadcasting.connections.pusher.secret')))) {
            throw new \RuntimeException(
                'BROADCAST_DRIVER=pusher requires PUSHER_APP_KEY and PUSHER_APP_SECRET to be set in the '
                .'environment. They are no longer defaulted in config/broadcasting.php because the '
                .'previous defaults were committed to version control (see docs/audit). Set the env vars '
                .'with rotated credentials, or set BROADCAST_DRIVER=null.'
            );
        }
    }

    /**
     * T3-14: refuse to boot on the sync queue outside local/testing.
     */
    private function guardQueueDriver(): void
    {
        // T3-14: SendPushNotificationJob is ShouldQueue with tries/backoff, but
        // config/queue.php defaults QUEUE_CONNECTION to `sync`, which runs it
        // inline — a slow or failing FCM call (3 retries + backoff) then executes
        // inside the HTTP request and the retry configuration is meaningless
        // there. Previously an unset env silently degraded every notification
        // into a request-blocking call. Fail fast outside local/testing,
        // mirroring the T2-8 guard above: a deploy that lost its env refuses to
        // boot instead of serving request-blocking pushes. local/testing stay
        // exempt because the suite deliberately runs on sync.
        if (! app()->environment('local', 'testing')
            && config('queue.default') === 'sync') {
            throw new \RuntimeException(
                'QUEUE_CONNECTION must be set to an async driver (redis/database) in '
                .'production-like environments. It currently resolves to "sync", which '
                .'runs push notifications inline and blocks requests on FCM failures. '
                .'Set QUEUE_CONNECTION=redis (see .env.example) or deploy with an '
                .'explicit queue worker.'
            );
        }
    }

    /**
     * RV-04: refuse to boot outside local/testing without a usable JWT secret.
     *
     * `JwtService::generateSignature()` passes `config('jwt.secret')` straight to
     * `hash_hmac()`. PHP 8.2 coerces the null that an unset/blank JWT_SECRET
     * produces into '', so the app will happily sign HS256 tokens with an EMPTY
     * key — and an empty key is public knowledge, which makes every access token
     * forgeable by anyone. (`StaffJwtService::secret()` throws on this; the user
     * path never did.) `.env.example` ships `JWT_SECRET=` blank, so this is one
     * missing variable away from being live, and the failure is silent: the app
     * serves traffic normally while issuing forgeable credentials.
     *
     * Fail fast instead, with the same shape as the T2-8 queue guard above:
     * local/testing stay exempt (the suite runs on a dummy secret), everything
     * else must supply >= 32 bytes.
     */
    private function guardJwtSecret(): void
    {
        BootGuards::jwtSecret();
    }

    /**
     * RV-16 — refuse to boot with a testing OTP mode enabled outside development.
     *
     * These switches exist so the test suite and local work can read a code back out
     * of the API instead of waiting for a real message. Left enabled on a deployed
     * environment they publish every single-factor credential the app issues:
     * signup, password reset and wallet top-up all become completable by anyone who
     * asks for a code.
     *
     * `App\Support\OtpDisclosure` already refuses to RETURN a code outside
     * local/testing, so this guard is defence in depth for the other direction: it
     * turns a dangerous deployment into a loud boot failure instead of a silent
     * misconfiguration.
     *
     * RV-37: it reads config('otp.*') rather than getenv(). Config is the correct
     * source for a guard: when the config is cached, the cached value IS what the
     * deployment will actually use, whereas getenv() only sees the live process. It
     * also removes the last raw-environment reader from the boot path, which is what
     * let a test's putenv() decide whether the app boots.
     */
    private function guardOtpTestingModes(): void
    {
        BootGuards::guardOtpTestingModes();
    }
}
