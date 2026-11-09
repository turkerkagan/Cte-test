<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionCache
{
    private const GLOBAL_VERSION_KEY = 'permission-cache:global-version';

    public static function remember(User $user, string $permissionKey, Closure $resolver): bool
    {
        if (! $user->exists) {
            return (bool) $resolver();
        }

        $globalVersion = self::version(self::GLOBAL_VERSION_KEY);
        $userVersion = self::version(self::userVersionKey($user->getKey()));
        $cacheKey = sprintf(
            'permission-cache:user:%s:global:%s:user:%s:permission:%s',
            $user->getKey(),
            $globalVersion,
            $userVersion,
            sha1($permissionKey),
        );

        return (bool) Cache::remember($cacheKey, now()->addMinutes(15), fn () => (bool) $resolver());
    }

    public static function flushForUser(User $user): void
    {
        if ($user->exists) {
            self::flushForUserId($user->getKey());
        }
    }

    public static function flushForUserId(int|string|null $userId): void
    {
        if ($userId !== null) {
            self::bump(self::userVersionKey($userId));
            DB::afterCommit(fn () => self::bump(self::userVersionKey($userId)));
        }
    }

    public static function flushAll(): void
    {
        self::bump(self::GLOBAL_VERSION_KEY);
        DB::afterCommit(fn () => self::bump(self::GLOBAL_VERSION_KEY));
    }

    private static function version(string $key): int
    {
        Cache::add($key, 1, now()->addYears(10));

        return (int) Cache::get($key, 1);
    }

    private static function bump(string $key): void
    {
        Cache::add($key, 1, now()->addYears(10));

        Cache::increment($key);
    }

    private static function userVersionKey(int|string $userId): string
    {
        return 'permission-cache:user:'.$userId.':version';
    }
}
