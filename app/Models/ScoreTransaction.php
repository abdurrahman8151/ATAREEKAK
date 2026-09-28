<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ScoreTransaction Model
 *
 * Immutable audit trail for every score change.
 * Never delete rows — only insert.
 */
class ScoreTransaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // insert-only, no updates

    protected $fillable = [
        'user_id',
        'action',
        'points',
        'previous_score',
        'new_score',
        'reference_type',   // e.g. App\Models\Booking
        'reference_id',     // e.g. booking_id or ride_id
        'reason',
        'high_cancel_rate_applied',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'previous_score' => 'integer',
        'new_score' => 'integer',
        'high_cancel_rate_applied' => 'boolean',
        'metadata' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getIsPositiveAttribute(): bool
    {
        return $this->points > 0;
    }

    public function getFormattedPointsAttribute(): string
    {
        return ($this->points >= 0 ? '+' : '').$this->points;
    }
}
