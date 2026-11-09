<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UnitRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'ended_at' => 'date',
            'is_sensitive' => 'boolean',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $inmate = InmateFile::whereKey($this->inmate_file_id)->lockForUpdate()->firstOrFail();
            $current = $this->exists ? static::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if ($current?->is_closed && ! $this->is_closed) {
                throw ValidationException::withMessages(['is_closed' => 'Kapatılmış kayıt yeniden açılamaz.']);
            }
            if ($this->is_closed && ! $current?->is_closed) {
                if ($this->occurred_at?->isAfter(now()->endOfDay())) {
                    throw ValidationException::withMessages(['occurred_at' => 'Gelecekteki kayıt kapatılamaz.']);
                }
                $this->closed_at ??= now();
            }
            if ($this->module_code === 'izin') {
                $data = $this->data;
                Validator::make($data, [
                    'Çıkış' => ['nullable', 'date_format:Y-m-d'],
                    'Dönüş' => ['nullable', 'date_format:Y-m-d'],
                    'Çıkış Saati' => ['nullable', 'date_format:H:i'],
                    'Dönüş Saati' => ['nullable', 'date_format:H:i'],
                ])->validate();
                $this->occurred_at = $data['Çıkış'] ?? $this->occurred_at ?? today();
                $this->ended_at = $data['Dönüş'] ?? $this->ended_at;
                if ($this->ended_at && $this->ended_at->lt($this->occurred_at)) {
                    throw ValidationException::withMessages(['data.Dönüş' => 'Dönüş çıkıştan önce olamaz.']);
                }
                // A missing return is an unbounded interval, not a single day.
                $effectiveEnd = $this->is_closed && $this->closed_at ? $this->closed_at : $this->ended_at;
                $overlap = static::module('izin')->where('inmate_file_id', $inmate->id)
                    ->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
                    ->when($effectiveEnd, fn ($q) => $q->whereDate('occurred_at', '<=', $effectiveEnd))
                    ->where(function ($q): void {
                        $q->where(fn ($q) => $q->where('is_closed', true)->whereNotNull('closed_at')->whereDate('closed_at', '>=', $this->occurred_at))
                            ->orWhere(fn ($q) => $q->where(fn ($q) => $q->where('is_closed', false)->orWhereNull('closed_at'))
                                ->where(fn ($q) => $q->whereNull('ended_at')->orWhereDate('ended_at', '>=', $this->occurred_at)));
                    })->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['data.Çıkış' => 'Başka bir izin kaydıyla tarih çakışması var.']);
                }
            }

            return parent::save($options);
        }, 3);
    }

    protected function data(): Attribute
    {
        return Attribute::make(
            get: function ($value): array {
                $data = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

                return collect($data)->mapWithKeys(function ($item, $key): array {
                    if (! is_string($item) || ! str_starts_with($item, 'encrypted:')) {
                        return [$key => $item];
                    }

                    try {
                        return [$key => decrypt(substr($item, 10))];
                    } catch (\Throwable) {
                        return [$key => 'Şifreli veri okunamadı'];
                    }
                })->all();
            },
            set: function ($value): string {
                $data = is_array($value) ? $value : [];

                return json_encode(collect($data)->mapWithKeys(function ($item, $key): array {
                    $sensitive = str_contains(mb_strtolower((string) $key), 'şifre');
                    if (! $sensitive || $item === null || $item === '' || (is_string($item) && str_starts_with($item, 'encrypted:'))) {
                        return [$key => $item];
                    }

                    return [$key => 'encrypted:'.encrypt((string) $item)];
                })->all(), JSON_UNESCAPED_UNICODE);
            },
        );
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
                ->orWhere('notes', 'like', $term)
                ->orWhereHas('inmate', fn (Builder $query) => $query->where('full_name', 'like', $term)->orWhere('identity_number', 'like', $term));
        });
    }
}
