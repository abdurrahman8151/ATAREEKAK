<?php

namespace Tests\Unit\Providers;

use App\Events\RideBooked;
use App\Events\UserVerified;
use App\Listeners\SendUserVerifiedNotification;
use App\Models\User;
use App\Providers\EventServiceProvider;
use App\Services\NotificationService;
use Tests\TestCase;

/**
 * AF-4 (app-future audit): rewritten. This suite previously asserted a
 * four-event listener map that was mostly dead scaffolding — listeners with
 * empty handle(), or bound to events the app never fires as `event()` (they
 * are broadcast directly), while the real notifications happen inline in the
 * services. The map now contains the one chain that is genuinely wired AND
 * functional (UserVerified -> SendUserVerifiedNotification), and the tests
 * assert that truth plus the DELETIONS that closed the fake-assurance gap.
 */
class EventServiceProviderTest extends TestCase
{
    public function test_event_service_provider_is_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);

        $this->assertNotNull($provider);
        $this->assertInstanceOf(EventServiceProvider::class, $provider);
    }

    public function test_auto_discovery_is_disabled(): void
    {
        $provider = new EventServiceProvider($this->app);
        $this->assertFalse($provider->shouldDiscoverEvents());
    }

    public function test_user_verified_event_is_mapped_to_its_listener(): void
    {
        $listen = $this->listenMap();

        $this->assertArrayHasKey(UserVerified::class, $listen);
        $this->assertContains(SendUserVerifiedNotification::class, $listen[UserVerified::class]);
    }

    public function test_the_listener_map_no_longer_claims_dead_chains(): void
    {
        $listen = $this->listenMap();

        // RideBooked/RideCancelled still BROADCAST (verified elsewhere), but the
        // app notifies those parties inline; the empty queued listeners and
        // their map entries were removed under AF-4. A single wired chain remains.
        $this->assertArrayNotHasKey(RideBooked::class, $listen);
        $this->assertCount(1, $listen);
    }

    /** The deleted listener classes must stay gone, not silently revived. */
    public function test_the_dead_listeners_are_deleted(): void
    {
        foreach ([
            'app/Listeners/SendMessageNotification.php',
            'app/Listeners/SendRideBookedNotification.php',
            'app/Listeners/SendRideCancelledNotification.php',
            'app/Listeners/SendOtpNotification.php',
            'app/Jobs/SendScheduledNotification.php',
            'app/Events/MessageReceived.php',
            'app/Events/OtpSent.php',
            'app/Events/ConversationCreated.php',
        ] as $rel) {
            $this->assertFileDoesNotExist(base_path($rel), "$rel was deleted for being dead; keep it gone");
        }
    }

    /**
     * The whole point of wiring UserVerified: an APPROVED user must now receive
     * an in-app notification, mirroring what the REJECT path already did inline.
     * Before AF-4 the listener handle() was empty, so approval was silent.
     */
    public function test_listener_actually_creates_a_notification_for_the_user(): void
    {
        $user = User::factory()->create();

        $listener = new SendUserVerifiedNotification(
            app(NotificationService::class)
        );
        $listener->handle(new UserVerified($user, 'driver'));

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'verification_approved',
        ]);
    }

    private function listenMap(): array
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);

        return (new \ReflectionProperty($provider, 'listen'))->getValue($provider);
    }
}
