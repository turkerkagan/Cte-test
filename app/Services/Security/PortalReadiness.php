<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PortalReadiness
{
    public function ready(): bool
    {
        try {
            $installation = DB::table('portal_installations')->where('id', 1)->first();

            return $installation !== null && ($installation->installed_at !== null || User::query()->exists());
        } catch (Throwable $error) {
            // Missing/unconfigured target is an installation state, not a public SQL error.
            return false;
        }
    }
}
