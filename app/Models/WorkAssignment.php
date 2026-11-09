<?php

namespace App\Models;

use App\Models\Concerns\GuardsAssignmentTransitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkAssignment extends Model
{
    use GuardsAssignmentTransitions;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(InmateFile::class, 'inmate_file_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(WorkPlace::class, 'work_place_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
