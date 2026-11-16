<?php

namespace App\Console\SafeMigrations;

use App\Services\Imports\ImportTargetGuard;

class FreshCommand extends \Illuminate\Database\Console\Migrations\FreshCommand
{
    public function handle()
    {
        app(ImportTargetGuard::class)->assertCommandSafe('migrate:fresh', $this->option('database'));

        return parent::handle();
    }
}
