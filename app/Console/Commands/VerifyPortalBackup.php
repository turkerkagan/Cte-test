<?php

namespace App\Console\Commands;

use App\Services\Operations\PortalBackup;
use Illuminate\Console\Command;
use Throwable;

final class VerifyPortalBackup extends Command
{
    protected $signature = 'portal:verify-backup {archive}';

    protected $description = 'Yedek bütünlüğünü veri yazmadan kontrol eder';

    public function handle(PortalBackup $backup): int
    {
        try {
            $manifest = $backup->verify($this->argument('archive'));
            $this->info(count($manifest['tables']).' tablo ve '.count($manifest['entries']).' dosya doğrulandı.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Yedek bütünlüğü doğrulanamadı.');

            return self::FAILURE;
        }
    }
}
