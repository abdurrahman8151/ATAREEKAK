<?php

namespace App\Modules\Ride\Domain;

use App\Enums\ScoreAction;
use App\Modules\Ride\Domain\Policies\DriverCancelRidePolicy;
use App\Modules\Ride\Domain\Policies\DriverCancelSeatPolicy;
use App\Modules\Ride\Domain\Policies\DriverNoShowPolicy;
use App\Modules\Ride\Domain\Policies\PassengerCancelPolicy;
use App\Modules\Ride\Domain\Policies\PassengerNoShowPolicy;
use App\Modules\Ride\Domain\Policies\RideCompletionPolicy;
use App\Modules\Ride\Domain\Policies\ScorePolicyInterface;
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
            new RideCompletionPolicy,
            new PassengerCancelPolicy,
            new PassengerNoShowPolicy,
            new DriverCancelSeatPolicy,
            new DriverCancelRidePolicy,
            new DriverNoShowPolicy,
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
