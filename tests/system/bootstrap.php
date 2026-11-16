<?php

use Illuminate\Contracts\Console\Kernel;

// Opt-in isolated HTTP test runtime. Never accepts an application/source DB name.
$directory = realpath(getenv('CTE_SYSTEM_DIRECTORY') ?: '');
$windows = PHP_OS_FAMILY === 'Windows';
$private = $directory !== false && preg_match('/^cte-system-[A-Za-z0-9]+$/D', basename(str_replace('\\', '/', $directory)));
$allowed = $private && ($windows
    ? strcasecmp((string) realpath(dirname($directory)), (string) realpath(sys_get_temp_dir())) === 0
    : preg_match('~^/(?:private/)?tmp/cte-system-[A-Za-z0-9]+$~D', $directory));
if (! $allowed) {
    throw new RuntimeException('Private cte-system-* test directory required.');
}
$settings = json_decode(file_get_contents($directory.'/runtime.json'), true, 32, JSON_THROW_ON_ERROR);
$transport = $windows ? ($settings['socket'] === '' && ($settings['host'] ?? '') === '127.0.0.1' && is_int($settings['port'] ?? null) && $settings['port'] > 1024 && $settings['port'] <= 65535) : $settings['socket'] === $directory.'/mysql.sock';
if ($settings['database'] !== 'cte' || ! $transport) {
    throw new RuntimeException('Only the disposable system schema and private transport are allowed.');
}
foreach (['APP_ENV' => 'system-test', 'APP_DEBUG' => 'false', 'APP_KEY' => $settings['key'], 'APP_URL' => $settings['url'], 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $settings['database'], 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) ($settings['port'] ?? 3306), 'DB_SOCKET' => $settings['socket'], 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_TABLE_PREFIX' => '', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'SESSION_COOKIE' => 'cte_system_session', 'SESSION_SECURE_COOKIE' => 'false', 'APP_CONFIG_CACHE' => $directory.'/config-cache.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->make(Kernel::class)->bootstrap();

return [$app, $directory, $settings];
