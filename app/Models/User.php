<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property bool $is_guest
 * @property bool $is_master_admin
 * @property string|null $remember_token
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $locked_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['username', 'registry_number', 'full_name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_system_admin' => 'boolean',
            'is_master_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'session_version' => 'integer',
            'revision' => 'integer',
            'failed_login_attempts' => 'integer',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime', 'last_activity_at' => 'datetime', 'is_guest' => 'boolean',
        ];
    }
}
