<?php

namespace App\Modules\Identity\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffRefreshToken extends Model
{
    /**
     * Stage 2 C1: declared explicitly so the class can move without the rows moving with it.
     */
    protected $table = 'staff_refresh_tokens';

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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->revoked && ! $this->isExpired();
    }
}
