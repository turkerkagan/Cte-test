<?php

namespace App\Models;

use App\Support\PermissionCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    protected static function booted(): void
    {
        static::saved(function (self $override): void {
            PermissionCache::flushForUserId($override->user_id);
        });

        static::deleted(function (self $override): void {
            PermissionCache::flushForUserId($override->user_id);
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
