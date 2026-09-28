<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // AF-4 (app-future audit): HasApiTokens (Sanctum) removed. The owner
    // decision was "JWT for everything": no route uses auth:sanctum, nothing
    // calls createToken(), and T2-9 deliberately deleted the one call site
    // (GoogleController). The trait advertised a token API the app never
    // serves; three auth systems with a documented boundary is honest, a
    // third one that is unused is not.
    use HasFactory, Notifiable;

    // status: -1 = banned | 0 = logged out | 1 = active

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'gender',
        'address',
        'google_id',
        'avatar',
        'status',
        'token_version',
        'is_verified_passenger',
        'is_verified_driver',
        'verification_status',
        'wallet_id',
        'email_verified_at',
        // ── Ban fields ──────────────────────────────────────────────────────
        'ban_reason',
        'ban_type',          // 'permanent' | 'temporary'
        'banned_at',
        'ban_expires_at',
        'banned_by',
        // ── Identity ────────────────────────────────────────────────────────
        'national_id',       // set by admin/system_admin during verification approval only
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'token_version' => 'integer',
        'banned_at' => 'datetime',
        'ban_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    // ── Auth ────────────────────────────────────────────────────────────────
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

    // ── Relationships ────────────────────────────────────────────────────────
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function rides()
    {
        return $this->hasMany(Ride::class, 'driver_id');
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function notifications()
    {
        return $this->belongsToMany(Notification::class, 'user_notifications')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function pushTokens()
    {
        return $this->hasMany(PushNotificationToken::class);
    }

    public function unreadNotifications()
    {
        return $this->userNotifications()->unread()->with('notification');
    }

    public function givenRatings()
    {
        return $this->hasMany(UserRating::class, 'rater_id');
    }

    public function receivedRatings()
    {
        return $this->hasMany(UserRating::class, 'rated_user_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────
    public function getUnreadNotificationCountAttribute()
    {
        return $this->userNotifications()->unread()->count();
    }

    public function getUnreadNotificationsCountAttribute()
    {
        return $this->notifications()->whereNull('read_at')->count();
    }

    public function getAverageRatingAttribute()
    {
        // AF-4: rating is shown in the ride/booking API responses. This used to
        // call ->avg() unconditionally, i.e. one query per row — which is why
        // presenters avoided it and instead read a nonexistent driver_rating
        // column (always 0). When the relation is eager-loaded (the search and
        // list paths do this in one batched query), average the loaded rows in
        // memory; the lazy query remains the fallback for single-model paths.
        return ($this->relationLoaded('receivedRatings')
            ? $this->receivedRatings->avg('rating')
            : $this->receivedRatings()->avg('rating')) ?? 0;
    }

    public function getTotalRatingsAttribute()
    {
        return $this->receivedRatings()->count();
    }

    public function getIsBannedAttribute(): bool
    {
        return $this->status == -1;
    }

    public function getAccountStatusAttribute(): string
    {
        return match ((int) $this->status) {
            -1 => 'banned',
            0 => 'logged_out',
            1 => 'active',
            default => 'unknown',
        };
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
