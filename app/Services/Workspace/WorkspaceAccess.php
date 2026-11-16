<?php

namespace App\Services\Workspace;

use App\Models\User;

final class WorkspaceAccess
{
    public function actor(User $actor): User
    {
        $fresh = User::find($actor->id);
        abort_unless($fresh && $fresh->is_active && ! $fresh->is_guest && ! $fresh->must_change_password
            && (int) $fresh->session_version === (int) $actor->session_version, 403);

        return $fresh;
    }
}
