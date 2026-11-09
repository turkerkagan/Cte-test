<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }
}
