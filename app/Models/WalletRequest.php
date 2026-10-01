<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRequest extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',        // 'charge' | 'withdraw'
        'amount',
        'status',      // 'pending' | 'approved' | 'rejected' | 'cancelled'
        'user_notes',
        'admin_notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
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
