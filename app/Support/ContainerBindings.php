<?php

namespace App\Support;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\Domain\Score\ScorePolicyFactory;
use App\Interfaces\ChatMessageHandlerInterface;
use App\Interfaces\ChatRepositoryInterface;
use App\Interfaces\ComplaintRepositoryInterface;
use App\Interfaces\DocumentVerificationInterface;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\EmployeeManagementInterface;
use App\Interfaces\EmployeeRepositoryInterface;
use App\Interfaces\Geocoding\GeocodingServiceInterface;
use App\Interfaces\Geocoding\RouteCalculationServiceInterface;
use App\Interfaces\NotificationPort;
use App\Interfaces\OtpMailInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\Payment\BookingChargeInterface;
use App\Interfaces\Payment\CancellationRefundInterface;
use App\Interfaces\Payment\CashRideFeeServiceInterface;
use App\Interfaces\Payment\LedgerServiceInterface;
use App\Interfaces\Payment\NoShowSettlementInterface;
use App\Interfaces\Payment\PassengerWalletChargerInterface;
use App\Interfaces\Payment\PaymentStrategyFactoryInterface;
use App\Interfaces\Payment\RidePayoutInterface;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Interfaces\ScoreServiceInterface;
use App\Interfaces\StaffJwtInterface;
use App\Interfaces\SupportAgentRoutingInterface;
use App\Interfaces\TokenRevokerInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\UserRideStatsInterface;
use App\Interfaces\VerificationApprovalInterface;
use App\Interfaces\VerificationRepositoryInterface;
use App\Mail\OtpMailer;
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
use App\Services\JwtService;
use App\Services\NotificationService;
use App\Services\Payment\BookingCharge;
use App\Services\Payment\CancellationRefund;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\LedgerService;
use App\Services\Payment\NoShowSettlement;
use App\Services\Payment\PassengerWalletCharger;
use App\Services\Payment\RidePayout;
use App\Services\Payment\WalletAccess;
use App\Services\Profile\ProfileInteractionService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\PushNotification\FcmSenderService;
use App\Services\PushNotification\PushNotificationService;
use App\Services\PushNotification\PushTokenManager;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideSearchService;
use App\Services\Ride\RideService;
use App\Services\Ride\RideValidationService;
use App\Services\Ride\UserRideStatsService;
use App\Services\Score\ScoreLedger;
use App\Services\Score\ScoreService;
use App\Services\Staff\EmployeeAuthService;
use App\Services\Staff\EmployeeManagementService;
use App\Services\Staff\ReviewModerationService;
use App\Services\Staff\StaffComplaintService;
use App\Services\Staff\StaffJwtService;
use App\Services\Staff\SupportAgentRoutingService;
use App\Services\TextMeBotOtpService;
use App\Services\Verification\DocumentVerificationService;
use App\Services\Verification\VerificationApprovalService;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Contracts\Foundation\Application;

/**
 * The container binding catalogue that used to live inline in `AppServiceProvider::register()`.
 *
 * Every group method below is a contiguous run of the original provider body and they are
 * called in the order their bindings appeared, so container binding order is byte-for-byte
 * unchanged.
 */
class ContainerBindings
{
    public function __construct(private readonly Application $app) {}

    /**
     * Register every binding in the original provider order.
     */
    public function register(): void
    {
        $this->registerDomainServices();
        $this->registerPushNotificationServices();
        $this->registerProfileChatAndFileServices();
        $this->registerRideServices();
        $this->registerPaymentServices();
        $this->registerAdminServices();
        $this->registerStaffServices();
        $this->registerSupportServices();
        $this->registerRepositories();
        $this->registerBoundaryPorts();
        $this->registerOtpServices();
    }

    /**
     * Geocoding, score and route-calculation services.
     */
    private function registerDomainServices(): void
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
            // RV-11 (`R2 sec 73`): `ScoreService` no longer takes `ScorePolicyFactory` - all policy
            // lookups moved into `ScoreLedger`, which is now its only dependency. This binding passed
            // the factory alone and would have thrown ArgumentCountError on first resolution.
            return new ScoreService(
                $app->make(ScoreLedger::class)
            );
        });
        $this->app->singleton(ScoreLedger::class);
        $this->app->singleton(ScorePolicyFactory::class);
        $this->app->singleton(RouteCalculationService::class);
    }

    /**
     * Push notification services.
     */
    private function registerPushNotificationServices(): void
    {
        // ========================================
        // PUSH NOTIFICATION SERVICES
        // ========================================
        $this->app->singleton(PushTokenManager::class);
        $this->app->singleton(FcmSenderService::class);
        $this->app->singleton(PushNotificationService::class);
    }

    /**
     * Profile, chat and file-upload services.
     */
    private function registerProfileChatAndFileServices(): void
    {
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
    }

    /**
     * Ride lifecycle services.
     */
    private function registerRideServices(): void
    {
        // ========================================
        // RIDE SERVICES
        // ========================================
        $this->app->singleton(RideService::class);
        $this->app->singleton(BookingService::class);
        $this->app->singleton(RideValidationService::class);
        $this->app->singleton(RideSearchService::class);
    }

    /**
     * Payment, wallet-access and payment-strategy services.
     */
    private function registerPaymentServices(): void
    {
        // ========================================
        // PAYMENT SERVICES
        // ========================================
        $this->app->singleton(CashRideFeeService::class);
        $this->app->singleton(BookingCharge::class);
        $this->app->singleton(RidePayout::class);
        $this->app->singleton(CancellationRefund::class);
        $this->app->singleton(NoShowSettlement::class);
        $this->app->singleton(WalletAccess::class);
        $this->app->singleton(EPayPaymentStrategy::class);
        $this->app->singleton(CashPaymentStrategy::class);
        $this->app->singleton(PaymentStrategyFactory::class);
    }

    /**
     * Admin back-office services.
     */
    private function registerAdminServices(): void
    {
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
    }

    /**
     * Staff / employee services.
     */
    private function registerStaffServices(): void
    {
        // ── Staff / Employee Services ────────────────────────────────────────────
        $this->app->singleton(StaffJwtService::class);
        $this->app->singleton(EmployeeAuthService::class);
        $this->app->singleton(EmployeeManagementService::class);
        $this->app->singleton(ReviewModerationService::class);
        $this->app->singleton(StaffComplaintService::class);
    }

    /**
     * Wallet-request, notification and verification services.
     */
    private function registerSupportServices(): void
    {
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
    }

    /**
     * Repository interface bindings. The ComplaintService singleton that sat between
     * them in the original body stays here so the sequence is unchanged.
     */
    private function registerRepositories(): void
    {
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
    }

    /**
     * OTP services (special binding).
     */
    /**
     * Batch 227 ports: the interfaces the Repository and Domain layers may depend on, each bound
     * to the singleton that already implemented the behaviour. Nothing about the implementations
     * changed - these bindings only let the container hand the same instance to a port-typed
     * constructor.
     */
    private function registerBoundaryPorts(): void
    {
        $this->app->bind(TokenRevokerInterface::class, JwtService::class);
        $this->app->bind(GeocodingServiceInterface::class, GeocodingService::class);
        $this->app->bind(RouteCalculationServiceInterface::class, RouteCalculationService::class);
        $this->app->bind(BookingChargeInterface::class, BookingCharge::class);
        $this->app->bind(RidePayoutInterface::class, RidePayout::class);
        $this->app->bind(CancellationRefundInterface::class, CancellationRefund::class);
        // Batch 233 port: the four other contexts notify through the interface; the singleton
        // above is still the implementation, so instance identity and construction are unchanged.
        $this->app->bind(NotificationPort::class, NotificationService::class);
        // Batch 233 step (b): the ride services reach the money context only through these.
        $this->app->bind(NoShowSettlementInterface::class, NoShowSettlement::class);
        $this->app->bind(CashRideFeeServiceInterface::class, CashRideFeeService::class);
        $this->app->bind(PaymentStrategyFactoryInterface::class, PaymentStrategyFactory::class);
        // Batch 233 step (c): the remaining cross-context callers reach context code only here.
        $this->app->bind(DocumentVerificationInterface::class, DocumentVerificationService::class);
        $this->app->bind(ScoreServiceInterface::class, ScoreService::class);
        $this->app->bind(UserRideStatsInterface::class, UserRideStatsService::class);
        $this->app->bind(LedgerServiceInterface::class, LedgerService::class);
        $this->app->bind(OtpMailInterface::class, OtpMailer::class);
        $this->app->bind(EmployeeManagementInterface::class, EmployeeManagementService::class);
        $this->app->bind(SupportAgentRoutingInterface::class, SupportAgentRoutingService::class);
        $this->app->bind(VerificationApprovalInterface::class, VerificationApprovalService::class);
        $this->app->bind(StaffJwtInterface::class, StaffJwtService::class);
        $this->app->bind(ChatMessageHandlerInterface::class, ChatMessageHandler::class);
        $this->app->bind(PassengerWalletChargerInterface::class, PassengerWalletCharger::class);
    }

    private function registerOtpServices(): void
    {
        // ========================================
        // OTP SERVICES (Special binding)
        // ========================================
        $this->app->bind(TextMeBotOtpService::class, function ($app) {
            return new TextMeBotOtpService($app->make(OtpRepositoryInterface::class));
        });
    }
}
