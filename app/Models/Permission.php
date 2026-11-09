<?php

namespace App\Models;

use App\Support\PermissionCache;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
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
}
