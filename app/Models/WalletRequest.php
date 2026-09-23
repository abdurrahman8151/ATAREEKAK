<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRequest extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',        // 'charge' | 'withdraw'
        'amount',
        'status',      // 'pending' | 'approved' | 'rejected'
        'user_notes',
        'admin_notes',
        'processed_by',
        'processed_by_employee_id',
        'processed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * The staff employee who approved/rejected this request, if any.
     */
    public function processedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by_employee_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCharge(): bool
    {
        return $this->type === 'charge';
    }

    public function isWithdraw(): bool
    {
        return $this->type === 'withdraw';
    }
}
