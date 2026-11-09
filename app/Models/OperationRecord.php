<?php

namespace App\Models;

use App\Services\DutyRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationRecord extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'data' => 'array',
            'is_published' => 'boolean',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            Institution::whereKey($this->institution_id)->lockForUpdate()->firstOrFail();
            $current = $this->exists ? static::withTrashed()->whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if ($current?->is_closed && ! $this->is_closed) {
                throw ValidationException::withMessages(['is_closed' => 'Kapatılmış kayıt yeniden açılamaz.']);
            }
            if ($this->starts_at && $this->ends_at && $this->ends_at->lt($this->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'Bitiş başlangıçtan önce olamaz.']);
            }
            if ($this->is_closed && ! $current?->is_closed) {
                $this->closed_at ??= now();
            }
            if ($this->module_code === 'nobet') {
                app(DutyRules::class)->validate($this);
            }

            return parent::save($options);
        }, 3);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)->where('is_closed', false);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function readUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads', 'announcement_id', 'user_id')
            ->withTimestamps()
            ->withPivot('read_at');
    }

    public function markAnnouncementRead(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $record = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->module_code === 'duyurular' && $record->is_published && ! $record->is_closed, 404);
            DB::table('announcement_reads')->insertOrIgnore([
                'announcement_id' => $record->id, 'user_id' => $user->id,
                'read_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'operation_record_members', 'operation_record_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function privateFiles(): HasMany
    {
        return $this->hasMany(OperationPrivateFile::class, 'operation_record_id');
    }

    public function inmate(): BelongsTo
    {
        return $this->belongsTo(InmateFile::class, 'inmate_file_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeModule(Builder $query, string $moduleCode): Builder
    {
        return $query->where('module_code', $moduleCode);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $term = '%'.trim($term).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('title', 'like', $term)
                ->orWhere('record_type', 'like', $term)
                ->orWhere('status_code', 'like', $term)
                ->orWhere('body', 'like', $term)
                ->orWhereHas('inmate', fn (Builder $query) => $query->where('full_name', 'like', $term)->orWhere('identity_number', 'like', $term));
        });
    }
}
