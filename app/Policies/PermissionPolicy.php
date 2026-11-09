<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function check(User $user, Permission|string $permission): bool
    {
        $permissionKey = $permission instanceof Permission ? $permission->key : $permission;

        return $user->hasPermission($permissionKey);
    }
}
