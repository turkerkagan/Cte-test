<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$directory = realpath(getenv('CTE_BROWSER_DIRECTORY') ?: '');
if ($directory === false || ! str_starts_with(basename($directory), 'cte-browser-') || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Isolated browser test directory required.');
}
$settings = json_decode(file_get_contents($directory.'/settings.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $settings['key'], 'APP_URL' => $settings['url'],
    'APP_CONFIG_CACHE' => $directory.'/config.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
    'DB_URL' => '', 'DB_TABLE_PREFIX' => '', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false',
    'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->make(Kernel::class)->bootstrap();
DB::connection()->getPdo()->sqliteCreateFunction('FLOOR', static fn ($value) => $value === null ? null : floor((float) $value), 1);
config(['view.compiled' => $directory.'/storage/framework/views', 'filesystems.disks.local.root' => $directory.'/storage/app/private']);

return [$app, $directory];
