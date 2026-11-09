<?php

namespace App\Models;

use App\Support\PermissionCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role_id) {
                return;
            }

            $roleCode = $user->is_protected_superadmin ? 'superadmin' : 'standart';
            $user->role_id = Role::firstOrCreate(
                ['institution_id' => $user->institution_id, 'code' => $roleCode],
                ['name' => $user->is_protected_superadmin ? 'Ana Yönetici' : 'Standart Personel', 'is_active' => true],
            )->id;
        });

        static::updating(function (User $user): void {
            if (! $user->getOriginal('is_protected_superadmin')) {
                return;
            }

            $protectedAttributesChanged = ! $user->is_active
                || ! $user->is_protected_superadmin
                || $user->isDirty('role_id')
                || $user->isDirty('login_name');

            if ($protectedAttributesChanged) {
                throw new LogicException('Korunan ana yönetici hesabının güvenlik nitelikleri değiştirilemez.');
            }
        });

        static::saved(function (User $user): void {
            if ($user->wasChanged(['role_id', 'is_active', 'is_protected_superadmin'])) {
                PermissionCache::flushForUser($user);
            }
        });

        static::deleting(function (User $user): void {
            if ($user->is_protected_superadmin) {
                throw new LogicException('Korunan ana yönetici hesabı silinemez.');
            }
        });

        static::deleted(function (User $user): void {
            PermissionCache::flushForUser($user);
        });
    }

    protected $fillable = [
        'institution_id', 'department_id', 'role_id', 'full_name',
        'personnel_number', 'login_name', 'email', 'password', 'is_active',
        'is_protected_superadmin', 'must_change_password', 'last_login_at',
        'last_activity_at', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_protected_superadmin' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function hasPermission(string $key): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->is_protected_superadmin) {
            return true;
        }

        return PermissionCache::remember($this, $key, function () use ($key): bool {
            $override = $this->permissionOverrides()
                ->whereHas('permission', fn ($query) => $query->where('key', $key))
                ->value('allowed');

            if ($override !== null) {
                return (bool) $override;
            }

            $role = $this->role()->where('is_active', true)->first();

            return $role?->permissions()->where('key', $key)->exists() ?? false;
        });
    }
}
