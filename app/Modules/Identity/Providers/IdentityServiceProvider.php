<?php

namespace App\Modules\Identity\Providers;

use App\Mail\OtpMailer;
use App\Modules\Identity\Contracts\DocumentVerificationInterface;
use App\Modules\Identity\Contracts\EmailOtpServiceInterface;
use App\Modules\Identity\Contracts\OtpMailInterface;
use App\Modules\Identity\Contracts\OtpRepositoryInterface;
use App\Modules\Identity\Contracts\PasswordResetRepositoryInterface;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileRepositoryInterface;
use App\Modules\Identity\Contracts\StaffJwtInterface;
use App\Modules\Identity\Contracts\TokenRevokerInterface;
use App\Modules\Identity\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Contracts\VerificationApprovalInterface;
use App\Modules\Identity\Contracts\VerificationRepositoryInterface;
use App\Repositories\OtpRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\PhotoRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VerificationRepository;
use App\Services\EmailOtpService;
use App\Services\JwtService;
use App\Services\Profile\ProfileInteractionService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\Staff\EmployeeAuthService;
use App\Services\Staff\StaffJwtService;
use App\Services\TextMeBotOtpService;
use App\Services\Verification\DocumentVerificationService;
use App\Services\Verification\VerificationApprovalService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Identity module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes unchanged.
 *
 * Two of the ports here are implemented by KERNEL classes that the layout keeps out of the modules:
 * `OtpMailInterface` is implemented by `App\Mail\OtpMailer` and `TokenRevokerInterface` by
 * `App\Services\JwtService`, which stays behind because the staff JWT path shares it. Their bindings
 * still belong to this module: the identity context is what consumes them.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProfileUpdateService::class);
        $this->app->singleton(ProfileInteractionService::class);
        $this->app->singleton(StaffJwtService::class);
        $this->app->singleton(EmployeeAuthService::class);
        $this->app->singleton(DocumentVerificationService::class);

        // The one binding that needs a closure: the TextMeBot OTP driver takes the OTP repository.
        $this->app->bind(TextMeBotOtpService::class, function ($app) {
            return new TextMeBotOtpService($app->make(OtpRepositoryInterface::class));
        });

        // Repositories and ports.
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(ProfileRepositoryInterface::class, ProfileRepository::class);
        $this->app->bind(EmailOtpServiceInterface::class, EmailOtpService::class);
        $this->app->bind(OtpRepositoryInterface::class, OtpRepository::class);
        $this->app->bind(PhotoRepositoryInterface::class, PhotoRepository::class);
        $this->app->bind(VerificationRepositoryInterface::class, VerificationRepository::class);
        $this->app->bind(PasswordResetRepositoryInterface::class, PasswordResetRepository::class);
        $this->app->bind(TokenRevokerInterface::class, JwtService::class);
        $this->app->bind(DocumentVerificationInterface::class, DocumentVerificationService::class);
        $this->app->bind(VerificationApprovalInterface::class, VerificationApprovalService::class);
        $this->app->bind(StaffJwtInterface::class, StaffJwtService::class);
        $this->app->bind(OtpMailInterface::class, OtpMailer::class);
    }
}
