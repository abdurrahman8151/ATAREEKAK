<?php

namespace Tests\Unit\Providers;

use App\Interfaces\ChatRepositoryInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\VerificationRepositoryInterface;
use App\Providers\AppServiceProvider;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminWalletService;
use App\Services\Geocoding\ArabicPlaceNameService;
use App\Services\Geocoding\GeocodingService;
use App\Services\NotificationService;
use App\Services\PushNotification\FcmSenderService;
use App\Services\PushNotification\PushNotificationService;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            UserRepositoryInterface::class,
            $this->app->make(UserRepositoryInterface::class)
        );
    }

    public function test_profile_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            ProfileRepositoryInterface::class,
            $this->app->make(ProfileRepositoryInterface::class)
        );
    }

    public function test_otp_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            OtpRepositoryInterface::class,
            $this->app->make(OtpRepositoryInterface::class)
        );
    }

    public function test_photo_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            PhotoRepositoryInterface::class,
            $this->app->make(PhotoRepositoryInterface::class)
        );
    }

    public function test_chat_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            ChatRepositoryInterface::class,
            $this->app->make(ChatRepositoryInterface::class)
        );
    }

    public function test_verification_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            VerificationRepositoryInterface::class,
            $this->app->make(VerificationRepositoryInterface::class)
        );
    }

    public function test_password_reset_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            PasswordResetRepositoryInterface::class,
            $this->app->make(PasswordResetRepositoryInterface::class)
        );
    }

    public function test_ride_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            RideRepositoryInterface::class,
            $this->app->make(RideRepositoryInterface::class)
        );
    }

    public function test_geocoding_service_is_singleton(): void
    {
        $a = $this->app->make(GeocodingService::class);
        $b = $this->app->make(GeocodingService::class);
        $this->assertSame($a, $b);
    }

    public function test_arabic_place_name_service_is_singleton(): void
    {
        $a = $this->app->make(ArabicPlaceNameService::class);
        $b = $this->app->make(ArabicPlaceNameService::class);
        $this->assertSame($a, $b);
    }

    public function test_push_notification_service_is_singleton(): void
    {
        $a = $this->app->make(PushNotificationService::class);
        $b = $this->app->make(PushNotificationService::class);
        $this->assertSame($a, $b);
    }

    public function test_fcm_sender_service_is_singleton(): void
    {
        $a = $this->app->make(FcmSenderService::class);
        $b = $this->app->make(FcmSenderService::class);
        $this->assertSame($a, $b);
    }

    public function test_ride_service_is_singleton(): void
    {
        $a = $this->app->make(RideService::class);
        $b = $this->app->make(RideService::class);
        $this->assertSame($a, $b);
    }

    public function test_booking_service_is_singleton(): void
    {
        $a = $this->app->make(BookingService::class);
        $b = $this->app->make(BookingService::class);
        $this->assertSame($a, $b);
    }

    public function test_admin_auth_service_is_singleton(): void
    {
        $a = $this->app->make(AdminAuthService::class);
        $b = $this->app->make(AdminAuthService::class);
        $this->assertSame($a, $b);
    }

    public function test_admin_wallet_service_is_singleton(): void
    {
        $a = $this->app->make(AdminWalletService::class);
        $b = $this->app->make(AdminWalletService::class);
        $this->assertSame($a, $b);
    }

    public function test_notification_service_is_singleton(): void
    {
        $a = $this->app->make(NotificationService::class);
        $b = $this->app->make(NotificationService::class);
        $this->assertSame($a, $b);
    }

    public function test_provider_is_registered(): void
    {
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $this->assertNotNull($provider);
        $this->assertInstanceOf(AppServiceProvider::class, $provider);
    }
}
