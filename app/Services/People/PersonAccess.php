<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Access\PageAccess;

final class PersonAccess
{
    public function authorize(User $actor, string $permission = 'giris', bool $lock = false): User
    {
        abort_unless(in_array($permission, ['giris', 'yazma'], true), 403);
        $query = User::query()->whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($current === null || ! $current->is_active || $current->must_change_password, 403);
        abort_unless($current->is_system_admin || app(PageAccess::class)->allows($current, 'hukumlu_kayit', $permission), 403);

        return $current;
    }

    public function canWrite(User $actor): bool
    {
        $current = User::find($actor->id);

        return $current !== null && $current->is_active && ! $current->must_change_password
            && ($current->is_system_admin || app(PageAccess::class)->allows($current, 'hukumlu_kayit', 'yazma'));
    }
}
