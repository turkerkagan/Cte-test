<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$app, $directory] = require __DIR__.'/runtime.php';
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite') {
    throw new RuntimeException('Unsafe test database.');
}
Artisan::call('migrate', ['--force' => true]);
User::factory()->create(['username' => 'synthetic-browser', 'password' => 'SyntheticMaintenance!2026', 'is_system_admin' => true]);
