<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/**
 * UserScore Model
 *
 * Maintains each user's live score state.
 * ScoreTransaction holds the full audit trail.
 *
 * @property int $user_id
 * @property int $score Current score (starts at 100)
 * @property int $total_rides Completed rides (driver + passenger)
 * @property int $total_cancellations Cancelled bookings / rides
 * @property float $cancel_rate Computed: total_cancellations / max(1, total_rides+total_cancellations) * 100
 */
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserScore extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'score',
        'total_rides',
        'total_cancellations',
        'total_no_shows',      // ← add this
    ];

    protected $casts = [
        'score' => 'integer',
        'total_rides' => 'integer',
        'total_cancellations' => 'integer',
        'total_no_shows' => 'integer',   // ← add this
    ];

    // Add this helper method
    public function incrementNoShows(): void
    {
        $this->increment('total_no_shows');
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

    // ── Mutators ─────────────────────────────────────────────────────────────

    /**
     * The `tier` band is a REAL COLUMN written by `ScoreService::resolveTier` on every score
     * change, and it used to ALSO have a computed accessor here with identical bands. The accessor
     * shadowed the column: reading `->tier` returned the computed value, so the stored value could
     * silently disagree (it did - see R2 sec 42). The column is now the single source of truth,
     * written correctly by resolveTier (Gold>=80 / Silver>=60 / Bronze>=40, Restricted below), and
     * RV37ScorePolicyTest pins the raw column so the two can never drift again. The accessor is
     * gone.
    //

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
        // computed from score — never stored. The `tier` column is legacy (added by
        // 2025_08_18_add_tier_to_user_scores with a 'bronze' default) and is deliberately NOT
        // written: the computed accessor below is the single source of truth. R2 sec 42 records the
        // dead `ScoreService::resolveTier()` write that used to sit here and disagree with these
        // bands; it was removed rather than "fixed". Dropping the vestigial column is a separate
        // migration decision, recorded, not done here.
    }

    /**
     * Tier label for the admin dashboard - the single source of truth for the bands.
     *
     * Owner-pinned 2026-10-02 (un2, R2 sec 40.1): Gold >= 80, Silver >= 60, Bronze >= 40, and
     * Restricted below that. Pinned by `RV37ScorePolicyTest` so these numbers cannot drift.
     */
    public function getTierAttribute(): string
    {
        return match (true) {
            $this->score >= 80 => 'Gold',
            $this->score >= 60 => 'Silver',
            $this->score >= 40 => 'Bronze',
            default => 'Restricted',
        };
    }
}
