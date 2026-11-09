<?php

namespace App\Models;

use App\Support\PermissionCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (): void {
            PermissionCache::flushAll();
        });

        static::deleted(function (): void {
            PermissionCache::flushAll();
        });
    }

    protected $guarded = [];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function syncPermissionsWithCacheClear(array $permissionIds): array
    {
        $changes = $this->permissions()->sync($permissionIds);

        PermissionCache::flushAll();

        return $changes;
    }
}
