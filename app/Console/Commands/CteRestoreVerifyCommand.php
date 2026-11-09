<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class CteRestoreVerifyCommand extends Command
{
    protected $signature = 'cte:restore-verify {path}';

    protected $description = 'Verify a CTE Portal backup archive before restore.';

    public function handle(BackupService $service): int
    {
        $result = $service->verifyArchive((string) $this->argument('path'));
        if (! $result['exists']) {
            $this->error('Archive not found.');

            return self::FAILURE;
        }

        $this->info('Archive exists.');
        $this->info('Size: '.$result['size']);
        $this->info('SHA-256: '.$result['sha256']);

        if (! ($result['valid'] ?? false)) {
            foreach ($result['errors'] ?? [] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Manifest and file hashes are valid.');

        return self::SUCCESS;
    }
}
