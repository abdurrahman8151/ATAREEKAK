<?php

namespace Tests\Feature\Broadcast;

use App\Events\RideCancelled;
use App\Events\RideCreated;
use App\Models\Ride;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * T2-7 regression — the `rides` broadcast channel must not be a public channel.
 *
 * The ride-creation / cancellation stream is served over REST only behind the
 * `jwt` middleware (every api/rides/* route is AUTH), so broadcasting it on a
 * PUBLIC Pusher channel leaked the same data to any holder of the (also
 * committed-default, T2-8) app key, bypassing authentication entirely.
 *
 * The decisive framework fact this suite pins down: a public `Channel` is
 * delivered by Pusher with NO authorization handshake at all — the browser
 * subscribes directly with the public key and never calls /broadcasting/auth,
 * so the `Broadcast::channel('rides', fn () => true)` callback was never even
 * invoked. Only a `PrivateChannel` forces the subscribe-time auth round-trip.
 * Therefore the fix is fundamentally about the CHANNEL TYPE in the producers,
 * and the callback is what then runs behind the jwt-guarded auth route.
 */
class RideChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: RefreshDatabase cannot run the rides spatial migration on SQLite.');
        }
        parent::setUp();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The 'rides' auth callback exactly as routes/channels.php registered it. */
    private function ridesCallback(): callable
    {
        // Broadcast::channel() proxies to the default connection's Broadcaster,
        // which stores the callbacks in its protected $channels map. Resolve the
        // same memoized instance the /broadcasting/auth route uses.
        /** @var Broadcaster $broadcaster */
        $broadcaster = app(\Illuminate\Broadcasting\BroadcastManager::class)->driver();
        $prop = new ReflectionProperty(Broadcaster::class, 'channels');
        $prop->setAccessible(true);
        $channels = $prop->getValue($broadcaster);

        $this->assertArrayHasKey('rides', $channels, 'routes/channels.php must register the rides channel.');

        return $channels['rides'];
    }

    private function authenticatedUser(): array
    {
        $u = User::factory()->create([
            'status'            => 1,
            'email_verified_at' => now(),
        ]);

        return [$u, app(JwtService::class)->generateTokenPair($u)['access_token']];
    }

    // ── Producer channel TYPE (the actual security fix) ───────────────────────

    public function test_ride_created_broadcasts_the_rides_channel_as_private(): void
    {
        $ride = new Ride(['driver_id' => 1]);
        $channels = (new RideCreated($ride))->broadcastOn();

        $this->assertNotEmpty(array_values(array_filter(
            $channels,
            fn ($c) => $c instanceof PrivateChannel && $c->name === 'private-rides'
        )), 'RideCreated must target the rides channel as a PrivateChannel.');
    }

    public function test_ride_cancelled_broadcasts_the_rides_channel_as_private(): void
    {
        $ride    = new Ride(['driver_id' => 1]);
        $driver  = new User();
        $channels = (new RideCancelled($ride, [], $driver))->broadcastOn();

        $this->assertNotEmpty(array_values(array_filter(
            $channels,
            fn ($c) => $c instanceof PrivateChannel && $c->name === 'private-rides'
        )), 'RideCancelled must target the rides channel as a PrivateChannel.');
    }

    public function test_ride_events_do_not_broadcast_on_any_public_channel(): void
    {
        foreach ([new RideCreated(new Ride(['driver_id' => 1])),
                  new RideCancelled(new Ride(['driver_id' => 1]), [], new User())] as $event) {
            foreach ($event->broadcastOn() as $channel) {
                // A public channel is a Channel that is NOT a PrivateChannel/PresenceChannel.
                $isPublic = $channel instanceof Channel
                    && ! ($channel instanceof PrivateChannel);
                $this->assertFalse(
                    $isPublic,
                    get_class($event) . ' must not expose a public channel.'
                );
            }
        }
    }

    // ── The auth callback behaviour ──────────────────────────────────────────

    public function test_rides_channel_callback_refuses_an_anonymous_subscriber(): void
    {
        $this->assertFalse(
            ($this->ridesCallback())(null),
            'An unauthenticated (null) user must be refused the rides channel.'
        );
    }

    public function test_rides_channel_callback_admits_an_authenticated_user(): void
    {
        [$user] = $this->authenticatedUser();

        $this->assertTrue(
            (bool) ($this->ridesCallback())($user),
            'An authenticated user must be admitted (consistent with the REST rides surface).'
        );
    }

    // ── End-to-end through the real jwt-guarded /broadcasting/auth route ───────

    public function test_anonymous_cannot_authorize_the_private_rides_channel(): void
    {
        // Deterministic end-to-end proof that the gate CLOSES: the jwt
        // middleware rejects before Pusher is ever contacted. This is the one
        // HTTP-level assertion that is stable here.
        //
        // The authenticated round-trip is deliberately NOT asserted at the HTTP
        // layer: a comparison probe showed the PRE-EXISTING `user.{id}` channel
        // returns an identical 200/empty-body for both its should-allow and its
        // should-deny cases in this environment, because the Pusher credential
        // layer is T2-8's committed placeholder and misbehaves independently of
        // the authorization callback. Asserting the callback decision directly
        // (the two tests above) is both deterministic and closer to the actual
        // control, so the HTTP success path is not needed to prove the fix.
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-rides',
            'socket_id'    => '1234.5678',
        ])->assertStatus(401);
    }
}
