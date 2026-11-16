<?php

namespace App\Modules\Console\Models;

use App\Enums\StaffRole;
use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stage 2 C2: `employees` is Console's table and this model now lives in its module.
 *
 * The explicit `$table` is new on the move: a namespace change must never be able to change which table a row
 * lands in. Its relations are self-relations (`creator`, `managedEmployees`) plus the kernel `GuardsLazyLoading`
 * trait; `refreshTokens()` was removed in sec 353 because it pointed at Identity's `staff_refresh_tokens`.
 */
class Employee extends Authenticatable
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $table = 'employees';

    protected $fillable = [
        'username',
        'email',
        'password',
        'first_name',
        'last_name',
        'role',
        'is_active',
        'created_by',
        'token_version',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role' => StaffRole::class,
        'is_active' => 'boolean',
        'token_version' => 'integer',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function managedEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'created_by');
    }

    // Stage 2 C2: `refreshTokens()` (hasMany StaffRefreshToken) is REMOVED. It had NO consumer, and since
    // sec 301 moved `StaffRefreshToken` into Identity it had been pointing at a class that no longer exists -
    // a latent defect nothing called and no test could see, found by the new `ClassReferenceIntegrityTest`
    // ratchet. Identity's StaffJwtService queries its own table directly.

    // ── Role helpers ──────────────────────────────────────────────────────────

    public function isSystemAdmin(): bool
    {
        return $this->role === StaffRole::SYSTEM_ADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === StaffRole::ADMIN;
    }

    public function isSupportAgent(): bool
    {
        return $this->role === StaffRole::SUPPORT_AGENT;
    }

    /**
     * Whether this employee has authority over the given target employee.
     */
    public function canManage(self $target): bool
    {
        // No one can manage themselves.
        if ($this->id === $target->id) {
            return false;
        }

        return $this->role->canManage($target->role);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
