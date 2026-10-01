<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'user_id',
        // RV-23: ride_id + complained_id were written by Noshowservice::handleConflict
        // but never fillable and never a column — Eloquent dropped them in silence, so
        // no-show conflict complaints lost all context. Now columns + fillable below.
        'ride_id',
        'complained_id',
        'assigned_to',
        'title',
        'description',
        'type',
        'status',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'status' => ComplaintStatus::class,
        'type' => ComplaintType::class,
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** RV-23: the ride a complaint refers to (null for manual complaints). */
    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    /** RV-23: the user the complaint is ABOUT (distinct from the submitter). */
    public function complainedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complained_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }
}
