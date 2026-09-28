<?php

namespace App\Domain\Score;

use App\Enums\ScoreAction;

/**
 * Score Result — immutable Value Object
 *
 * Returned by every policy so callers never deal with raw integers.
 */
final class ScoreResult
{
    public function __construct(
        public readonly int $points,
        public readonly ScoreAction $action,
        public readonly string $reason,
        public readonly bool $highCancelRateApplied = false,
    ) {}

    public static function of(
        int $points,
        ScoreAction $action,
        string $reason,
        bool $highCancelRateApplied = false,
    ): self {
        return new self($points, $action, $reason, $highCancelRateApplied);
    }

    public function isPositive(): bool
    {
        return $this->points > 0;
    }

    public function isNegative(): bool
    {
        return $this->points < 0;
    }

    public function isNeutral(): bool
    {
        return $this->points === 0;
    }

    public function toArray(): array
    {
        return [
            'points' => $this->points,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'high_cancel_rate_applied' => $this->highCancelRateApplied,
        ];
    }
}
