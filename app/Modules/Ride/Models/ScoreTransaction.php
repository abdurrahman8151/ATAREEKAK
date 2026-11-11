<?php

namespace App\Modules\Ride\Models;

use App\Models\Concerns\GuardsLazyLoading;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/**
 * ScoreTransaction Model
 *
 * Immutable audit trail for every score change.
 * Never delete rows — only insert.
 */
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoreTransaction extends Model
{
    /**
     * Stage 2 C1: declared explicitly so the class can move without the rows moving with it.
     * The schema is frozen - the namespace changes, the table does not.
     */
    protected $table = 'score_transactions';

    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    public const UPDATED_AT = null; // insert-only, no updates

    protected $fillable = [
        'user_id',
        'action',
        'points',
        'previous_score',
        'new_score',
        'reference_type',   // class name of the referenced row (App\Models\Ride / App\Models\Booking today)
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
