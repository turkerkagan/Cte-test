<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class CteBackupCommand extends Command
{
    protected $signature = 'cte:backup {--dir=storage/app/backups} {--label=manual}';

    protected $description = 'Create a safe manifest-based CTE Portal backup archive.';

    public function handle(BackupService $service): int
    {
        $result = $service->createArchive(base_path((string) $this->option('dir')), (string) $this->option('label'));
        $this->info('Backup archive: '.$result['path']);
        $this->info('SHA-256: '.$result['sha256']);

        return self::SUCCESS;
    }
}
