<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeTestCommand extends Command
{
    protected $signature = 'cte:initialize-test';

    protected $description = 'Initialize an empty test database without resetting existing credentials.';

    public function handle(): int
    {
        if (DB::table('users')->exists()) {
            $this->info('Existing users found; initialization skipped.');

            return self::SUCCESS;
        }

        $password = env('CTE_INITIAL_ADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 16) {
            $this->error('Set CTE_INITIAL_ADMIN_PASSWORD to a random password of at least 16 characters.');

            return self::FAILURE;
        }

        return $this->call('db:seed', ['--force' => true]);
    }
}
