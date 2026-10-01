<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffRefreshToken extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'token',
        'expires_at',
        'revoked',
        'user_agent',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->revoked && ! $this->isExpired();
    }
}
