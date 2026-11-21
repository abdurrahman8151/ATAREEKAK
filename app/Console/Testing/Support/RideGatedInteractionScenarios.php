<?php

namespace App\Console\Testing\Support;

use App\Modules\Identity\Models\ProfileComment;
use App\Modules\Identity\Models\UserRating;
use App\Modules\Identity\Services\ProfileInteractionService;

/**
 * Scenario steps (S1–S11) for the ride-gated interaction test harness.
 *
 * Extracted verbatim from Testridegatedinteractioncommand so the command keeps its
 * signature, output and order of operations while delegating each scenario here.
 * The using command must provide: runScenario(), check(), attemptBlocked(),
 * makeScenario(), makeUser(), makeProfile(), makeRide() and makeBooking().
 */
trait RideGatedInteractionScenarios
{
    /**
     * S1 — a comment is ALLOWED after a completed ride.
     */
    private function scenarioCommentAllowedAfterCompletedRide(ProfileInteractionService $service): void
    {
        $this->runScenario('1 — Comment ALLOWED after completed ride', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $result = $service->addComment($passenger->id, $driver->id, 'Great driver!', $ride->id);

            $this->check('Returns a comment array', true, isset($result['id']));
            $this->check('Comment text stored correctly', 'Great driver!', $result['comment']);
            $this->check('ride_id stored on the comment', $ride->id, $result['ride_id']);
            $this->check('Commenter ID in response', $passenger->id, $result['commenter']['id']);
            $this->check('DB row created', 1,
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
        });
    }

    /**
     * S2 — a rating is ALLOWED after a completed ride.
     */
    private function scenarioRatingAllowedAfterCompletedRide(ProfileInteractionService $service): void
    {
        $this->runScenario('2 — Rating ALLOWED after completed ride', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $stats = $service->rateUser($passenger->id, $driver->id, 4.5, $ride->id);

            $this->check('Returns stats array', true, isset($stats['average']));
            $this->check('Average is 3.75', 3.75, (float) $stats['average']);
            $this->check('total_ratings is 2', 2, $stats['total_ratings']);
            $this->check('DB row created', 1,
                UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Rating value stored', 4.5,
                (float) UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('rating'));
        });
    }

    /**
     * S3 — two rides with the same driver: two comments are allowed.
     */
    private function scenarioTwoRidesTwoCommentsAllowed(ProfileInteractionService $service): void
    {
        $this->runScenario('3 — Two rides with same driver → two comments allowed', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride1] =
                $this->makeScenario('completed');

            $ride2 = $this->makeRide($driver);
            $this->makeBooking($ride2, $passenger, 'completed');

            $r1 = $service->addComment($passenger->id, $driver->id, 'First ride was great', $ride1->id);
            $r2 = $service->addComment($passenger->id, $driver->id, 'Second ride too', $ride2->id);

            $this->check('First comment created', true, isset($r1['id']));
            $this->check('Second comment created', true, isset($r2['id']));
            $this->check('Comments have different ride_id', true, $r1['ride_id'] !== $r2['ride_id']);
            $this->check('Two comments in DB', 2,
                ProfileComment::where('user_id', $passenger->id)->count());
        });
    }

    /**
     * S4 — two rides with the same driver: two ratings are allowed.
     */
    private function scenarioTwoRidesTwoRatingsAllowed(ProfileInteractionService $service): void
    {
        $this->runScenario('4 — Two rides with same driver → two ratings allowed', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride1] =
                $this->makeScenario('completed');

            $ride2 = $this->makeRide($driver);
            $this->makeBooking($ride2, $passenger, 'completed');

            $stats1 = $service->rateUser($passenger->id, $driver->id, 5.0, $ride1->id);
            $stats2 = $service->rateUser($passenger->id, $driver->id, 3.0, $ride2->id);

            $this->check('First rating saved', true, $stats1['total_ratings'] === 2);
            $this->check('Second rating saved', true, $stats2['total_ratings'] === 3);
            $this->check('Average of 5+3+seed=3.67', 3.67, (float) $stats2['average']);
            $this->check('Two rating rows in DB', 2,
                UserRating::where('rater_id', $passenger->id)->count());
        });
    }

    /**
     * S5 — no booking: the comment is BLOCKED (403).
     */
    private function scenarioNoBookingCommentBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('5 — No booking → comment BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario(null);

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->addComment($passenger->id, $driver->id, 'Should never appear', $ride->id)
            );

            $this->check('Exception thrown', true, $blocked);
            $this->check('HTTP code is 403', 403, $code);
            $this->check('Zero DB rows created', 0,
                ProfileComment::where('user_id', $passenger->id)->count());
        });
    }

    /**
     * S6 — pending booking: the rating is BLOCKED (403).
     */
    private function scenarioPendingBookingRatingBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('6 — Pending booking → rating BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('pending');

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id)
            );

            $this->check('Exception thrown', true, $blocked);
            $this->check('HTTP code is 403', 403, $code);
            $this->check('Zero rating rows in DB', 0,
                UserRating::where('rater_id', $passenger->id)->count());
        });
    }

    /**
     * S7 — confirmed booking, ride not done: the comment is BLOCKED (403).
     */
    private function scenarioConfirmedBookingCommentBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('7 — Confirmed booking (ride not done) → comment BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('confirmed');

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->addComment($passenger->id, $driver->id, 'Too early', $ride->id)
            );

            $this->check('Exception thrown for confirmed booking', true, $blocked);
            $this->check('HTTP code is 403', 403, $code);
        });
    }

    /**
     * S8 — cancelled booking: the rating is BLOCKED (403).
     */
    private function scenarioCancelledBookingRatingBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('8 — Cancelled booking → rating BLOCKED (403)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('cancelled');

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id)
            );

            $this->check('Exception thrown for cancelled booking', true, $blocked);
            $this->check('HTTP code is 403', 403, $code);
        });
    }

    /**
     * S9 — completed ride with driver A: commenting on driver B is BLOCKED (403).
     */
    private function scenarioCommentOnUnrelatedDriverBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('9 — Completed ride with driver A → cannot comment on driver B (403)', function () use ($service) {
            ['driver' => $driverA, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $driverB = $this->makeUser('Unrelated-Driver');
            $this->makeProfile($driverB);

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->addComment($passenger->id, $driverB->id, 'Never rode with B', $ride->id)
            );

            $this->check('Exception thrown (wrong driver)', true, $blocked);
            $this->check('HTTP code is 403', 403, $code);
            $this->check('No comment on driverB profile', 0,
                ProfileComment::where('user_id', $passenger->id)->count());
        });
    }

    /**
     * S10 — a duplicate comment on the same ride is BLOCKED (409).
     */
    private function scenarioDuplicateCommentBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('10 — Duplicate comment on same ride → BLOCKED (409)', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $first = $service->addComment($passenger->id, $driver->id, 'First and only', $ride->id);
            $this->check('First comment saved', true, isset($first['id']));

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->addComment($passenger->id, $driver->id, 'Trying again', $ride->id)
            );

            $this->check('Duplicate blocked', true, $blocked);
            $this->check('HTTP code is 409', 409, $code);
            $this->check('Still only 1 row in DB', 1,
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Original text preserved', 'First and only',
                ProfileComment::where('user_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('comment'));
        });
    }

    /**
     * S11 — a duplicate rating on the same ride is BLOCKED (409), and the first rating is preserved.
     */
    private function scenarioDuplicateRatingBlocked(ProfileInteractionService $service): void
    {
        $this->runScenario('11 — Duplicate rating on same ride → BLOCKED (409), first preserved', function () use ($service) {
            ['driver' => $driver, 'passenger' => $passenger, 'ride' => $ride] =
                $this->makeScenario('completed');

            $stats1 = $service->rateUser($passenger->id, $driver->id, 4.0, $ride->id);
            $this->check('First rating (4.0) saved', 2, $stats1['total_ratings']);

            [$blocked, $code] = $this->attemptBlocked(
                fn () => $service->rateUser($passenger->id, $driver->id, 5.0, $ride->id)
            );

            $this->check('Duplicate rating blocked', true, $blocked);
            $this->check('HTTP code is 409', 409, $code);
            $this->check('Still only 1 rating row', 1,
                UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->count());
            $this->check('Original rating 4.0 not overwritten', 4.0,
                (float) UserRating::where('rater_id', $passenger->id)
                    ->where('ride_id', $ride->id)->value('rating'));
        });
    }
}
