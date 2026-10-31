<?php

namespace Tests\Feature\Snapshots;

use App\Enums\StaffRole;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\AssertsApiSnapshot;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Base class for the route snapshot batches.
 *
 * A module batch (auth, rides, wallet, admin, ...) extends this, declares its cases in a static
 * `cases()` array and inherits ONE test method. That shape is deliberate: the case definition is
 * the only per-route code, so the same array is also the route inventory (see RouteInventoryTest)
 * and there is no way to add a case without it being listed.
 *
 * Every case runs inside RefreshDatabase, so one case cannot see another's rows. The standard
 * world is built in setUp and its ids are substituted into the URI, which keeps a case definition
 * to the things that actually differ: actor, uri, body.
 */
abstract class ApiSnapshotTestCase extends TestCase
{
    use ActsAsStaff;
    use AssertsApiSnapshot;
    use RefreshDatabase;
    use SeedsSystemWallets;

    protected User $driver;

    protected User $passenger;

    protected User $otherPassenger;

    /** A verified account with NO wallet, so the wallet-creation doors can be exercised. */
    protected User $walletless;

    protected Wallet $driverWallet;

    protected Wallet $passengerWallet;

    protected Wallet $otherWallet;

    protected Ride $ride;

    protected Booking $booking;

    protected string $driverToken;

    protected string $passengerToken;

    protected string $otherToken;

    protected string $walletlessToken;

    private ?Employee $adminEmployee = null;

    private ?Employee $staffEmployee = null;

    private ?Employee $staffAdminEmployee = null;

    private ?string $adminToken = null;

    private ?string $staffToken = null;

    private ?string $staffAdminToken = null;

    /**
     * Per-case values substituted into `{placeholders}`, set by a case's `prepare` hook (for
     * example a real refresh token minted before the measured call).
     *
     * @var array<string, string>
     */
    public array $caseVars = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeThirdPartyServices();
        $this->buildStandardWorld();
    }

    /**
     * Nothing in these snapshots may reach a third-party service.
     *
     * A fake catch-all is used rather than `preventStrayRequests` alone because many routes call
     * geocoding/route calculation on their normal path; with no stub the call would throw and the
     * snapshot would pin an infrastructure failure instead of the route's own behaviour. The
     * specific stubs come first so a route that actually parses a provider payload still gets a
     * realistic, deterministic one.
     */
    protected function fakeThirdPartyServices(): void
    {
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [[
                    'summary' => ['distance' => 320500.0, 'duration' => 14400.0],
                    'geometry' => 'encrypted-geometry',
                    'segments' => [['distance' => 320500.0, 'duration' => 14400.0]],
                ]],
            ], 200),
            'api.mapbox.com/*' => Http::response([
                'features' => [[
                    'place_name' => 'Damascus, Syria',
                    'center' => [36.2765, 33.5138],
                    'geometry' => ['coordinates' => [36.2765, 33.5138]],
                ]],
            ], 200),
            'nominatim.openstreetmap.org/*' => Http::response([[
                'display_name' => 'Damascus, Syria',
                'lat' => '33.5138',
                'lon' => '36.2765',
            ]], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1'], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function buildStandardWorld(): void
    {
        $this->seedSystemWallets(1_000_000);

        $this->driver = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Driver',
            'email' => 'snapshot.driver@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'M',
        ]);

        $this->passenger = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Passenger',
            'email' => 'snapshot.passenger@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'M',
        ]);

        $this->otherPassenger = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Other',
            'email' => 'snapshot.other@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'M',
        ]);

        $this->driverWallet = $this->walletFor($this->driver, '0900000001', 'SNAP-DRV-0001', 1_000_000);
        $this->passengerWallet = $this->walletFor($this->passenger, '0900000002', 'SNAP-PAX-0002', 1_000_000);
        $this->otherWallet = $this->walletFor($this->otherPassenger, '0900000003', 'SNAP-OTH-0003', 1_000_000);

        $this->walletless = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Walletless',
            'email' => 'snapshot.walletless@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'M',
        ]);

        $this->driverToken = $this->userToken($this->driver);
        $this->passengerToken = $this->userToken($this->passenger);
        $this->otherToken = $this->userToken($this->otherPassenger);
        $this->walletlessToken = $this->userToken($this->walletless);

        $this->ride = RideBuilder::for($this->driver)
            ->status('active')
            ->seats(4)
            ->price(50_000)
            ->departureTime(now()->addHours(3))
            ->withAttributes([
                'pickup_address' => 'Damascus',
                'destination_address' => 'Aleppo',
                'vehicle_type' => 'car',
                'booking_type' => 'direct',
                'payment_method' => 'e-pay',
                'communication_number' => '0911000000',
            ])
            ->create();

        $this->staggerWorldTimestamps();
    }

    /**
     * Give every row in the standard world a distinct `created_at`.
     *
     * The list endpoints (`/api/admin/users`, `/api/admin/wallets`, `/api/staff/users`) order by
     * `created_at`, and MySQL is free to return any order for rows that share a second. The world
     * is built inside one second, so those lists came back in a different order between the
     * generating run and the verifying run and the snapshot diffed on row ORDER rather than on
     * content. Explicit, well-separated timestamps remove the ambiguity without touching the app.
     */
    private function staggerWorldTimestamps(): void
    {
        $base = now()->subHour();

        $system = Wallet::whereNull('user_id')->orderBy('id')->get();

        foreach ($system as $index => $wallet) {
            $wallet->forceFill([
                'created_at' => $base->copy()->addSeconds($index),
                'updated_at' => $base->copy()->addSeconds($index),
            ])->save();
        }

        $users = [$this->driver, $this->passenger, $this->otherPassenger, $this->walletless];
        $wallets = [$this->driverWallet, $this->passengerWallet, $this->otherWallet];

        foreach ($users as $index => $user) {
            $stamp = $base->copy()->addMinutes(10 + $index);
            $user->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();
        }

        foreach ($wallets as $index => $wallet) {
            $stamp = $base->copy()->addMinutes(10 + $index);
            $wallet->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();
        }

        $this->ride->forceFill([
            'created_at' => $base->copy()->addMinutes(20),
            'updated_at' => $base->copy()->addMinutes(20),
        ])->save();
    }

    private function walletFor(User $user, string $phone, string $number, float $balance): Wallet
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => $phone,
            'wallet_number' => $number,
            'balance' => $balance,
        ]);

        $user->update(['wallet_id' => $wallet->id]);

        return $wallet;
    }

    /**
     * Book the standard ride as the standard passenger, through the REAL endpoint, so the booking
     * carries the escrow and money snapshot a later cancel/confirm/no-show route expects.
     */
    protected function bookStandardRide(int $seats = 1, ?Ride $ride = null): Booking
    {
        $ride ??= $this->ride;

        $response = $this->withToken($this->passengerToken)->postJson("/api/rides/{$ride->id}/book", [
            'seats' => $seats,
            'communication_number' => '0900000002',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not create its standard booking (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 500)
            );
        }

        $id = $response->json('data.id');

        return Booking::findOrFail((int) $id);
    }

    /**
     * A second ride whose booking_type is `request`, plus a booking on it that is waiting for the
     * driver's decision. The accept/reject doors only exist for that state; the standard ride is
     * `direct`, so its booking is confirmed the moment it is made.
     *
     * If the snapshot world ever stops accepting a request booking the case is red at its source,
     * which is the point - the alternative is a snapshot of a refusal nobody asked for.
     */
    protected function pendingRequestBooking(): Booking
    {
        $ride = RideBuilder::for($this->driver)
            ->status('active')
            ->seats(4)
            ->price(40_000)
            ->departureTime(now()->addHours(5))
            ->withAttributes([
                'pickup_address' => 'Damascus',
                'destination_address' => 'Homs',
                'vehicle_type' => 'car',
                'booking_type' => 'request',
                'payment_method' => 'e-pay',
                'communication_number' => '0911000000',
            ])
            ->create();

        $response = $this->withToken($this->otherToken)->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1,
            'communication_number' => '0900000003',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not create a pending request booking (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 500)
            );
        }

        $booking = Booking::findOrFail((int) $response->json('data.id'));
        $this->booking = $booking;
        $this->caseVars['{booking}'] = (string) $booking->id;

        return $booking;
    }

    /**
     * Create a complaint through the real endpoint and remember its id as {complaint}.
     */
    protected function createComplaint(): string
    {
        $response = $this->withToken($this->passengerToken)->postJson('/api/complaints', [
            'title' => 'Snapshot complaint',
            'description' => 'A deterministic complaint created by the snapshot batch.',
            'type' => 'technical_issue',
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not create a complaint (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        // The store door nests the row under data.complaint, unlike the index which returns a
        // flat data list. Reading data.id here silently produced an EMPTY placeholder, and an
        // empty id made /api/staff/complaints//escalate 404 - the classic way a snapshot ends up
        // pinning the wrong route.
        $this->caseVars['{complaint}'] = (string) $response->json('complaint.id');

        if ($this->caseVars['{complaint}'] === '') {
            $this->fail('Snapshot world could not read the new complaint id: '.substr((string) $response->getContent(), 0, 400));
        }

        return $this->caseVars['{complaint}'];
    }

    /**
     * Move the standard complaint into the escalated queue, through the staff door, and remember
     * its id as {complaint}.
     */
    protected function escalateComplaint(): string
    {
        $id = $this->createComplaint();

        $response = $this->withToken($this->staffToken())->patchJson(
            "/api/staff/complaints/{$id}/escalate",
            ['reason' => 'The snapshot batch escalates this complaint for the queue test.']
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail(
                'Snapshot world could not escalate a complaint (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        return $id;
    }

    /**
     * Open the support conversation for the standard passenger through /api/contact and remember
     * its id as {conversation}. The staff actor is the agent the door routes to, so the staff chat
     * routes can read and reply to it.
     */
    protected function openSupportChat(): string
    {
        // The support door routes to the least-loaded ACTIVE agent, so the standard staff row has
        // to exist before the call - otherwise it correctly answers 503 and the case measures a
        // refusal instead of the conversation it is meant to pin.
        $this->staffEmployee();

        $response = $this->withToken($this->passengerToken)->postJson('/api/contact');

        if (! in_array($response->getStatusCode(), [200, 201], true)) {
            $this->fail(
                'Snapshot world could not open a support conversation (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $this->caseVars['{conversation}'] = (string) $response->json('conversation_id');

        if ($this->caseVars['{conversation}'] === '') {
            $this->fail('Snapshot world could not read the support conversation id: '.substr((string) $response->getContent(), 0, 400));
        }

        return $this->caseVars['{conversation}'];
    }

    /**
     * A rating/comment pair left by the standard passenger on the standard driver after a completed
     * ride, remembered as {comment}. The staff review queue is empty otherwise.
     */
    protected function createProfileComment(): string
    {
        $this->booking = $this->bookStandardRide();
        $this->completeStandardRide();

        $response = $this->withToken($this->passengerToken)->postJson(
            "/api/profile/{$this->driver->id}/comments",
            [
                'comment' => 'A calm and punctual driver.',
                'ride_id' => $this->ride->id,
            ]
        );

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not create a profile comment (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $this->caseVars['{comment}'] = (string) $response->json('data.id');

        return $this->caseVars['{comment}'];
    }

    /**
     * Put the `other` account into the pending-verification state the staff queue lists.
     */
    protected function markOtherPendingVerification(): void
    {
        $this->otherPassenger->update([
            'verification_status' => 'pending',
            'is_verified_passenger' => false,
            'is_verified_driver' => false,
        ]);
    }

    /**
     * The cases a module batch declares. Keyed by the snapshot case name; the value is the
     * definition consumed by sendSnapshotRequest() plus two optional hooks:
     *
     *   'with_booking' => true      build the standard booking (through the real book endpoint)
     *                               before the call, so {booking} is usable
     *   'prepare' => fn ($test)     any extra state the case needs
     *   'expect' => 200             the status the case must return; asserted in BOTH modes so a
     *                               generation run cannot bake an unexpected 500 into a snapshot
     *
     * @return array<string, array{method: string, uri: string, route: string, actor?: string|null, body?: array, expect?: int}>
     */
    abstract public static function cases(): array;

    /**
     * @return array<string, array{0: string}>
     */
    public static function snapshotCases(): array
    {
        $names = array_keys(static::cases());

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    #[DataProvider('snapshotCases')]
    public function test_route_snapshot(string $case): void
    {
        $definition = static::cases()[$case];

        if ($definition['with_booking'] ?? false) {
            $this->booking = $this->bookStandardRide($definition['booking_seats'] ?? 1);
        }

        if (isset($definition['prepare'])) {
            ($definition['prepare'])($this);
        }

        $response = $this->snapshotCall(
            $case,
            fn (): TestResponse => $this->sendSnapshotRequest($definition),
            $definition['expect'] ?? null
        );

        if (isset($definition['expect'])) {
            $this->assertSame(
                $definition['expect'],
                $response->getStatusCode(),
                "Snapshot case '{$case}' returned the wrong status. Body: ".substr((string) $response->getContent(), 0, 600)
            );
        }
    }

    /**
     * @param  array{actor?: string|null, method: string, uri: string, body?: array, files?: array, headers?: array}  $case
     */
    protected function sendSnapshotRequest(array $case): TestResponse
    {
        $method = strtoupper($case['method']);
        $uri = $this->resolvePlaceholders($case['uri']);
        $rawBody = $case['body'] ?? [];
        $body = $rawBody instanceof \Closure
            ? $this->resolvePlaceholders($rawBody($this))
            : $this->resolvePlaceholders($rawBody);
        $headers = $case['headers'] ?? [];

        $actor = $case['actor'] ?? null;
        $request = $actor === null ? $this : $this->withToken($this->tokenFor($actor));

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        return match ($method) {
            'GET' => $request->getJson($uri),
            'POST' => isset($case['files'])
                // post() (not call()) is what carries the default headers for a multipart body:
                // call() ignores $defaultHeaders, so the Bearer token vanished and every upload
                // case answered 401 TOKEN_MISSING.
                ? $request->post($uri, array_merge($body, $case['files']))
                : $request->postJson($uri, $body),
            'PUT' => $request->putJson($uri, $body),
            'PATCH' => $request->patchJson($uri, $body),
            'DELETE' => $request->deleteJson($uri, $body),
            default => $this->fail("Unsupported snapshot method: {$method}"),
        };
    }

    protected function tokenFor(string $actor): string
    {
        return match ($actor) {
            'driver' => $this->driverToken,
            'passenger' => $this->passengerToken,
            'other' => $this->otherToken,
            'walletless' => $this->walletlessToken,
            'admin' => $this->adminToken(),
            'staff' => $this->staffToken(),
            'staff_admin' => $this->staffAdminToken(),
            default => $this->fail("Unknown snapshot actor: {$actor}"),
        };
    }

    private function adminToken(): string
    {
        if ($this->adminToken === null) {
            $employee = $this->adminEmployee();

            $this->adminToken = (string) $this->postJson('/api/admin/login', [
                'username' => $employee->username,
                'password' => 'snapshot-password',
            ])->json('tokens.access_token');

            if ($this->adminToken === '') {
                $this->fail('Snapshot world could not obtain an admin token.');
            }
        }

        return $this->adminToken;
    }

    private function staffToken(): string
    {
        if ($this->staffToken === null) {
            $employee = $this->staffEmployee();

            $this->staffToken = (string) $this->postJson('/api/staff/login', [
                'identifier' => $employee->username,
                'password' => 'snapshot-password',
            ])->json('tokens.access_token');

            if ($this->staffToken === '') {
                $this->fail('Snapshot world could not obtain a staff token.');
            }
        }

        return $this->staffToken;
    }

    /**
     * A staff-panel employee with the ADMIN role. The support agent above is refused by every
     * route that carries `staff:admin,system_admin` (cancel a trip, resolve an escalation, approve
     * a verification), so those doors need their own actor.
     */
    private function staffAdminToken(): string
    {
        if ($this->staffAdminToken === null) {
            $employee = $this->staffAdminEmployee();

            $this->staffAdminToken = (string) $this->postJson('/api/staff/login', [
                'identifier' => $employee->username,
                'password' => 'snapshot-password',
            ])->json('tokens.access_token');

            if ($this->staffAdminToken === '') {
                $this->fail('Snapshot world could not obtain a staff-admin token.');
            }
        }

        return $this->staffAdminToken;
    }

    /**
     * The single deterministic admin employee. Idempotent so a case may ask for it in `prepare`
     * and still use the `admin` actor.
     */
    public function adminEmployee(): Employee
    {
        return $this->adminEmployee ??= Employee::firstOrCreate(
            ['username' => 'snapshot_admin'],
            [
                'email' => 'snapshot.admin@example.test',
                'password' => 'snapshot-password',
                'first_name' => 'Snapshot',
                'last_name' => 'Admin',
                'role' => StaffRole::SYSTEM_ADMIN->value,
                'is_active' => true,
                'token_version' => 0,
            ]
        );
    }

    public function staffEmployee(): Employee
    {
        return $this->staffEmployee ??= Employee::firstOrCreate(
            ['username' => 'snapshot_staff'],
            [
                'email' => 'snapshot.staff@example.test',
                'password' => 'snapshot-password',
                'first_name' => 'Snapshot',
                'last_name' => 'Staff',
                'role' => StaffRole::SUPPORT_AGENT->value,
                'is_active' => true,
                'token_version' => 0,
            ]
        );
    }

    /**
     * A staff-panel employee with the ADMIN role. The support agent above is refused by every
     * route that carries `staff:admin,system_admin` (cancel a trip, resolve an escalation, approve
     * a verification), so those doors need their own actor.
     */
    public function staffAdminEmployee(): Employee
    {
        return $this->staffAdminEmployee ??= Employee::firstOrCreate(
            ['username' => 'snapshot_staff_admin'],
            [
                'email' => 'snapshot.staff.admin@example.test',
                'password' => 'snapshot-password',
                'first_name' => 'Snapshot',
                'last_name' => 'StaffAdmin',
                'role' => StaffRole::ADMIN->value,
                'is_active' => true,
                'token_version' => 0,
            ]
        );
    }

    /**
     * Real refresh token for an actor, minted through the real door. Used by `prepare` so the
     * login POST is NOT inside the measured call.
     */
    protected function loginForRefreshToken(string $email, string $password = 'snapshot-password'): string
    {
        return (string) $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->json('tokens.refresh_token');
    }

    protected function adminRefreshToken(): string
    {
        return (string) $this->postJson('/api/admin/login', [
            'username' => $this->adminEmployee()->username,
            'password' => 'snapshot-password',
        ])->json('tokens.refresh_token');
    }

    protected function staffRefreshToken(): string
    {
        return (string) $this->postJson('/api/staff/login', [
            'identifier' => $this->staffEmployee()->username,
            'password' => 'snapshot-password',
        ])->json('tokens.refresh_token');
    }

    /**
     * Create a wallet request through the real endpoint and return its id. Used by cases that
     * read or cancel an EXISTING request, so the id is a fact of the database rather than a guess.
     */
    protected function createWalletRequest(string $type, float $amount): string
    {
        $response = $this->withToken($this->passengerToken)->postJson('/api/wallet/requests', [
            'type' => $type,
            'amount' => $amount,
            'notes' => 'snapshot prepare',
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not create a wallet request (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $id = (string) $response->json('data.id');

        if ($id === '') {
            $this->fail('Snapshot world could not read the wallet request id: '.substr((string) $response->getContent(), 0, 400));
        }

        // Exposed as {request} so the admin approve/reject cases can address the row this world
        // created instead of an id they guessed.
        $this->caseVars['{request}'] = $id;

        return $id;
    }

    /** Ban the other account through the admin door, so the unban case has something to lift. */
    protected function banOtherAccount(): void
    {
        $response = $this->withToken($this->adminToken())->postJson(
            '/api/admin/users/'.$this->otherPassenger->id.'/ban',
            [
                'reason' => 'The snapshot batch bans this account.',
                'type' => 'permanent',
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail(
                'Snapshot world could not ban the other account (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }
    }

    /**
     * Mark the standard ride finished and its booking completed, which is the state the rating
     * and comment doors require ("you can only rate a driver after completing a ride with them").
     */
    protected function completeStandardRide(): void
    {
        $this->booking->update([
            'status' => 'completed',
            'completed_at' => now(),
            'passenger_confirmed_at' => now(),
        ]);

        $this->ride->update([
            'status' => 'finished',
            'finished_at' => now(),
        ]);
    }

    /**
     * Remove any OTP row for an identity before an OTP case runs.
     *
     * The code under test branches on whether a row already exists (a stale row makes the verify
     * path do one more query and changes the message). Pinning that branch to "no row" is what
     * makes the case independent of whatever ran earlier in the process - the same class of
     * order dependence that produced the RV45 failures.
     */
    protected function clearOtpRows(string $identity): void
    {
        DB::table('otps')->where('phone_number', $identity)->delete();
    }

    /**
     * Put the four documents RV-53 requires on file for the standard driver, so the routes that
     * refuse an undocumented driver can be exercised on their success path.
     */
    protected function seedDriverDocuments(): void
    {
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::firstOrCreate(
                ['user_id' => $this->driver->id, 'type' => $type],
                ['path' => "documents/snapshot-{$type}.jpg"],
            );
        }
    }

    /**
     * Replace `{placeholders}` in a uri/body with the ids of the standard world.
     */
    private function resolvePlaceholders(array|string $value): array|string
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolvePlaceholders($item), $value);
        }

        $replacements = [
            '{driver}' => (string) $this->driver->id,
            '{passenger}' => (string) $this->passenger->id,
            '{other}' => (string) $this->otherPassenger->id,
            '{ride}' => (string) ($this->ride->id ?? 0),
            '{booking}' => (string) ($this->booking->id ?? 0),
            '{wallet}' => (string) $this->passengerWallet->id,
            '{driver_wallet}' => (string) $this->driverWallet->id,
        ];

        $all = $replacements + $this->caseVars;

        // A placeholder that resolves to nothing is worse than a red test: `/api/complaints//show`
        // and `/api/staff/complaints//escalate` silently match a DIFFERENT route (the index), and
        // the snapshot then pins that route's body under this route's name. Every `{name}` in the
        // value must therefore be a known placeholder with a non-empty value.
        if (is_string($value) && preg_match_all('/\{([a-z0-9_]+)\}/i', $value, $matches) > 0) {
            foreach ($matches[0] as $placeholder) {
                if (! isset($all[$placeholder]) || $all[$placeholder] === '') {
                    $this->fail(
                        "Unresolved placeholder {$placeholder} in '{$value}'. The case's prepare hook "
                        .'did not produce a value for it, and an empty id would make the request hit a '
                        .'different route than the one this snapshot names.'
                    );
                }
            }
        }

        return strtr($value, $all);
    }
}
