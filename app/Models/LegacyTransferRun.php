<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyTransferRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'report' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
