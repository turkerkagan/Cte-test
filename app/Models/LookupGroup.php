<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LookupGroup extends Model
{
    protected $table = 'definition_groups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LookupItem::class, 'definition_group_id')->orderBy('sort_order')->orderBy('label');
    }
}
