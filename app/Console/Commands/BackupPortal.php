<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalState;
use App\Services\Operations\PortalBackup;
use Illuminate\Console\Command;
use Throwable;

final class BackupPortal extends Command
{
    protected $signature = 'portal:backup {directory} {--mysqldump=mysqldump}';

    protected $description = 'Bakım modunda portal namespace ve özel dosyalarını checksum doğrulanmış arşive alır';

    public function handle(PortalBackup $backup, OperationalState $state): int
    {
        try {
            $path = $backup->create($this->argument('directory'), $this->option('mysqldump'));
            $state->result('backup', true);
            $this->info('Doğrulanan özel yedek: '.$path);

            return self::SUCCESS;
        } catch (Throwable) {
            $state->result('backup', false);
            $this->error('Yedek başarısız. Migration/güncelleme devam ettirilmemelidir. Bakım modu, özel klasör ve veritabanı aracı kontrol edilmeli.');

            return self::FAILURE;
        }
    }
}
