<?php

namespace App\Services\Security;

/** Shared with standalone recovery; intentionally has no framework dependencies. */
final class MasterAdministrator
{
    public static function fromLegacy(array $user): bool
    {
        return (bool) ($user['is_system_admin'] ?? false)
            && mb_strtolower(trim((string) ($user['username'] ?? '')), 'UTF-8') === 'türker';
    }

    public static function restore(array $users): array
    {
        foreach ($users as &$user) {
            $user['is_master_admin'] = array_key_exists('is_master_admin', $user)
                ? (bool) $user['is_master_admin'] : self::fromLegacy($user);
        }

        return $users;
    }
}
