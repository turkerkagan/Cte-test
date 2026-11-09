<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationPrivateFile extends Model
{
    protected $guarded = [];

    public function operationRecord(): BelongsTo
    {
        return $this->belongsTo(OperationRecord::class, 'operation_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
