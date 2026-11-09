<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class MenuItem extends Model
{
    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            static::orderBy('id')->lockForUpdate()->get(['id']);

            return parent::save($options);
        }, 3);
    }

    protected static function booted(): void
    {
        static::saving(function (self $menu): void {
            $seen = $menu->exists ? [$menu->id] : [];
            $parent = $menu->parent_id;
            while ($parent) {
                if (in_array((int) $parent, $seen, true)) {
                    throw ValidationException::withMessages(['parent_id' => 'Menü ağacında döngü oluşturulamaz.']);
                }
                $seen[] = (int) $parent;
                $parent = self::whereKey($parent)->value('parent_id');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('title');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function canBeRenderedFor(?User $user): bool
    {
        if (! $this->is_active || ! $this->is_visible) {
            return false;
        }

        if ($this->route_name && ! Route::has($this->route_name)) {
            return false;
        }

        if ($this->permission_key && ! $user?->hasPermission($this->permission_key)) {
            return false;
        }

        return true;
    }
}
