<?php

namespace App\Models;

use App\Models\Concerns\GuardsAssignmentTransitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousingAssignment extends Model
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

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
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
