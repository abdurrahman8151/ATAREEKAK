<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileComment extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'profile_id',
        'user_id',      // actual column name in DB (was assumed commenter_id — wrong)
        'comment',
        'ride_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** The user who wrote this comment. FK is user_id in profile_comments. */
    public function commenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Stage 2 C2: `ride()` (belongsTo Ride) is REMOVED - it had no consumer. The `ride_id` column is still
    // written when a comment is earned by a completed ride, and the eligibility check that used to read a
    // ride reads it through the BOOKING (`Booking::whereHas('ride', ...)` in ProfileInteractionService), so
    // nothing ever loaded this relation.
}
