<?php

namespace App\Services\Administration;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ManagementAccess
{
    public function actor(User $actor, bool $lock = false): User
    {
        if ($lock) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
        }
        $query = User::whereKey($actor->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($fresh === null || (int) $fresh->session_version !== (int) $actor->session_version, 403);
        Gate::forUser($fresh)->authorize('manage-modules');

        return $fresh;
    }
}
