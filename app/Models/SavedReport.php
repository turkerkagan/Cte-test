<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavedReport extends Model
{
    use SoftDeletes;

    protected $table = 'report_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'filters' => 'array',
            'is_shared' => 'boolean',
        ];
    }
}
