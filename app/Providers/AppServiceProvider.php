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
        // NOTIFICATION SERVICE
        // ========================================
        $this->app->singleton(\App\Services\NotificationService::class);

        // ========================================
        // VERIFICATION SERVICE
        // ========================================
        $this->app->singleton(\App\Services\Verification\DocumentVerificationService::class);

        // ========================================
        // POLICY SERVICE (privacy / cancellation text — Settings page)
        // ========================================
        $this->app->singleton(\App\Services\PolicyService::class);

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

        $this->app->bind(
            \App\Interfaces\PolicyRepositoryInterface::class,
            \App\Repositories\PolicyRepository::class
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
            } catch (\Throwable) {}
        });

        Event::listen(CacheMissed::class, function () {
            try {
                $req = request();
                if ($req->attributes->get('cache_status') !== 'HIT') {
                    $req->attributes->set('cache_status', 'MISS');
                }
            } catch (\Throwable) {}
        });
        $scheduledLogPath = storage_path('logs/scheduled');

        if (! is_dir($scheduledLogPath)) {
            mkdir($scheduledLogPath, 0755, true);
        }
    }
}
