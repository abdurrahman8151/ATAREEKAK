<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    case PENDING = 'pending';
    case IN_REVIEW = 'in_review';
    case ESCALATED = 'escalated';   // ← NEW: transferred from agent → admin
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::IN_REVIEW => 'In Review',
            self::ESCALATED => 'Escalated',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::IN_REVIEW => 'blue',
            self::ESCALATED => 'orange',
            self::RESOLVED => 'green',
            self::CLOSED => 'gray',
        };
    }

    /** True while an agent or admin can still act on this complaint. */
    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::IN_REVIEW, self::ESCALATED]);
    }

    /** Agent can act (respond / escalate) — escalated ones belong to admin. */
    public function isAgentActionable(): bool
    {
        return in_array($this, [self::PENDING, self::IN_REVIEW]);
    }
}
