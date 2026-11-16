<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase can restore a different cached PDO after app creation.
        // Register on the final connection, including after namespace tests purge it.
        if ($this->app->environment('testing') && config('database.default') === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('FLOOR', static fn ($value) => $value === null ? null : floor((float) $value), 1);
        }
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        // PHP's Windows SQLite build may omit optional SQL math functions.
        // SQLite is only an in-memory testing target; production remains MariaDB.
        if ($app->environment('testing') && config('database.default') === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('FLOOR', static fn ($value) => $value === null ? null : floor((float) $value), 1);
        }

        return $app;
    }
}
