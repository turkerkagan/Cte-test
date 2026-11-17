<?php

namespace Cte\Deployment;

use RuntimeException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class RuntimeEnvironment
{
    private const KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_FALLBACK_LOCALE',
        'INSTITUTION_NAME', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        'DB_PASSWORD', 'DB_SOCKET', 'DB_TABLE_PREFIX', 'CTE_HOSTED_DATABASE', 'CTE_TRUST_PROXY',
        'CTE_PYTHON_BINARY', 'SESSION_DRIVER', 'SESSION_COOKIE', 'SESSION_PATH', 'SESSION_DOMAIN',
        'SESSION_LIFETIME', 'SESSION_SECURE_COOKIE', 'SESSION_HTTP_ONLY', 'SESSION_SAME_SITE',
        'SESSION_ENCRYPT', 'CACHE_STORE', 'QUEUE_CONNECTION', 'FILESYSTEM_DISK', 'LOG_CHANNEL',
        'LOG_LEVEL',
    ];

    public static function write(string $root, array $environment): void
    {
        if (empty($environment['APP_KEY'])) {
            throw new RuntimeException('A persistent APP_KEY must be provided.');
        }
        $text = '';
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $environment)) {
                continue;
            }
            $value = (string) $environment[$key];
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new RuntimeException('Multiline runtime configuration is not supported.');
            }
            $text .= $key.'="'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value)."\"\n";
        }
        $temporary = tempnam($root, '.runtime-env-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot prepare runtime configuration.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $text) !== strlen($text)
                || ! rename($temporary, $root.'/.env')) {
                throw new RuntimeException('Cannot save runtime configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    RuntimeEnvironment::write(dirname(__DIR__, 2), getenv());
}
