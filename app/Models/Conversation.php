<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest();
    }

    /**
     * Check if a user is a participant in this conversation.
     *
     * OPTIMIZED: When participants are already eager loaded (e.g. from cache),
     * this checks the in-memory collection — zero DB queries.
     * Falls back to a DB exists() only when the relationship wasn't loaded.
     */
    public function isParticipant(User $user): bool
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->contains('id', $user->id);
        }

        return $this->participants()->where('user_id', $user->id)->exists();
    }

    public function getOtherParticipant(User $currentUser): ?User
    {
        if ($this->type !== 'private') {
            return null;
        }

        return $this->participants()
            ->where('user_id', '!=', $currentUser->id)
            ->first();
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
