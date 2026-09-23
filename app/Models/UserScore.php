<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UserScore Model
 *
 * Maintains each user's live score state.
 * ScoreTransaction holds the full audit trail.
 *
 * @property int   $user_id
 * @property int   $score                 Current score (starts at 100)
 * @property int   $total_rides           Completed rides (driver + passenger)
 * @property int   $total_cancellations   Cancelled bookings / rides
 * @property float $cancel_rate           Computed: total_cancellations / max(1, total_rides+total_cancellations) * 100
 */
class UserScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'score',
        'total_rides',
        'total_cancellations',
        'total_no_shows',      // ← add this
        'consecutive_five_star_ratings',
        'last_driver_cancellation_at',
        'last_streak_milestone_days',
    ];

    protected $casts = [
        'score'                         => 'integer',
        'total_rides'                   => 'integer',
        'total_cancellations'           => 'integer',
        'total_no_shows'                => 'integer',   // ← add this
        'consecutive_five_star_ratings' => 'integer',
        'last_driver_cancellation_at'   => 'datetime',
        'last_streak_milestone_days'    => 'integer',
    ];

// Add this helper method
    public function incrementNoShows(): void
    {
        $this->increment('total_no_shows');
    }

    /** Bumps the 5-star streak counter (call on every 5-star rating received). */
    public function incrementFiveStarStreak(): void
    {
        $this->increment('consecutive_five_star_ratings');
    }

    /** Breaks the 5-star streak (call on any rating below 5 stars). */
    public function resetFiveStarStreak(): void
    {
        if ($this->consecutive_five_star_ratings !== 0) {
            $this->update(['consecutive_five_star_ratings' => 0]);
        }
    }

    /** Resets the no-cancellation streak clock (call on any driver cancellation / no-show). */
    public function registerDriverCancellation(): void
    {
        $this->update([
            'last_driver_cancellation_at' => now(),
            'last_streak_milestone_days'  => 0,
        ]);
    }
    // ── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ScoreTransaction::class, 'user_id', 'user_id');
    }

    // ── Computed attributes ──────────────────────────────────────────────────

    /**
     * Cancel rate as a percentage (0–100).
     * Used by policies to determine the high-cancel-rate tier.
     */
    public function getCancelRateAttribute(): float
    {
        $total = $this->total_rides + $this->total_cancellations;

        return $total > 0
            ? round(($this->total_cancellations / $total) * 100, 2)
            : 0.0;
    }

    /**
     * Tier label for the admin dashboard.
     */
    public function getTierAttribute(): string
    {
        return match (true) {
            $this->score >= 80  => 'Gold',
            $this->score >= 60  => 'Silver',
            $this->score >= 40  => 'Bronze',
            default             => 'Restricted',
        };
    }

    // ── Mutators ─────────────────────────────────────────────────────────────

    /**
     * Apply a delta to the score, clamping to [0, 200].
     */
    public function applyDelta(int $delta): void
    {
        $this->score = max(0, min(100, $this->score + $delta));
        $this->save();
    }

    public function incrementRides(): void
    {
        $this->increment('total_rides');
    }

    public function incrementCancellations(): void
    {
        $this->increment('total_cancellations');
    }

    public function setCancelRateAttribute(mixed $value): void
    {
        // computed from total_cancellations / total_rides — never stored
    }

    public function setTierAttribute(mixed $value): void
    {
        // computed from score — never stored
    }
}
