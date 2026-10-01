<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'path'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
