<?php

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Employee extends Authenticatable
{
    use HasFactory;

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

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(StaffRefreshToken::class);
    }

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
