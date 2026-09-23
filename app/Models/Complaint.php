<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    protected $fillable = [
        'user_id',
        'assigned_to',
        'title',
        'description',
        'type',
        'status',
        'resolution_notes',
        'resolved_at',
        'escalated_at',
    ];

    protected $casts = [
        'status'       => ComplaintStatus::class,
        'type'         => ComplaintType::class,
        'resolved_at'  => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
