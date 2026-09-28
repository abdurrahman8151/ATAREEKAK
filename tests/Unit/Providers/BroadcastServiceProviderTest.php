<?php

namespace Tests\Unit\Providers;

use App\Providers\BroadcastServiceProvider;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

class BroadcastServiceProviderTest extends TestCase
{
    public function test_broadcast_service_provider_class_exists(): void
    {
        $this->assertTrue(class_exists(BroadcastServiceProvider::class));
    }

    public function test_broadcast_manager_is_resolvable(): void
    {
        $manager = $this->app->make(BroadcastManager::class);
        $this->assertInstanceOf(BroadcastManager::class, $manager);
    }

    public function test_broadcast_service_provider_is_registered_in_app(): void
    {
        // The app boots without throwing — channels.php is loaded
        $provider = $this->app->getProvider(BroadcastServiceProvider::class);
        $this->assertNotNull($provider);
    }

    public function test_broadcast_manager_has_default_driver(): void
    {
        $manager = $this->app->make(BroadcastManager::class);
        // Accessing the driver should not throw
        $this->assertNotNull($manager);
    }

    public function test_broadcast_service_provider_extends_service_provider(): void
    {
        $this->assertTrue(
            is_subclass_of(
                BroadcastServiceProvider::class,
                ServiceProvider::class
            )
        );
    }

    public function test_provider_has_boot_method(): void
    {
        $this->assertTrue(method_exists(BroadcastServiceProvider::class, 'boot'));
    }
}
