<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class PortalLogin
{
    public function verify(string $username, string $password): ?User
    {
        // Same hashing work for unknown and known usernames; no fallback account.
        $dummyHash = Hash::make(bin2hex(random_bytes(32)));

        return DB::transaction(function () use ($username, $password, $dummyHash): ?User {
            $user = User::query()->where('username', $username)->lockForUpdate()->first();
            if ($user === null) {
                // Existing usernames take precedence; ambiguous registry numbers never select an account.
                $matches = User::query()->where('registry_number', $username)->orderBy('id')->limit(2)->lockForUpdate()->get();
                $user = $matches->count() === 1 ? $matches->first() : null;
            }
            $matches = password_verify($password, $user->password ?? $dummyHash);
            if ($user === null || ! $user->is_active) {
                return null;
            }
            if ($user->locked_at !== null && $user->locked_at->addMinutes(config('portal_security.lock_minutes'))->isFuture()) {
                return null;
            }
            if ($user->locked_at !== null) {
                $user->failed_login_attempts = 0;
                $user->locked_at = null;
            }
            if (! $matches) {
                $user->failed_login_attempts++;
                if ($user->failed_login_attempts >= config('portal_security.failed_login_limit')) {
                    $user->locked_at = now();
                }
                $user->save();

                return null;
            }
            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($password);
            }
            $user->failed_login_attempts = 0;
            $user->locked_at = null;
            $user->last_login_at = now();
            $user->save();

            return $user;
        });
    }
}
