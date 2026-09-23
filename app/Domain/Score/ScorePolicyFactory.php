<?php

namespace App\Domain\Score;

use App\Domain\Score\Policies\DriverCancelRidePolicy;
use App\Domain\Score\Policies\DriverCancelSeatPolicy;
use App\Domain\Score\Policies\DriverCommitmentStreakPolicy;
use App\Domain\Score\Policies\DriverNoShowPolicy;
use App\Domain\Score\Policies\PassengerCancelPolicy;
use App\Domain\Score\Policies\PassengerNoShowPolicy;
use App\Domain\Score\Policies\RatingPolicy;
use App\Domain\Score\Policies\RideCompletionPolicy;
use App\Domain\Score\Policies\ScorePolicyInterface;
use App\Enums\ScoreAction;
use InvalidArgumentException;

/**
 * Score Policy Factory
 *
 * OCP: add a new policy by registering it in $policies — nothing else changes.
 * DIP: consumers depend on ScorePolicyInterface, not concrete classes.
 */
final class ScorePolicyFactory
{
    /** @var ScorePolicyInterface[] */
    private array $policies;

    public function __construct()
    {
        $this->policies = [
            new RideCompletionPolicy(),
            new RatingPolicy(),
            new DriverCommitmentStreakPolicy(),
            new PassengerCancelPolicy(),
            new PassengerNoShowPolicy(),
            new DriverCancelSeatPolicy(),
            new DriverCancelRidePolicy(),
            new DriverNoShowPolicy(),
        ];
    }

    /**
     * Resolve the policy that handles the given action.
     *
     * @throws InvalidArgumentException if no policy is registered for the action
     */
    public function make(ScoreAction $action): ScorePolicyInterface
    {
        foreach ($this->policies as $policy) {
            if ($policy->supports($action)) {
                return $policy;
            }
        }

        throw new InvalidArgumentException(
            "No score policy registered for action: {$action->value}"
        );
    }

    /**
     * Register an additional policy at runtime (e.g. from tests or extensions).
     */
    public function register(ScorePolicyInterface $policy): void
    {
        array_unshift($this->policies, $policy); // higher priority wins
    }
}
