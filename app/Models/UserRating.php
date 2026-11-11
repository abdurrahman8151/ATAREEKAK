<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRating extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'rater_id',
        'rated_user_id',
        'rating',
        'ride_id',
    ];

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function ratedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_user_id');
    }

    // Stage 2 C2: `ride()` (belongsTo Ride) is REMOVED - it had no consumer. `ride_id` is still
    // WRITTEN (ProfileInteractionService records it on the row) and still read as a scalar where a
    // rating is joined to a trip; only the relation was dead, and it was the one thing that would
    // have made this Identity model reach for a Ride-owned class once Ride moves.
}
