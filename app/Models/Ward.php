<?php

namespace App\Models;

use App\Models\Concerns\GuardsResourceTransitions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends Model
{
    use GuardsResourceTransitions;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class)->orderBy('sort_order')->orderBy('label');
    }
}
