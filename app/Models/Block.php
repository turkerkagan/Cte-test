<?php

namespace App\Models;

use App\Models\Concerns\GuardsResourceTransitions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    use GuardsResourceTransitions;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class)->orderBy('sort_order')->orderBy('name');
    }
}
