<?php

namespace App\Providers;

use App\Interfaces\ChatRepositoryInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Models\User;
use App\Observers\UserObserver;
use App\Repositories\ChatRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\RideRepository;
use App\Services\TextMeBotOtpService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use App\Interfaces\EmailOtpServiceInterface;
use App\Services\EmailOtpService;

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
        $this->app->singleton(\App\Services\Geocoding\ArabicPlaceNameService::class);

        $this->app->singleton(\App\Services\Geocoding\GeocodingService::class, function ($app) {
            return new \App\Services\Geocoding\GeocodingService(
                $app->make(\App\Services\Geocoding\ArabicPlaceNameService::class)
            );
        });
        $this->app->singleton(\App\Services\Score\ScoreService::class, function ($app) {
            return new \App\Services\Score\ScoreService(
                $app->make(\App\Domain\Score\ScorePolicyFactory::class)
            );
        });
        $this->app->singleton(\App\Domain\Score\ScorePolicyFactory::class);
        $this->app->singleton(\App\Services\Geocoding\RouteCalculationService::class);

        // ========================================
        // PUSH NOTIFICATION SERVICES
        // ========================================
        $this->app->singleton(\App\Services\PushNotification\PushTokenManager::class);
        $this->app->singleton(\App\Services\PushNotification\FcmSenderService::class);
        $this->app->singleton(\App\Services\PushNotification\PushNotificationService::class);

        // ========================================
        // PROFILE SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Profile\ProfileUpdateService::class);
        $this->app->singleton(\App\Services\Profile\ProfileInteractionService::class);

        // ========================================
        // CHAT SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Chat\ChatMessageHandler::class);

        // ========================================
        // FILE UPLOAD SERVICE
        // ========================================
        $this->app->singleton(\App\Services\File\FileUploadService::class);

        // ========================================
        // RIDE SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Ride\RideService::class);
        $this->app->singleton(\App\Services\Ride\BookingService::class);
        $this->app->singleton(\App\Services\Ride\RideValidationService::class);
        $this->app->singleton(\App\Services\Ride\RideSearchService::class);

        // ========================================
        // PAYMENT SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Payment\CashRideFeeService::class);
        $this->app->singleton(\App\Services\Payment\WalletTransactionService::class);
        $this->app->singleton(\App\Domain\Payment\Strategies\EPayPaymentStrategy::class);
        $this->app->singleton(\App\Domain\Payment\Strategies\CashPaymentStrategy::class);
        $this->app->singleton(\App\Domain\Payment\Strategies\PaymentStrategyFactory::class);
        // ========================================
        // ADMIN SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Admin\AdminAuthService::class);
        $this->app->singleton(\App\Services\Admin\AdminWalletService::class);
        $this->app->singleton(\App\Services\Admin\AdminReportService::class);
        $this->app->singleton(\App\Services\Admin\AdminExportService::class);
        $this->app->singleton(\App\Services\Admin\AdminTripService::class);
        $this->app->singleton(\App\Services\Admin\AdminDriverService::class);
        $this->app->singleton(\App\Services\Admin\AdminUserService::class);
        // ── Staff / Employee Services ────────────────────────────────────────────
        $this->app->singleton(\App\Services\Staff\StaffJwtService::class);
        $this->app->singleton(\App\Services\Staff\EmployeeAuthService::class);
        $this->app->singleton(\App\Services\Staff\EmployeeManagementService::class);
        $this->app->singleton(\App\Services\Staff\ReviewModerationService::class);
        $this->app->singleton(\App\Services\Staff\StaffComplaintService::class);

        // ========================================
        // WALLET SERVICES
        // ========================================
        $this->app->singleton(\App\Services\Wallet\WalletRequestService::class);

        // ========================================
        // NOTIFICATION SERVICE
        // ========================================
        $this->app->singleton(\App\Services\NotificationService::class);

        // ========================================
        // VERIFICATION SERVICE
        // ========================================
        $this->app->singleton(\App\Services\Verification\DocumentVerificationService::class);

        // ========================================
        // REPOSITORY BINDINGS
        // ========================================
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );
        $this->app->bind(
            \App\Interfaces\EmployeeRepositoryInterface::class,
            \App\Repositories\EmployeeRepository::class,
        );
        $this->app->singleton(\App\Services\Complaint\ComplaintService::class);

        $this->app->bind(
            \App\Interfaces\ComplaintRepositoryInterface::class,
            \App\Repositories\ComplaintRepository::class,
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
            \App\Interfaces\OtpRepositoryInterface::class,
            \App\Repositories\OtpRepository::class
        );

        $this->app->bind(
            \App\Interfaces\PhotoRepositoryInterface::class,
            \App\Repositories\PhotoRepository::class
        );

        $this->app->bind(
            ChatRepositoryInterface::class,
            ChatRepository::class
        );

        $this->app->bind(
            \App\Interfaces\VerificationRepositoryInterface::class,
            \App\Repositories\VerificationRepository::class
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
        Schema::defaultStringLength(191);

        Event::listen(CacheHit::class, function () {
            try {
                request()->attributes->set('cache_status', 'HIT');
            } catch (\Throwable) {} // intentionally silent: fires per cache event on every request
        });

        Event::listen(CacheMissed::class, function () {
            try {
                $req = request();
                if ($req->attributes->get('cache_status') !== 'HIT') {
                    $req->attributes->set('cache_status', 'MISS');
                }
            } catch (\Throwable) {} // intentionally silent: fires per cache event on every request
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
                . 'environment. They are no longer defaulted in config/broadcasting.php because the '
                . 'previous defaults were committed to version control (see docs/audit). Set the env vars '
                . 'with rotated credentials, or set BROADCAST_DRIVER=null.'
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
                . 'production-like environments. It currently resolves to "sync", which '
                . 'runs push notifications inline and blocks requests on FCM failures. '
                . 'Set QUEUE_CONNECTION=redis (see .env.example) or deploy with an '
                . 'explicit queue worker.'
            );
        }
    }
}
