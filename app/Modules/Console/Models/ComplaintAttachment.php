<?php

namespace App\Modules\Console\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintAttachment extends Model
{
    // Stage 2 C2: the class moved into the module that owns `complaint_attachments`. The explicit table keeps
    // a namespace change from ever changing which table a row lands in.
    protected $table = 'complaint_attachments';

    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

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
