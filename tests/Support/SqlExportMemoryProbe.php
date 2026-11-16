<?php

use App\Models\User;
use App\Services\Administration\SqlExports;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// A separate PHP process makes the memory bound independent of PHPUnit's accumulated allocations.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DB_TABLE_PREFIX' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$root = sys_get_temp_dir().'/cte-memory-'.bin2hex(random_bytes(6));
$app->useStoragePath($root);
$app->make(Kernel::class)->bootstrap();
try {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Isolated memory database required.');
    }
    Artisan::call('migrate', ['--force' => true]);
    Schema::create('memory_probe', function (Blueprint $table) {
        $table->id();
        $table->text('value');
    });
    $actor = User::factory()->create(['is_system_admin' => true])->refresh();
    $count = min(20000, max(500, (int) ($argv[1] ?? 500)));
    for ($offset = 0; $offset < $count; $offset += 500) {
        DB::table('memory_probe')->insert(array_fill(0, min(500, $count - $offset), ['value' => str_repeat('ş', 512)]));
    }
    $before = memory_get_usage(true);
    memory_reset_peak_usage();
    $path = app(SqlExports::class)->dataFile($actor, 'memory_probe');
    $peak = memory_get_peak_usage(true) - $before;
    echo json_encode(['extraPeak' => $peak, 'bytes' => filesize($path)], JSON_THROW_ON_ERROR);
} finally {
    File::deleteDirectory($root);
}
