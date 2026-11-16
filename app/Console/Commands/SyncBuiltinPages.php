<?php

namespace App\Console\Commands;

use App\Services\Access\BuiltinPageCatalog;
use Illuminate\Console\Command;

final class SyncBuiltinPages extends Command
{
    protected $signature = 'portal:sync-pages';

    protected $description = 'Eksik sabit sayfa tanımlarını ekler; mevcut tanım ve yetkilere dokunmaz.';

    public function handle(BuiltinPageCatalog $catalog): int
    {
        $this->info($catalog->sync().' eksik sayfa tanımı eklendi.');

        return self::SUCCESS;
    }
}
