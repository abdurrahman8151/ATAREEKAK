<?php

namespace Tests\Feature\Snapshots;

use App\Enums\StaffRole;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\ProfileComment;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Models\WalletTransaction;
use App\Services\Payment\PassengerWalletCharger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    /** Second approved driver: the driver lists need more than one real person. */
    private ?User $secondDriver = null;

    /** Third driver, waiting for verification: the verification queues need a pending row. */
    private ?User $thirdDriver = null;

    /** @var array<int, Ride> */
    private array $liveRides = [];

    /** @var array<int, Ride> trips departing tomorrow, the window rides/search asks for */
    private array $searchableRides = [];

    /** The account the seeded admin charges are recorded against. */
    private ?User $platformAdmin = null;

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

    /**
     * The instant every snapshot test pins the clock to.
     *
     * `cases()` runs as a data provider, BEFORE `setUp()` pins the clock, so a case definition that
     * writes `now()->addDay()` asks about the REAL calendar while the world it is compared against
     * was built at this instant - the two only agree on a lucky day. Case definitions must call
     * `self::snapshotNow()` instead.
     */
    public const PINNED_NOW = '2026-06-15 12:00:00';

    /** The pinned "now", for case definitions and fixtures that must agree with them. */
    public static function snapshotNow(): Carbon
    {
        return Carbon::parse(self::PINNED_NOW);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // THE CLOCK IS PINNED. Several routes bucket their data by "now" - the admin dashboard
        // answers with a six-month window ending in the current month, and two staff lists carry a
        // `joined_label` month name. The corpus was generated on 31 Oct 2026 and six cases went red
        // on 1 Nov for no reason other than the calendar; every month boundary would have done the
        // same. A snapshot may pin a window, but it must not pin WHICH window today is.
        Carbon::setTestNow(self::snapshotNow());

        $this->fakeThirdPartyServices();
        $this->buildStandardWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
            // Address-aware: before this, EVERY address resolved to Damascus, so a ride whose
            // destination is Aleppo stored Damascus coordinates and `/api/rides/search` could never
            // match a Damascus -> Aleppo ride (the destination leg was ~300 km off). The stub now
            // answers the two cities the snapshot world uses, which is what makes the search
            // endpoint's spatial filter a real filter instead of a coin flip.
            'nominatim.openstreetmap.org/*' => function ($request) {
                $query = mb_strtolower((string) ($request->data()['q'] ?? ''));

                $aleppo = str_contains($query, 'aleppo') || str_contains($query, 'حلب');

                return Http::response([[
                    'display_name' => $aleppo ? 'Aleppo, Syria' : 'Damascus, Syria',
                    'lat' => $aleppo ? '36.2021' : '33.5138',
                    'lon' => $aleppo ? '37.1343' : '36.2765',
                ]], 200);
            },
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

        $this->enrichWorld();
        $this->staggerWorldTimestamps();
    }

    /**
     * STAGE 0.5: the rows the list endpoints filter for.
     *
     * Before this, 33 snapshots pinned `[]` somewhere - an empty list looks identical whether the
     * filter is right or the fixture simply has no matching row, so those cases pinned nothing about
     * the query they were supposed to police. The world now carries what the screens need:
     *
     *   - a Profile and the four identity photos for every account with a profile/verification
     *     screen (this is what fills every `documents` collection, and what makes the verification
     *     queues and the admin activity feed non-empty);
     *   - two more drivers, one approved and one pending, so those lists have three DIFFERENT people;
     *   - three trips already on the road with a confirmed booking each (real book endpoint, so
     *     escrow and the wallets stay consistent), which fills the live-trips board, the recent
     *     bookings feed and the passenger's trip history;
     *   - three score transactions, three wallet requests, three profile comments the passenger
     *     RECEIVED and three the other passenger received, and three complaints by the passenger.
     */
    private function enrichWorld(): void
    {
        foreach ([$this->driver, $this->passenger, $this->otherPassenger, $this->walletless] as $user) {
            $this->seedProfileFor($user);
        }

        foreach ([$this->driver, $this->passenger, $this->otherPassenger] as $user) {
            $this->seedDocumentsFor($user);
        }

        $this->secondDriver = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'SecondDriver',
            'email' => 'snapshot.driver2@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'M',
        ]);

        $this->thirdDriver = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'PendingDriver',
            'email' => 'snapshot.driver3@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_driver' => false,
            'is_verified_passenger' => true,
            'verification_status' => 'pending',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'حلب',
            'gender' => 'M',
        ]);

        // Who the admin charges below are recorded against. A real admin account, not the passenger:
        // `wallet_transactions.user_id` is NOT NULL and the screen shows that user's name.
        $this->platformAdmin = User::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Admin',
            'email' => 'snapshot.admin@example.test',
            'password' => bcrypt('snapshot-password'),
            'is_verified_driver' => false,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'status' => 1,
            'email_verified_at' => now(),
            'address' => 'دمشق',
            'gender' => 'F',
        ]);

        $this->walletFor($this->secondDriver, '0900000011', 'SNAP-DRV-0011', 500_000);
        $this->walletFor($this->thirdDriver, '0900000012', 'SNAP-DRV-0012', 250_000);

        foreach ([$this->secondDriver, $this->thirdDriver] as $user) {
            $this->seedProfileFor($user);
            $this->seedDocumentsFor($user);
        }

        // Three scheduled Damascus -> Aleppo trips for TOMORROW, which is the window
        // `rides/search-get` and `rides/search-post` ask for. The three trips on the road below
        // depart in the past, so search correctly excludes them and these are the candidates.
        foreach ([9, 13, 17] as $index => $hour) {
            $this->searchableRides[] = RideBuilder::for($index === 1 ? $this->secondDriver : $this->driver)
                ->status('active')
                ->seats(3)
                ->price(45_000)
                ->departureTime(self::snapshotNow()->addDay()->setTime($hour, 0))
                ->withAttributes([
                    'pickup_address' => 'Damascus',
                    'destination_address' => 'Aleppo',
                    'vehicle_type' => 'car',
                    'booking_type' => 'direct',
                    'payment_method' => 'e-pay',
                    'communication_number' => '0911000000',
                ])
                ->create();
        }

        // Three admin charges on the passenger's wallet: the wallet-charges screens list
        // `wallet_transactions` of type `admin_charge`, and before this the list was `[]` in both
        // the admin and the passenger profile panels. Written through the SAME two service calls the
        // real endpoint makes, so the balance, the transaction row and the External Capital ledger
        // leg agree with each other - a hand-written transaction row would have moved the balance
        // without a ledger entry to explain it (decision un3).
        $charger = app(PassengerWalletCharger::class);

        foreach ([20_000, 35_000, 12_500] as $index => $amount) {
            $credit = $charger->creditWalletBalance($this->passenger, $amount);

            $this->stampRow(WalletTransaction::create([
                'wallet_id' => $credit['wallet']->id,
                'user_id' => $this->platformAdmin->id,
                'type' => 'admin_charge',
                'amount' => $amount,
                'previous_balance' => $credit['previous'],
                'new_balance' => $credit['current'],
                'description' => 'Admin wallet charge seeded by the snapshot world',
                'transaction_id' => 'ADM-'.$this->passenger->id.'-'.$index.'-snapshot',
                'status' => 'completed',
                'reference' => 'admin_charge:'.$this->passenger->id,
            ]), now()->subHours(12 - $index));

            $charger->injectExternalCapital($credit['wallet'], $this->passenger, $amount);
        }

        $charger->bustWalletChargeCaches($this->passenger->id);

        // Three trips on the road right now (status active, departure already passed). Each
        // booking goes through the real endpoint, so escrow_held, the wallet balances and the
        // ledger all agree with what the app would have written.
        $liveTrips = [
            ['destination' => 'Homs', 'minutes_ago' => 45, 'driver' => $this->driver, 'passenger' => $this->passenger],
            ['destination' => 'Latakia', 'minutes_ago' => 70, 'driver' => $this->secondDriver, 'passenger' => $this->otherPassenger],
            ['destination' => 'Hama', 'minutes_ago' => 95, 'driver' => $this->driver, 'passenger' => $this->thirdDriver],
        ];

        $thirdDriverToken = $this->userToken($this->thirdDriver);

        foreach ($liveTrips as $index => $trip) {
            $bookerToken = match ($index) {
                0 => $this->passengerToken,
                1 => $this->otherToken,
                default => $thirdDriverToken,
            };

            $ride = RideBuilder::for($trip['driver'])
                ->status('active')
                ->seats(3)
                ->price(5_000)
                ->departureTime(now()->subMinutes($trip['minutes_ago']))
                ->withAttributes([
                    'pickup_address' => 'Damascus',
                    'destination_address' => $trip['destination'],
                    'vehicle_type' => 'car',
                    'booking_type' => 'direct',
                    'payment_method' => 'e-pay',
                    'communication_number' => '0911000000',
                ])
                ->create();

            $response = $this->withToken($bookerToken)->postJson("/api/rides/{$ride->id}/book", [
                'seats' => 1,
                'communication_number' => $trip['passenger']->phone_number ?? '0911000000',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            if ($response->getStatusCode() !== 201) {
                $this->fail(
                    'Snapshot world could not book a live trip (HTTP '.$response->getStatusCode().'): '
                    .substr((string) $response->getContent(), 0, 400)
                );
            }

            $this->liveRides[] = $ride;
        }

        // A SECOND passenger on the Homs trip, and the reason is a determinism bug this step found
        // rather than a desire for more rows: with one booking each, Hama and Homs both sit at
        // trip_count 1 / total_passengers 1 / demand 25%, so `admin/routes-popular` orders two rows
        // that tie on EVERY sort key and MySQL returns them in whichever order it likes - an
        // isolated run pinned Homs first, a full run Hama first, so the case could never be green
        // twice. Two passengers on Homs breaks the tie with real data.
        $response = $this->withToken($this->otherToken)->postJson("/api/rides/{$this->liveRides[0]->id}/book", [
            'seats' => 1,
            'communication_number' => '0900000003',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not add the second Homs passenger (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        // Score history: three movements, so the history and the paginated transactions list are
        // populated. The action strings must be valid `ScoreAction` cases - the resource casts with
        // `ScoreAction::from()`, so an action the enum does not know is a 500, not an empty list.
        //
        // The references stay NULL deliberately, and the reason is a REAL DEFECT this fixture found
        // (parked for the owner, app/ is frozen): `ScoreTransactionResource::formatRide()` reads
        // `$ride->origin` / `$ride->destination`, which the Ride model does not have (it has
        // `pickup_address` / `destination_address`) and `Model::preventAccessingMissingAttributes()`
        // is on, so ANY score row that references a Ride throws MissingAttributeException and the
        // endpoint answers 500. `ScoreLedger::resolveReference()` writes exactly those references
        // for ride and booking events, so the branch is reachable in production. Referencing a
        // Booking is no better: `formatBooking()` reads `$booking->ride->origin` too. Seeding a
        // reference here would bake a 500 into the committed corpus, so the reference shape is left
        // for the owner's fix and the three rows pin the populated list, the points/score blocks and
        // the nullable reference instead.
        foreach ([
            ['action' => 'ride_completed', 'points' => 10, 'previous' => 70, 'new' => 80, 'reason' => 'Completed a trip'],
            ['action' => 'passenger_cancel_early', 'points' => 0, 'previous' => 80, 'new' => 80, 'reason' => 'Cancelled a booking in good time'],
            ['action' => 'passenger_no_show', 'points' => -15, 'previous' => 80, 'new' => 65, 'reason' => 'Did not show up for the trip'],
        ] as $index => $movement) {
            $this->stampRow(ScoreTransaction::create([
                'user_id' => $this->passenger->id,
                'action' => $movement['action'],
                'points' => $movement['points'],
                'previous_score' => $movement['previous'],
                'new_score' => $movement['new'],
                'reason' => $movement['reason'],
                'reference_type' => null,
                'reference_id' => null,
                'high_cancel_rate_applied' => false,
            ]), now()->subHours(3 - $index));
        }

        // Wallet requests: three rows the passenger, and the admin queue, can list. All three are
        // HISTORICAL (already decided) on purpose: the service correctly refuses a SECOND pending
        // request of the same type, so a pending row in the world would turn the real
        // `wallet/request-charge` and `wallet/requests-store` cases into 409s. A decided row is what
        // the list screen shows anyway, and the pending path is exercised by those two cases.
        foreach ([
            ['type' => 'charge', 'amount' => 75_000, 'status' => 'approved', 'hours_ago' => 30],
            ['type' => 'withdraw', 'amount' => 10_000, 'status' => 'rejected', 'hours_ago' => 54],
            ['type' => 'charge', 'amount' => 25_000, 'status' => 'cancelled', 'hours_ago' => 78],
        ] as $decided) {
            $this->stampRow(WalletRequest::create([
                'user_id' => $this->passenger->id,
                'wallet_id' => $this->passengerWallet->id,
                'type' => $decided['type'],
                'amount' => $decided['amount'],
                'status' => $decided['status'],
                'user_notes' => 'A decided request seeded by the snapshot world.',
            ]), now()->subHours($decided['hours_ago']));
        }

        // The rows above exist for the LISTS. A case that needs to address one row (the admin
        // approve/reject doors) creates its own in `prepare` and rebinds the placeholder, so the
        // world must not leave `{request}` pointing at a row those cases never meant to touch.
        unset($this->caseVars['{request}']);

        // Profile comments: three the passenger received, three the other passenger received.
        $this->seedProfileComments($this->passenger, [$this->driver, $this->otherPassenger, $this->secondDriver]);
        $this->seedProfileComments($this->otherPassenger, [$this->passenger, $this->driver, $this->secondDriver]);

        // Complaints: three by the passenger, one of each status the screens filter on, each with
        // one attachment so the nested `attachments` list is a real row and not `[]`. The stored
        // basename is a 38-character alphanumeric so the normaliser masks it to `<file>` and the
        // snapshot does not depend on the complaint id in the path.
        foreach ([
            ['title' => 'Snapshot ride issue', 'type' => 'trip_safety', 'status' => 'pending'],
            ['title' => 'Snapshot payment issue', 'type' => 'financial_issue', 'status' => 'in_review'],
            ['title' => 'Snapshot driver issue', 'type' => 'driver_behavior', 'status' => 'resolved'],
        ] as $index => $complaint) {
            $row = Complaint::create([
                'user_id' => $this->passenger->id,
                'title' => $complaint['title'],
                'description' => 'A deterministic complaint seeded by the snapshot world.',
                'type' => $complaint['type'],
                'status' => $complaint['status'],
            ]);

            $this->stampRow($row, now()->subHours(6 - $index));

            ComplaintAttachment::create([
                'complaint_id' => $row->id,
                'path' => 'complaints/'.$row->id.'/'.str_pad('snapshotattachment'.($index + 1), 38, '0'),
                'original_name' => 'snapshot-attachment.pdf',
                'mime_type' => 'application/pdf',
                'size' => 12_288,
            ]);
        }
    }

    /**
     * Set `created_at` on a row whose fillable list (correctly) does not cover timestamps, so the
     * lists that order by it have one fixed order instead of insert timing.
     */
    private function stampRow(Model $row, Carbon $at): void
    {
        $row->forceFill(['created_at' => $at])->save();
    }

    /** One Profile row per account, with a deterministic photo path. */
    private function seedProfileFor(User $user): Profile
    {
        return Profile::firstOrCreate(
            ['user_id' => $user->id],
            [
                // Named after the fixture's E-MAIL LOCAL PART, never the row id: an id is
                // auto-increment and moves with the suite's own history, so a filename carrying it
                // rewrote the snapshot whenever the counters drifted (it had already leaked 733, 206
                // and 78 into three different files).
                'profile_photo' => 'profiles/snapshot-'.$this->fileSuffixFor($user).'.jpg',
                'full_name' => trim("{$user->first_name} {$user->last_name}"),
                'address' => $user->address,
                'gender' => $user->gender,
                'number_of_rides' => 0,
            ]
        );
    }

    /**
     * A stable, human-readable suffix for a fixture account: `snapshot.driver@example.test` ->
     * `driver`. The e-mail is a fixture constant, so the stored filename is the same in every run.
     */
    private function fileSuffixFor(User $user): string
    {
        $local = (string) strstr((string) $user->email, '@', true);

        return $local === '' ? 'anonymous' : (string) preg_replace('#[^a-z0-9]+#i', '-', substr($local, strlen('snapshot.')));
    }

    /**
     * The four identity photos the verification screens show. `type` is an enum column, so the
     * list is closed: face_id, back_id, license, mechanic_card.
     *
     * The stored name is stable for the same reason as the profile photo: the account's fixture
     * name, not the row id.
     */
    private function seedDocumentsFor(User $user): void
    {
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::firstOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['path' => "documents/snapshot-{$type}-{$this->fileSuffixFor($user)}.jpg"],
            );
        }
    }

    /**
     * Comments RECEIVED by one account, written by the others.
     *
     * @param  array<int, User>  $authors
     */
    private function seedProfileComments(User $target, array $authors): void
    {
        $profile = $this->seedProfileFor($target);

        foreach (array_values($authors) as $index => $author) {
            if ($author->id === $target->id) {
                continue;
            }

            $this->stampRow(ProfileComment::create([
                'profile_id' => $profile->id,
                'user_id' => $author->id,
                'comment' => 'Snapshot comment number '.($index + 1).' for '.$target->first_name.'.',
                'ride_id' => $this->ride->id,
            ]), now()->subMinutes(30 - $index * 5));
        }
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

        $users = [
            $this->driver, $this->passenger, $this->otherPassenger, $this->walletless,
            $this->secondDriver, $this->thirdDriver, $this->platformAdmin,
        ];
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

        // The live trips, the trips scheduled for tomorrow and the accounts added by enrichWorld()
        // join the same ladder, so every list that orders by created_at has one unambiguous order
        // regardless of insert timing.
        foreach (array_merge($this->liveRides, $this->searchableRides) as $index => $ride) {
            $stamp = $base->copy()->addMinutes(21 + $index);
            $ride->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();

            foreach (Booking::where('ride_id', $ride->id)->orderBy('id')->get() as $offset => $booking) {
                $booking->forceFill([
                    'created_at' => $stamp->copy()->addSeconds($offset),
                    'updated_at' => $stamp->copy()->addSeconds($offset),
                ])->save();
            }
        }
    }

    /**
     * Tables whose `created_at` this API orders lists by, so a tie is a coin flip.
     *
     * Auth and infrastructure tables are deliberately absent: otps/password_reset_tokens/
     * refresh_tokens/personal_access_tokens drive expiry windows, and jobs/failed_jobs are queue
     * bookkeeping nothing here reads.
     */
    private const LADDERED_TABLES = [
        'bookings',
        'complaint_attachments',
        'complaints',
        'conversation_participants',
        'conversations',
        'employees',
        'messages',
        'noshow_reports',
        'notifications',
        'photos',
        'profile_comments',
        'profiles',
        'rides',
        'score_transactions',
        'user_notifications',
        'user_ratings',
        'user_scores',
        'users',
        'wallet_requests',
        'wallet_transactions',
        'wallets',
    ];

    /**
     * Give EVERY row a distinct `created_at` (and `updated_at`), ordered by id.
     *
     * MySQL returns rows that share a second in whatever order it likes. The whole world is built
     * inside one pinned second, so two runs of the SAME green test could order a list differently
     * - and with it every `<id:N>` alias in the document. That is how `notifications/index` (three
     * notifications written in one second) and `admin/routes-popular` (two routes with identical
     * counts) flipped between the generating run and the verifying run.
     *
     * The ladder starts an hour in the past, one second per row, so it can never collide with a
     * business timestamp (a departure in the future, a decision deadline). It runs right before
     * the request, after the case's own prepare(), and outside the measured query window.
     */
    private function settleRowTimestamps(): void
    {
        $base = self::snapshotNow()->subHour();

        foreach (self::LADDERED_TABLES as $table) {
            $columns = Schema::getColumnListing($table);

            if (! in_array('created_at', $columns, true)) {
                continue;
            }

            $ids = DB::table($table)->orderBy('id')->pluck('id')->all();

            foreach ($ids as $index => $id) {
                $stamp = $base->copy()->addSeconds($index);

                $values = ['created_at' => $stamp];

                if (in_array('updated_at', $columns, true)) {
                    $values['updated_at'] = $stamp;
                }

                DB::table($table)->where('id', $id)->update($values);
            }
        }
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
     *
     * One attachment travels with it. The nested `attachments` list was `[]` in nine complaint
     * snapshots, so nothing pinned the shape of an attachment row; the file is a fabricated PDF of
     * a fixed size (not a generated image) because the upload stores the file's byte size and a
     * GD-compressed PNG is the kind of value that quietly differs between machines.
     */
    protected function createComplaint(): string
    {
        $response = $this->withToken($this->passengerToken)->post('/api/complaints', [
            'title' => 'Snapshot complaint',
            'description' => 'A deterministic complaint created by the snapshot batch.',
            'type' => 'technical_issue',
            'attachments' => [UploadedFile::fake()->create('snapshot-attachment.pdf', 12, 'application/pdf')],
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

    /** Put a passenger message in the support conversation, remembered as {message}. */
    protected function sendSupportMessage(): string
    {
        $response = $this->withToken($this->passengerToken)->postJson(
            '/api/chat/conversations/'.$this->caseVars['{conversation}'].'/messages',
            ['content' => 'The passenger needs help with a booking.']
        );

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not send a support message (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $this->caseVars['{message}'] = (string) $response->json('data.id');

        // A text message always carries `metadata: []` - that map is only populated for the image
        // type, which the message handler writes directly (not through the text door). One image row
        // per conversation is what lets the chat lists pin the populated shape at all; without it
        // every message in the corpus shows an empty map and the metadata block is unpinned.
        Message::where('id', $this->caseVars['{message}'])->update(['created_at' => now()->subMinutes(2)]);
        $this->seedChatImage((int) $this->caseVars['{conversation}'], $this->passenger->id);

        return $this->caseVars['{message}'];
    }

    /**
     * Insert one image message with a deterministic metadata map.
     *
     * Written directly rather than uploaded: the upload path names the stored file
     * "{sender}_{receiver}_{timestamp}", which carries the two user ids into the snapshot, while
     * this row keeps a stable path and a stable map.
     */
    protected function seedChatImage(int $conversationId, int $senderId): Message
    {
        $image = Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'content' => 'chat-images/snapshot-map.png',
            'type' => 'image',
            'metadata' => [
                'original_name' => 'snapshot-map.png',
                'size' => 2048,
                'mime_type' => 'image/png',
                'caption' => 'The pickup point',
            ],
        ]);

        $image->forceFill(['created_at' => now()->subMinute()])->save();

        return $image;
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

        // A pending verification with no uploaded documents is not a state the verification queue
        // can actually be showing: the nested `documents` list was `[]` in the admin and staff
        // queue snapshots for exactly that reason.
        $this->seedDocumentsFor($this->otherPassenger);
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

        // AFTER the case's own prepare (which may write rows) and BEFORE the call, so the extra
        // rows join the same ladder. It cannot run inside snapshotCall(): that window is the
        // measured query budget.
        $this->settleRowTimestamps();

        $response = $this->snapshotCall(
            $case,
            fn (): TestResponse => $this->sendSnapshotRequest($definition),
            $definition['expect'] ?? null,
            $definition['route']
        );

        if (isset($definition['expect'])) {
            $this->assertSame(
                $definition['expect'],
                $response->getStatusCode(),
                "Snapshot case '{$case}' returned the wrong status. Body: ".substr((string) $response->getContent(), 0, 600)
            );
        }

        // Money and ride mutations additionally pin the ROWS they wrote: a body that says
        // "confirmed" over a write that only half happened is exactly the class of bug a response
        // snapshot cannot see.
        if ($definition['state'] ?? in_array($case, self::MONEY_AND_RIDE_MUTATION_CASES, true)) {
            $this->assertStateSnapshot($case, fn (): array => $this->captureMoneyAndRideState());
        }
    }

    /**
     * The cases that must ALSO pin the rows they wrote, because the thing they mutate is money or a
     * ride. A response body that reports success over a half-finished write is invisible to a body
     * snapshot; the state files under tests/Snapshots/state make it visible.
     *
     * `StateSnapshotCoverageTest` proves this list is complete: every non-GET case whose route is a
     * wallet, ledger, booking or ride route must appear here (and have a committed state file).
     *
     * @var array<int, string>
     */
    public const MONEY_AND_RIDE_MUTATION_CASES = [
        'admin/passenger-charge-wallet',
        'admin/passenger-charge-a-negative-amount',
        'admin/wallet-charge',
        'admin/wallet-charge-a-negative-amount',
        'admin/wallet-requests-approve',
        'admin/wallet-requests-reject',
        'bookings/accept',
        'bookings/accept-someone-elses-request',
        'bookings/cancel',
        'bookings/cancel-more-seats-than-booked',
        'bookings/cancel-seats',
        'bookings/cancel-someone-elses-booking',
        'bookings/passenger-confirm',
        'bookings/passenger-no-show',
        'bookings/reject',
        'rides/book',
        'rides/book-more-seats-than-the-ride-has',
        'rides/book-the-drivers-own-ride',
        'rides/book-without-the-balance',
        'rides/cancel',
        'rides/cancel-someone-elses-trip',
        'rides/create',
        'rides/create-departure-in-the-past',
        'rides/create-with-route',
        'rides/driver-no-show',
        'staff/bookings-cancel',
        'staff/bookings-cancel-a-booking-that-does-not-exist',
        'staff/trips-cancel',
        'wallet/create-direct',
        'wallet/create-direct-already-has-a-wallet',
        'wallet/initiate',
        'wallet/request-charge',
        'wallet/request-charge-zero-amount',
        'wallet/request-withdraw',
        'wallet/request-withdraw-more-than-balance',
        'wallet/requests-destroy',
        'wallet/requests-destroy-not-the-owner',
        'wallet/requests-store',
        'wallet/requests-store-unknown-type',
        'wallet/verify-and-create-missing-session',
    ];

    /**
     * Non-GET cases that write NOTHING, so a state snapshot would pin rows the route never touched.
     *
     * The list is deliberately tiny and each entry carries the reason. `StateSnapshotCoverageTest`
     * proves every entry exists and is a real non-GET case, so this cannot silently absorb a route
     * that starts writing later.
     *
     * @var array<string, string>
     */
    public const READ_ONLY_POST_CASES = [
        'rides/route-options' => 'POST because the body carries a whole address/route payload; it computes candidate routes and writes no row',
        'rides/search-post' => 'search with a structured filter body; it shares the read path with rides/search-get and neither writes',
    ];

    /**
     * The money and ride rows every mutation case pins.
     *
     * Only meaning-bearing, run-stable columns: an id (aliased), a status, a balance, an escrow or
     * seat count. Deliberately excluded: `wallet_transactions.transaction_id` (`ADM-7-<uuid>` - the
     * absolute user id inside the string changes between runs while the alias inside the JSON
     * columns does not), `ledger_entries.description`, and every timestamp (masked where kept).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function captureMoneyAndRideState(): array
    {
        return [
            'wallets' => DB::table('wallets')->orderBy('id')
                ->get(['id', 'user_id', 'phone_number', 'kind', 'balance', 'cash_ride_debt'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'wallet_transactions' => DB::table('wallet_transactions')->orderBy('id')
                ->get(['id', 'wallet_id', 'user_id', 'type', 'amount', 'previous_balance', 'new_balance', 'status', 'reference', 'posting_key'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'ledger_entries' => DB::table('ledger_entries')->orderBy('id')
                ->get(['id', 'wallet_id', 'wallet_transaction_id', 'amount'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'wallet_requests' => DB::table('wallet_requests')->orderBy('id')
                ->get(['id', 'user_id', 'wallet_id', 'type', 'amount', 'status'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'bookings' => DB::table('bookings')->orderBy('id')
                ->get(['id', 'user_id', 'ride_id', 'seats', 'status', 'unit_price', 'amount_paid', 'escrow_held'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'rides' => DB::table('rides')->orderBy('id')
                ->get(['id', 'driver_id', 'status', 'available_seats', 'price_per_seat'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
        ];
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
        $this->seedDocumentsFor($this->driver);
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
