<?php

namespace App\Modules\Identity\Models;

use App\Models\Concerns\GuardsLazyLoading;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 2 C2: `photos` is Identity's table and this model now lives in its module.
 *
 * The explicit `$table` predates the move and stays: a namespace change must never be able to change
 * which table a row lands in.
 */
class Photo extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $table = 'photos';

    protected $fillable = ['user_id', 'type', 'path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
