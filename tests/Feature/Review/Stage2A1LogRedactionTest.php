<?php

namespace Tests\Feature\Review;

use App\Http\Requests\CreateRideRequest;
use App\Models\User;
use App\Modules\Identity\Contracts\EmailOtpServiceInterface;
use App\Modules\Identity\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Http\Handlers\SignupRegistrationHandler;
use App\Modules\Ride\Http\Handlers\RideLifecycleHandler;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Stage 2 A1 characterization: a failed INSERT must not put the bound values into the log.
 *
 * THE LEAK, AND WHY THE TEST CANNOT JUST MOCK IT
 *
 * `QueryException::getMessage()` is built by Laravel as
 *
 *     SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'
 *     (Connection: mysql, SQL: insert into `users` (`first_name`, `email`, `password`, `address`)
 *      values (LeakProbeFirst, leak.probe@example.test, $2y$10$LeakProbeHash..., LeakProbeAddress))
 *
 * so logging it writes every bound value out. The owner's own test log showed exactly that for signup
 * (Path B: bcrypt hash, name, email, address) and for ride creation (phone, addresses, notes).
 *
 * A test that mocks the exception can be accused of proving nothing about the real thing, so this test
 * does both halves:
 *
 *   1. it CAUSES real MySQL failures - an INSERT naming a column that does not exist - and asserts the
 *      caught QueryException's own message really does contain the marker values (the positive control:
 *      without it, "the log is clean" could just mean "the fixture carried nothing");
 *   2. it feeds those same two real exceptions through the two production code paths that used to log
 *      `$e->getMessage()` - signup Path B and ride creation - and asserts the captured log record
 *      contains none of the bound values but still carries the SQLSTATE.
 *
 * Both handlers are called directly rather than over HTTP: the point under test is the logging inside
 * their catch blocks, and going direct keeps validation, routing and middleware out of the fixture.
 * Response shapes and status codes are asserted too, because A1 is not allowed to change them.
 */
final class Stage2A1LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    /** Markers that must never reach the log, and the values the two real exceptions carry. */
    private const SIGNUP_MARKERS = [
        'LeakProbeFirst',
        'LeakProbeLast',
        'leak.probe@example.test',
        '$2y$10$LeakProbeHashLeakProbeHashLeakProbeHashLeakProbeHash',
        'LeakProbeAddress',
    ];

    private const RIDE_MARKERS = [
        'LeakProbePickupAddress',
        'LeakProbeDestinationAddress',
        'LeakProbeRideNotes',
        '0912345678',
    ];

    /**
     * The signup values that must not reach the log. The email is NOT here: it is an existing context
     * key of that log line (`'email' => $request->email`, kept because A1 keeps the existing keys), so
     * it is asserted separately below. The hash, the name and the address only ever arrived through the
     * exception message - that is the leak.
     */
    private const SIGNUP_FORBIDDEN = [
        'LeakProbeFirst',
        'LeakProbeLast',
        '$2y$10$LeakProbeHashLeakProbeHashLeakProbeHashLeakProbeHash',
        'LeakProbeAddress',
    ];

    public function test_a_query_failure_is_logged_without_its_bound_values(): void
    {
        $signupLeak = $this->forceFailingInsert('users', [
            'first_name' => self::SIGNUP_MARKERS[0],
            'last_name' => self::SIGNUP_MARKERS[1],
            'email' => self::SIGNUP_MARKERS[2],
            'password' => self::SIGNUP_MARKERS[3],
            'address' => self::SIGNUP_MARKERS[4],
        ]);

        $rideLeak = $this->forceFailingInsert('rides', [
            'pickup_address' => self::RIDE_MARKERS[0],
            'destination_address' => self::RIDE_MARKERS[1],
            'notes' => self::RIDE_MARKERS[2],
            'communication_number' => self::RIDE_MARKERS[3],
        ]);

        // POSITIVE CONTROL: the exceptions really do carry the values, in the message Laravel builds.
        foreach (self::SIGNUP_MARKERS as $marker) {
            $this->assertStringContainsString($marker, $signupLeak->getMessage(), 'fixture is not a real leak');
        }
        foreach (self::RIDE_MARKERS as $marker) {
            $this->assertStringContainsString($marker, $rideLeak->getMessage(), 'fixture is not a real leak');
        }

        Log::spy();

        $signupRecord = $this->driveSignupPathB($signupLeak);
        $rideRecord = $this->driveRideCreation($rideLeak);

        $this->assertRecordIsClean($signupRecord, 'Registration failed (Path B)', self::SIGNUP_FORBIDDEN);
        $this->assertRecordIsClean($rideRecord, 'Ride creation failed', self::RIDE_MARKERS);

        // The email stays a context key on purpose (the existing keys are kept), so it is asserted
        // separately: the address, the name and the hash are the leak, not the lookup key.
        $this->assertStringContainsString(self::SIGNUP_MARKERS[2], (string) $signupRecord['context']['email']);
    }

    /**
     * Run a real INSERT that cannot succeed, and return the QueryException MySQL produced.
     *
     * The payload carries the marker values as genuine bindings and one column that does not exist, so
     * the statement fails in the driver with a message that names the column and leaks nothing itself -
     * the values reach the message only through the SQL Laravel appends.
     *
     * @param  array<string, string>  $markers
     */
    private function forceFailingInsert(string $table, array $markers): QueryException
    {
        try {
            DB::table($table)->insert($markers + ['stage2_a1_probe_column' => 1]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('stage2_a1_probe_column', $e->getMessage());

            return $e;
        }

        $this->fail('The INSERT into '.$table.' was expected to fail, so the fixture carried no real leak.');
    }

    /**
     * Call signup Path B with a repository that fails the way a real INSERT fails.
     *
     * @return array{message: string, context: array<string, mixed>}
     */
    private function driveSignupPathB(QueryException $leak): array
    {
        $repository = Mockery::mock(UserRepositoryInterface::class);
        $repository->shouldReceive('findByEmail')->andReturn(null);
        $repository->shouldReceive('createUser')->andThrow($leak);

        $handler = new SignupRegistrationHandler($repository, Mockery::mock(EmailOtpServiceInterface::class));

        // The allowed address list is data inside the handler; take its first entry instead of
        // hard-coding a city here, so the test follows the rule rather than duplicating it.
        $source = (string) file_get_contents(app_path('Modules/Identity/Http/Handlers/SignupRegistrationHandler.php'));
        preg_match("/'address' => 'required\|in:([^']+)'/", $source, $matches);
        $this->assertArrayHasKey(1, $matches, 'signup address rule not found - the fixture must follow it');
        $address = explode(',', $matches[1])[0];

        $request = Request::create('/api/signup', 'POST', [
            'first_name' => self::SIGNUP_MARKERS[0],
            'last_name' => self::SIGNUP_MARKERS[1],
            'email' => self::SIGNUP_MARKERS[2],
            'password' => 'LeakProbe-Password-1',
            'password_confirmation' => 'LeakProbe-Password-1',
            'gender' => 'M',
            'address' => $address,
        ]);

        $response = $handler->register($request);

        $this->assertSame(500, $response->getStatusCode(), 'A1 must not change the signup failure status.');

        return $this->recordFor('Registration failed (Path B)');
    }

    /**
     * Call the REAL ride creation path, with its first database query interrupted by the real
     * QueryException MySQL produced for the same marker values.
     *
     * `RideService` is final, so it cannot be mocked - and this is the better fixture anyway: the throw
     * happens inside the production call stack (a DB listener runs inside the connection while the
     * handler is working), so the handler's own catch block and its log line are what is characterised,
     * not a stand-in. It is armed only after the request has been built and validated, so the failure
     * is the ride path's first query, whatever it happens to be.
     *
     * @return array{message: string, context: array<string, mixed>}
     */
    private function driveRideCreation(QueryException $leak): array
    {
        $armed = false;
        $thrown = false;
        DB::listen(function ($query) use ($leak, &$armed, &$thrown): void {
            if ($armed && ! $thrown) {
                $thrown = true;

                throw $leak;
            }
        });

        $user = User::factory()->create(['is_verified_driver' => true]);

        $request = CreateRideRequest::create('/api/rides', 'POST', [
            'pickup_address' => self::RIDE_MARKERS[0],
            'pickup_lat' => 33.5138,
            'pickup_lng' => 36.2765,
            'destination_address' => self::RIDE_MARKERS[1],
            'destination_lat' => 33.5102,
            'destination_lng' => 36.2913,
            'departure_time' => now()->addHours(2)->toDateTimeString(),
            'available_seats' => 2,
            'price_per_seat' => 5000,
            'payment_method' => 'e-pay',
            'booking_type' => 'direct',
            'communication_number' => self::RIDE_MARKERS[3],
            'vehicle_type' => 'sedan',
            'notes' => self::RIDE_MARKERS[2],
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        $armed = true;
        $response = app(RideLifecycleHandler::class)->create($request);

        $this->assertSame(
            500,
            $response->getStatusCode(),
            'A1 must not change the ride failure status. Body: '.$response->getContent()
        );
        $this->assertTrue(
            $thrown,
            'No query ran inside the ride path, so the failure path was not exercised. Body: '.$response->getContent()
        );

        return $this->recordFor('Ride creation failed');
    }

    /**
     * The log record the handler wrote, captured from the Log spy.
     *
     * @return array{message: string, context: array<string, mixed>}
     */
    private function recordFor(string $needle): array
    {
        $records = [];
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use (&$records, $needle) {
                if (! str_contains($message, $needle)) {
                    return false;
                }
                $records[] = ['message' => $message, 'context' => $context];

                return true;
            })
            ->atLeast()->once();

        $this->assertNotEmpty($records, 'No log record was written for "'.$needle.'".');

        return end($records);
    }

    /**
     * @param  array{message: string, context: array<string, mixed>}  $record
     * @param  array<int, string>  $markers
     */
    private function assertRecordIsClean(array $record, string $needle, array $markers): void
    {
        $this->assertStringContainsString($needle, $record['message']);

        $text = $record['message'].' '.json_encode($record['context']);
        foreach ($markers as $marker) {
            $this->assertStringNotContainsString($marker, $text, 'Bound value leaked into the log: '.$marker);
        }

        $error = (string) ($record['context']['error'] ?? '');
        $this->assertStringContainsString('SQLSTATE[', $error, 'The log must still say what the database said.');
        $this->assertStringNotContainsString('SQL: insert', $error, 'The log must not carry the statement or its bindings.');
        // The driver's own words survive - that is what makes the line still useful.
        $this->assertStringContainsString('stage2_a1_probe_column', $error);
    }

    /** The formatter itself, against the same real exception, without any handler in the way. */
    public function test_the_formatter_keeps_the_sqlstate_and_drops_the_statement(): void
    {
        $leak = $this->forceFailingInsert('users', [
            'first_name' => self::SIGNUP_MARKERS[0],
            'email' => self::SIGNUP_MARKERS[2],
            'password' => self::SIGNUP_MARKERS[3],
        ]);

        $safe = SafeExceptionLog::message($leak);

        $this->assertStringStartsWith('SQLSTATE[', $safe);
        $this->assertStringContainsString('stage2_a1_probe_column', $safe);
        foreach (self::SIGNUP_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $safe);
        }
    }
}
