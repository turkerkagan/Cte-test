<?php

namespace App\Support;

class ProductionSecurity
{
    public static function checks(): array
    {
        return [
            'app_debug_disabled' => config('app.debug') === false,
            'session_driver_file' => config('session.driver') === 'file',
            'cache_store_file' => config('cache.default') === 'file',
            'queue_connection_sync' => config('queue.default') === 'sync',
            'session_lifetime_30' => (int) config('session.lifetime') === 30,
            'session_encrypted' => config('session.encrypt') === true,
            'session_http_only' => config('session.http_only') === true,
            'session_same_site_strict' => config('session.same_site') === 'strict',
            'safe_log_level' => in_array(config('logging.channels.stack.level', env('LOG_LEVEL', 'debug')), ['warning', 'error', 'critical', 'alert', 'emergency'], true),
        ];
    }

    public static function passes(): bool
    {
        return ! in_array(false, self::checks(), true);
    }
}
