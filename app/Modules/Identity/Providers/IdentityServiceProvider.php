<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Contracts\DocumentVerificationInterface;
use App\Modules\Identity\Contracts\EmailOtpServiceInterface;
use App\Modules\Identity\Contracts\EmployeeAuthInterface;
use App\Modules\Identity\Contracts\OtpMailInterface;
use App\Modules\Identity\Contracts\OtpRepositoryInterface;
use App\Modules\Identity\Contracts\PasswordResetRepositoryInterface;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileRepositoryInterface;
use App\Modules\Identity\Contracts\StaffJwtInterface;
use App\Modules\Identity\Contracts\StaffTokenCleanupInterface;
use App\Modules\Identity\Contracts\StaffTokenVerifierInterface;
use App\Modules\Identity\Contracts\TokenRevokerInterface;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Identity\Contracts\UserRatingRecorder;
use App\Modules\Identity\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Contracts\UserTokenCleanupInterface;
use App\Modules\Identity\Contracts\UserTokenVerifierInterface;
use App\Modules\Identity\Contracts\VerificationApprovalInterface;
use App\Modules\Identity\Contracts\VerificationRepositoryInterface;
use App\Modules\Identity\Repositories\OtpRepository;
use App\Modules\Identity\Repositories\PasswordResetRepository;
use App\Modules\Identity\Repositories\PhotoRepository;
use App\Modules\Identity\Repositories\ProfileRepository;
use App\Modules\Identity\Repositories\UserRepository;
use App\Modules\Identity\Repositories\VerificationRepository;
use App\Modules\Identity\Services\DocumentVerificationService;
use App\Modules\Identity\Services\EmailOtpService;
use App\Modules\Identity\Services\EmployeeAuthService;
use App\Modules\Identity\Services\JwtService;
use App\Modules\Identity\Services\ProfileInteractionService;
use App\Modules\Identity\Services\ProfileUpdateService;
use App\Modules\Identity\Services\StaffJwtService;
use App\Modules\Identity\Services\TextMeBotOtpService;
use App\Modules\Identity\Services\UserDirectoryReader;
use App\Modules\Identity\Services\UserRatingReaderService;
use App\Modules\Identity\Services\UserRatingRecorderService;
use App\Modules\Identity\Services\VerificationApprovalService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Identity module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes unchanged.
 *
 * Phase 2 moved the implementations into this module (`JwtService`, `StaffJwtService`,
 * `EmployeeAuthService`, the OTP services, the verification services), so the only implementation
 * that serves this module from outside `Modules/Identity` is the kernel mailer: `OtpMailInterface`
 * is implemented by `App\Mail\OtpMailer`. Its binding used to live in the Communication provider
 * (mail delivery is that context's transport), which made Communication import this module's
 * Contracts - an edge, and with this module's own `NotificationPort` use, a cycle. Stage 1.5 item 4
 * moved the binding to the kernel catalogue `App\Support\ContainerBindings`: the kernel owns the
 * mailer it implements, binding a kernel class to a module port is the kernel's own wiring, and
 * binding it THERE leaves both module providers free of each other's Contracts. The staff door
 * reaches this module through `EmployeeAuthInterface` and `StaffJwtInterface`, never a class.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProfileUpdateService::class);
        $this->app->singleton(ProfileInteractionService::class);
        $this->app->singleton(StaffJwtService::class);
        $this->app->singleton(EmployeeAuthService::class);
        $this->app->bind(EmployeeAuthInterface::class, EmployeeAuthService::class);
        $this->app->singleton(DocumentVerificationService::class);

        // The one binding that needs a closure: the TextMeBot OTP driver takes the OTP repository.
        $this->app->bind(TextMeBotOtpService::class, function ($app) {
            return new TextMeBotOtpService($app->make(OtpRepositoryInterface::class));
        });

        // Repositories and ports.
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        // Stage 2 C3: other modules read `users` through this, never through a query of their own.
        $this->app->bind(UserDirectory::class, UserDirectoryReader::class);
        $this->app->bind(UserRatingReader::class, UserRatingReaderService::class);
        $this->app->bind(UserRatingRecorder::class, UserRatingRecorderService::class);
        $this->app->bind(ProfileRepositoryInterface::class, ProfileRepository::class);
        $this->app->bind(EmailOtpServiceInterface::class, EmailOtpService::class);
        $this->app->bind(OtpRepositoryInterface::class, OtpRepository::class);
        $this->app->bind(PhotoRepositoryInterface::class, PhotoRepository::class);
        $this->app->bind(VerificationRepositoryInterface::class, VerificationRepository::class);
        $this->app->bind(PasswordResetRepositoryInterface::class, PasswordResetRepository::class);
        $this->app->bind(TokenRevokerInterface::class, JwtService::class);
        $this->app->bind(UserTokenCleanupInterface::class, JwtService::class);
        $this->app->bind(UserTokenVerifierInterface::class, JwtService::class);
        $this->app->bind(StaffTokenCleanupInterface::class, StaffJwtService::class);
        $this->app->bind(StaffTokenVerifierInterface::class, StaffJwtService::class);
        $this->app->bind(DocumentVerificationInterface::class, DocumentVerificationService::class);
        $this->app->bind(VerificationApprovalInterface::class, VerificationApprovalService::class);
        $this->app->bind(StaffJwtInterface::class, StaffJwtService::class);
    }
}
