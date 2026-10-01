<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PLACE IN: app/Models/NoshowReport.php
 */
class NoshowReport extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'ride_id',
        'booking_id',
        'reporter_id',
        'reporter_role',
        'target_id',
        'target_role',
        'payment_method',
        'status',
        'expires_at',
        'resolved_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }
}
