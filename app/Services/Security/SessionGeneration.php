<?php

namespace App\Services\Security;

use RuntimeException;

/** Kept outside panel backups so restoring a snapshot cannot revive an old session. */
final class SessionGeneration
{
    public static function current(string $storage): string
    {
        $path = $storage.'/app/private/operations/auth-generation';
        if (! is_file($path)) {
            return '';
        }

        $generation = file_get_contents($path);
        if ($generation === false || ! preg_match('/^[a-f0-9]{64}$/D', $generation)) {
            throw new RuntimeException('Oturum güvenlik bilgisi okunamadı.');
        }

        return $generation;
    }

    public static function rotate(string $storage): void
    {
        $directory = $storage.'/app/private/operations';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Oturum güvenlik alanı oluşturulamadı.');
        }
        $temporary = tempnam($directory, '.auth-');
        if ($temporary === false) {
            throw new RuntimeException('Oturum güvenlik bilgisi hazırlanamadı.');
        }
        try {
            if (file_put_contents($temporary, bin2hex(random_bytes(32)), LOCK_EX) !== 64
                || ! chmod($temporary, 0600) || ! rename($temporary, $directory.'/auth-generation')) {
                throw new RuntimeException('Eski oturumlar güvenli biçimde sonlandırılamadı.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function invalidateRestoredUsers(array $users, array $currentVersions): array
    {
        foreach ($users as &$user) {
            $user['session_version'] = max((int) $user['session_version'], (int) ($currentVersions[$user['id']] ?? 0)) + 1;
            $user['remember_token'] = null;
        }

        return $users;
    }
}
