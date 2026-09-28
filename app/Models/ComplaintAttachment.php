<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintAttachment extends Model
{
    protected $fillable = [
        'complaint_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }
}
