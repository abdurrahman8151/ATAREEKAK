<?php

namespace App\Providers;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\Domain\Score\ScorePolicyFactory;
use App\Interfaces\ChatRepositoryInterface;
use App\Interfaces\ComplaintRepositoryInterface;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\EmployeeRepositoryInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\VerificationRepositoryInterface;
use App\Repositories\ChatRepository;
use App\Repositories\ComplaintRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\OtpRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\PhotoRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RideRepository;
use App\Repositories\UserRepository;
use App\Repositories\VerificationRepository;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminDriverService;
use App\Services\Admin\AdminExportService;
use App\Services\Admin\AdminReportService;
use App\Services\Admin\AdminTripService;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\AdminWalletService;
use App\Services\Chat\ChatMessageHandler;
use App\Services\Complaint\ComplaintService;
use App\Services\EmailOtpService;
use App\Services\File\FileUploadService;
use App\Services\Geocoding\ArabicPlaceNameService;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\NotificationService;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\WalletTransactionService;
use App\Services\Profile\ProfileInteractionService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\PushNotification\FcmSenderService;
use App\Services\PushNotification\PushNotificationService;
use App\Services\PushNotification\PushTokenManager;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideSearchService;
use App\Services\Ride\RideService;
use App\Services\Ride\RideValidationService;
use App\Services\Score\ScoreService;
use App\Services\Staff\EmployeeAuthService;
use App\Services\Staff\EmployeeManagementService;
use App\Services\Staff\ReviewModerationService;
use App\Services\Staff\StaffComplaintService;
use App\Services\Staff\StaffJwtService;
use App\Services\TextMeBotOtpService;
use App\Services\Verification\DocumentVerificationService;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
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
        // ========================================
        // GEOCODING SERVICES (CRITICAL - WAS MISSING!)
        // ========================================
        $this->app->singleton(ArabicPlaceNameService::class);

        $this->app->singleton(GeocodingService::class, function ($app) {
            return new GeocodingService(
                $app->make(ArabicPlaceNameService::class)
            );
        });
        $this->app->singleton(ScoreService::class, function ($app) {
            return new ScoreService(
                $app->make(ScorePolicyFactory::class)
            );
        });
        $this->app->singleton(ScorePolicyFactory::class);
        $this->app->singleton(RouteCalculationService::class);

        // ========================================
        // PUSH NOTIFICATION SERVICES
        // ========================================
        $this->app->singleton(PushTokenManager::class);
        $this->app->singleton(FcmSenderService::class);
        $this->app->singleton(PushNotificationService::class);

        // ========================================
        // PROFILE SERVICES
        // ========================================
        $this->app->singleton(ProfileUpdateService::class);
        $this->app->singleton(ProfileInteractionService::class);

        // ========================================
        // CHAT SERVICES
        // ========================================
        $this->app->singleton(ChatMessageHandler::class);

        // ========================================
        // FILE UPLOAD SERVICE
        // ========================================
        $this->app->singleton(FileUploadService::class);

        // ========================================
        // RIDE SERVICES
        // ========================================
        $this->app->singleton(RideService::class);
        $this->app->singleton(BookingService::class);
        $this->app->singleton(RideValidationService::class);
        $this->app->singleton(RideSearchService::class);

        // ========================================
        // PAYMENT SERVICES
        // ========================================
        $this->app->singleton(CashRideFeeService::class);
        $this->app->singleton(WalletTransactionService::class);
        $this->app->singleton(EPayPaymentStrategy::class);
        $this->app->singleton(CashPaymentStrategy::class);
        $this->app->singleton(PaymentStrategyFactory::class);
        // ========================================
        // ADMIN SERVICES
        // ========================================
        $this->app->singleton(AdminAuthService::class);
        $this->app->singleton(AdminWalletService::class);
        $this->app->singleton(AdminReportService::class);
        $this->app->singleton(AdminExportService::class);
        $this->app->singleton(AdminTripService::class);
        $this->app->singleton(AdminDriverService::class);
        $this->app->singleton(AdminUserService::class);
        // ── Staff / Employee Services ────────────────────────────────────────────
        $this->app->singleton(StaffJwtService::class);
        $this->app->singleton(EmployeeAuthService::class);
        $this->app->singleton(EmployeeManagementService::class);
        $this->app->singleton(ReviewModerationService::class);
        $this->app->singleton(StaffComplaintService::class);

        // ========================================
        // WALLET SERVICES
        // ========================================
        $this->app->singleton(WalletRequestService::class);

        // ========================================
        // NOTIFICATION SERVICE
        // ========================================
        $this->app->singleton(NotificationService::class);

        // ========================================
        // VERIFICATION SERVICE
        // ========================================
        $this->app->singleton(DocumentVerificationService::class);

        // ========================================
        // REPOSITORY BINDINGS
        // ========================================
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );
        $this->app->bind(
            EmployeeRepositoryInterface::class,
            EmployeeRepository::class,
        );
        $this->app->singleton(ComplaintService::class);

        $this->app->bind(
            ComplaintRepositoryInterface::class,
            ComplaintRepository::class,
        );

        $this->app->bind(
            ProfileRepositoryInterface::class,
            ProfileRepository::class
        );
        $this->app->bind(
            EmailOtpServiceInterface::class,
            EmailOtpService::class,
        );
        $this->app->bind(
            OtpRepositoryInterface::class,
            OtpRepository::class
        );

        $this->app->bind(
            PhotoRepositoryInterface::class,
            PhotoRepository::class
        );

        $this->app->bind(
            ChatRepositoryInterface::class,
            ChatRepository::class
        );

        $this->app->bind(
            VerificationRepositoryInterface::class,
            VerificationRepository::class
        );

        $this->app->bind(
            PasswordResetRepositoryInterface::class,
            PasswordResetRepository::class
        );

        $this->app->bind(
            RideRepositoryInterface::class,
            RideRepository::class
        );

        // ========================================
        // OTP SERVICES (Special binding)
        // ========================================
        $this->app->bind(TextMeBotOtpService::class, function ($app) {
            return new TextMeBotOtpService($app->make(OtpRepositoryInterface::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardJwtSecret();
        $this->guardOtpTestingModes();

        Schema::defaultStringLength(191);

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
        $scheduledLogPath = storage_path('logs/scheduled');

        if (! is_dir($scheduledLogPath)) {
            mkdir($scheduledLogPath, 0755, true);
        }

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
        if (app()->environment('local', 'testing')) {
            return;
        }

        $secret = (string) config('jwt.secret');

        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be set to at least 32 bytes outside local/testing '
                .'environments; it currently resolves to '
                .($secret === '' ? 'an EMPTY value' : strlen($secret).' bytes')
                .'. Tokens signed with an empty or short key are forgeable. '
                .'Generate one with: php artisan jwt:secret'
            );
        }
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
        if (app()->environment('local', 'testing')) {
            return;
        }

        $dangerous = [];

        foreach ([
            'EMAIL_OTP_MODE' => config('otp.email_mode'),
            'WALLET_OTP_MODE' => config('otp.wallet_mode'),
            'OTP_BYPASS_ENABLED' => config('otp.bypass') ? 'true' : null,
        ] as $name => $value) {
            if (is_string($value) && strtolower(trim($value)) === ($name === 'OTP_BYPASS_ENABLED' ? 'true' : 'testing')) {
                $dangerous[] = $name.'='.$value;
            }
        }

        if ($dangerous !== []) {
            throw new \RuntimeException(
                'Refusing to start with a testing OTP mode enabled outside local/testing: '
                .implode(', ', $dangerous).'. In this state every OTP the app issues is '
                .'readable by whoever requests it (signup, password reset, wallet top-up). '
                .'Remove the variable(s) from the deployment environment.'
            );
        }
    }
}
