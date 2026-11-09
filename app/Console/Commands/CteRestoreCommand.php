<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class CteRestoreCommand extends Command
{
    protected $signature = 'cte:restore {path} {--confirm=}';

    protected $description = 'Hash doğrulaması, bakım modu, ön yedek ve rollback ile CTE Portal yedeğini geri yükler.';

    public function handle(BackupService $service): int
    {
        if ($this->option('confirm') !== 'RESTORE_BACKUP') {
            $this->error('Geri yükleme engellendi. --confirm=RESTORE_BACKUP kullanın.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $verification = $service->verifyArchive($path);
        if (! ($verification['valid'] ?? false)) {
            foreach ($verification['errors'] ?? ['Yedek doğrulanamadı.'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $wasDown = app()->isDownForMaintenance();
        $safeToOpen = true;
        $preBackup = null;
        Artisan::call('down', ['--retry' => 60]);

        try {
            $preBackup = $service->createArchive(storage_path('app/backups'), 'restore-preflight');
            $this->info('İşlem öncesi yedek: '.$preBackup['path']);
            $safeToOpen = false;
            $result = $service->restoreArchive($path);
            $safeToOpen = true;
            $this->info('Geri yükleme tamamlandı.');
            $this->info('SHA-256: '.$result['sha256']);
            $this->info('Private dosya: '.$result['private_files']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Geri yükleme başarısız: '.$exception->getMessage());
            $this->warn('İşlem öncesi yedeğe dönülüyor.');

            try {
                if (! $preBackup) {
                    throw new \RuntimeException('Ön yedek oluşturulamadı; geri yükleme başlamadı.');
                }
                $service->restoreArchive($preBackup['path']);
                $safeToOpen = true;
                $this->info('Rollback tamamlandı.');
            } catch (Throwable $rollbackException) {
                $this->error('Rollback başarısız: '.$rollbackException->getMessage());
            }

            return self::FAILURE;
        } finally {
            if ($safeToOpen && ! $wasDown) {
                Artisan::call('up');
            } elseif (! $safeToOpen) {
                $this->error('Veri tutarlılığı doğrulanamadı. Uygulama bakım modunda bırakıldı.');
            }
        }
    }
}
