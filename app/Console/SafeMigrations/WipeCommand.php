<?php

namespace App\Console\SafeMigrations;

use App\Services\Imports\ImportTargetGuard;

class WipeCommand extends \Illuminate\Database\Console\WipeCommand
{
    public function handle()
    {
        app(ImportTargetGuard::class)->assertCommandSafe('db:wipe', $this->option('database'));

        return parent::handle();
    }
}
