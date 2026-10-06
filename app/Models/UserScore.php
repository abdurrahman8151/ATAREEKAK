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
     * Apply a delta to the score, clamping to [0, 100].
     *
     * RV-11 (R2 sec 67): this docblock claimed "[0, 200]" - the ceiling the owner rejected when
     * they refused the 200/150/100 tier scale in un2. The code always clamped at 100; only the
     * comment disagreed, and a wrong ceiling in a comment is how the rejected scale kept being
     * quoted. ScoreService::MIN_SCORE / MAX_SCORE carry the same bounds on the other mutation
     * path, which until this pass had no ceiling at all.
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
