<?php

namespace App\Models;

use App\Support\StateDefinitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InmateFile extends Model
{
    use SoftDeletes;

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $current = $this->exists ? static::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if ($current && $current->institution_id !== (int) $this->institution_id && ($this->housingAssignments()->exists() || $this->workAssignments()->exists() || $this->unitRecords()->exists())) {
                throw ValidationException::withMessages(['institution_id' => 'Geçmişi bulunan dosyanın kurumu doğrudan değiştirilemez.']);
            }
            StateDefinitions::validate($this->getAttributes(), $current?->getAttributes() ?? []);
            if (in_array(mb_strtolower((string) $this->status_code), ['pasif', 'passive', 'arsiv', 'arşiv', 'tahliye', 'tahliye edildi', 'nakil'], true)) {
                $this->is_active = false;
            }
            $saved = parent::save($options);
            if ($saved && $current && ! $this->is_active) {
                foreach ([$this->housingAssignments(), $this->workAssignments()] as $relation) {
                    foreach ($relation->whereNull('ended_at')->get() as $assignment) {
                        $assignment->update(['ended_at' => max(today()->toDateString(), $assignment->started_at->toDateString())]);
                    }
                }
            }

            return $saved;
        }, 3);
    }

    public function scopeOnLeave(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereHas('unitRecords', fn ($q) => $q->where('module_code', 'izin')->where('is_closed', false)->whereDate('occurred_at', '<=', today()))
                ->orWhere(fn ($q) => $q->whereDoesntHave('unitRecords', fn ($q) => $q->where('module_code', 'izin'))->whereIn('status_code', ['ozel-izin', 'özel-izin', 'ÖZEL İZİN']));
        });
    }

    protected static function booted(): void
    {
        static::creating(function (InmateFile $file): void {
            $file->registered_at ??= today();
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
            'entered_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function housingAssignments(): HasMany
    {
        return $this->hasMany(HousingAssignment::class)->latest('started_at');
    }

    public function activeHousingAssignment(): HasMany
    {
        return $this->housingAssignments()->whereNull('ended_at');
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class)->latest('started_at');
    }

    public function activeWorkAssignment(): HasMany
    {
        return $this->workAssignments()->whereNull('ended_at');
    }

    public function privateFiles(): HasMany
    {
        return $this->hasMany(InmatePrivateFile::class)->latest();
    }

    public function unitRecords(): HasMany
    {
        return $this->hasMany(UnitRecord::class)->latest('occurred_at')->latest();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $term = '%'.trim($term).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('full_name', 'like', $term)
                ->orWhere('identity_number', 'like', $term)
                ->orWhere('legacy_id', 'like', $term);
        });
    }
}
