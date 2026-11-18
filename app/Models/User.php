<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

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

    // ── RV-12: one account-status rule for every entry point ────────────────
    /**
     * Is this account banned RIGHT NOW?
     *
     * `users.status` overloads three meanings (-1 banned / 0 logged-out / 1 active),
     * and a temporary ban also carries `ban_expires_at`. Before this method the rule
     * lived in three copies that disagreed: the middleware honoured the expiry (and
     * auto-lifted it) while LoginController and GoogleController rejected ANY
     * status === -1 unconditionally. A banned user's tokens were revoked at ban time,
     * so the middleware's auto-lift can never run for them — it only executes on an
     * AUTHENTICATED request. Result: a temporary ban that expired still locked the
     * user out forever, because logging in is the only door left and that door ignored
     * the expiry.
     *
     * This is the single source of truth: banned means status -1 AND NOT an expired
     * temporary ban. Callers must not re-implement the condition.
     */
    public function isBannedNow(): bool
    {
        if ((int) $this->status !== -1) {
            return false;
        }

        return ! $this->banHasExpired();
    }

    /**
     * A temporary ban whose end time has passed is no longer in force.
     * Permanent bans (or a missing expiry) never self-lift.
     */
    public function banHasExpired(): bool
    {
        return $this->ban_type === 'temporary'
            && $this->ban_expires_at !== null
            && now()->greaterThan($this->ban_expires_at);
    }

    /**
     * The SQL twin of `isBannedNow()`, so a COUNT or a list cannot drift from the
     * single-row answer.
     *
     * RV-42a: "suspended" is what the USER is told when login is refused -
     * `LoginController:83` blocks on `isBannedNow()` and returns `ACCOUNT_BANNED`
     * with the message "Your account has been suspended". So "suspended" means
     * BANNED, which is `status = -1`, and it is NOT `status = 0`: `0` is
     * LOGGED_OUT, it is what every self-registration starts as
     * (`SignupController:144`) and what `BanService::unban()` deliberately writes
     * back (`BanService:86`, "returns to LOGGED_OUT (not ACTIVE)"). Admin screens
     * that counted `status = 0` as suspended were therefore counting every
     * signed-out and every unbanned account.
     *
     * `ban_type` and `ban_expires_at` are both NULLABLE (see the add-ban-fields
     * migration), so the expiry test has to mirror `banHasExpired()` exactly
     * rather than with a plain `>`: a NULL `ban_expires_at` does NOT expire, and
     * SQL `ban_type != 'temporary'` is not true for NULL either.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeBannedNow(Builder $query): Builder
    {
        return $query->where('status', -1)->where(function (Builder $q) {
            $q->whereNull('ban_type')
                ->orWhere('ban_type', '!=', 'temporary')
                ->orWhere(function (Builder $t) {
                    $t->where('ban_type', 'temporary')
                        ->where(function (Builder $e) {
                            $e->whereNull('ban_expires_at')
                                ->orWhere('ban_expires_at', '>', now());
                        });
                });
        });
    }

    // ── Relationships ────────────────────────────────────────────────────────
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    // Stage 2 C2: `rides()` (hasMany Ride) is REMOVED. It had exactly one live consumer - Console's
    // driver-profile presenter - which now queries Ride itself, so keeping it would only have been a
    // kernel relation pointing at a model that is moving into the Ride module (the cross-module edge
    // the ownership ratchets forbid). The two remaining mentions in tests are comments.

    // Stage 2 C2: `photos()` (hasMany Photo) is REMOVED - Identity owns the `photos` table and the
    // model moved into its module. Its last two readers were dead eager loads whose screens never read
    // a document (byte-identical `admin/drivers` and `admin/passenger-full-profile` snapshots); both
    // lines are gone, so no kernel relation points at the moved class.

    // Stage 2 C2: `bookings()` (hasMany Booking) is REMOVED - it had no consumer at all. Every
    // `->bookings` in the tree belongs to a Ride (Ride::bookings), to the RideCancelled event's own
    // property, or to a seeder's `$this->bookings`; no User query eager-loaded it and no test
    // referenced `users.bookings`. Keeping a kernel relation to a model that is moving into the Ride
    // module would only have been an edge with nothing on the other end.

    // Stage 2 C1: the five reverse relations to Communication's models (conversations, sentMessages,
    // notifications, userNotifications, pushTokens) are gone, together with the unread-count accessors
    // that used them. The authenticated principal does not own another module's data: their three call
    // sites all lived in Communication's own NotificationController, which now asks this module's
    // UnreadNotificationCounter directly - so the emitted `unread_count` is unchanged and this file
    // keeps no edge into the module, neither to a model nor to a contract.
    // Stage 2 C2: `receivedRatings()` (hasMany UserRating) is REMOVED, owner ruling 2026-11-17. `user_ratings`
    // is Identity's table, so the relation had to go before the class could move into the module - and its only
    // consumers were the `average_rating` accessor below (now gone too), Identity's own `UserRatingReader`, and
    // one mechanism assertion. The batched read is `UserRatingReader::averageForUsers()`, one query per page.
    // Stage 2 C1: wallet() is gone with the other reverse relations. Money owns wallets, and readers
    // ask Money\Contracts\WalletDirectory (batched) instead of the principal.

    // ── Accessors ────────────────────────────────────────────────────────────
    // Stage 2 C1: the two unread-count accessors were removed with the reverse relations. They had three
    // call sites, all in Communication's own NotificationController, which now asks this module's
    // UnreadNotificationCounter directly - so the emitted `unread_count` is unchanged and the kernel
    // keeps no edge into the module, neither to a model nor to a contract.

    // Stage 2 C2: `getAverageRatingAttribute()` is REMOVED with the relation it read (owner ruling
    // 2026-11-17). A rating is another module's data: the resources now take a batched map from Identity's
    // `UserRatingReader::averageForUsers()` (`withRatings()` on `RideResource` / `BookingResource`), so the
    // kernel principal no longer answers a question about `user_ratings` at all - not with a relation, and not
    // with a fallback query per row. The AF-4 defect this accessor fixed (every driver shown as rating 0) is
    // pinned by `RideSearchServiceTest::test_search_results_render_the_real_driver_rating_not_zero`.

    // Stage 2 C2: `getTotalRatingsAttribute()` is REMOVED - it had no consumer. Every `total_ratings`
    // in the tree is read off Identity's `UserRatingStats` DTO (or a response array built from it),
    // never off a User.

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
