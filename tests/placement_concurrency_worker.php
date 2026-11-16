<?php

use App\Models\User;
use App\Services\Housing\PersonPlacement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

/** CLI-only worker; accepts disposable schemas exclusively. */
if (PHP_SAPI !== 'cli' || ! preg_match('/^cte_test_g03_[a-f0-9]{12}$/D', getenv('DB_DATABASE') ?: '')) {
    exit(1);
}
// Pin test settings before bootstrap can load local .env values.
$testSettings = [];
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_SOCKET', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_TABLE_PREFIX'] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $testSettings[$key] = $value;
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql']);
foreach (['database' => 'DB_DATABASE', 'host' => 'DB_HOST', 'port' => 'DB_PORT', 'unix_socket' => 'DB_SOCKET', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'url' => 'DB_URL', 'prefix' => 'DB_TABLE_PREFIX'] as $setting => $key) {
    config(['database.connections.mysql.'.$setting => $testSettings[$key] ?? '']);
}
try {
    $deadline = microtime(true) + 15;
    while (! is_file($argv[4])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
    $actor = User::findOrFail((int) $argv[1]);
    app(PersonPlacement::class)->save($actor, (int) $argv[2], ['status_id' => 2, 'block_id' => 1, 'room_id' => 1, 'bed_id' => (int) $argv[3], 'revision' => 0, 'status_revision' => 0]);
    echo '200';
} catch (ValidationException $e) {
    echo array_key_exists('bed_id', $e->errors()) ? '422' : 'error';
} catch (Throwable $e) {
    echo 'error';
}
