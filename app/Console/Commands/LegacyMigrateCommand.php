<?php

namespace App\Console\Commands;

use App\Models\LegacyTransferRun;
use App\Services\LegacyTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LegacyMigrateCommand extends Command
{
    protected $signature = 'cte:legacy-migrate {sample_json} {institution_id} {--confirm=}';

    protected $description = 'Safely import mapped legacy inmate rows from JSON after explicit confirmation.';

    public function handle(LegacyTransferService $service): int
    {
        if ($this->option('confirm') !== 'RUN_IMPORT') {
            $this->error('Import blocked. Use --confirm=RUN_IMPORT after successful dry-run and backup.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('sample_json');
        if (! File::exists($path)) {
            $this->error('Sample JSON not found.');

            return self::FAILURE;
        }
        $rows = json_decode(File::get($path), true);
        if (! is_array($rows)) {
            $this->error('Sample JSON must be an array.');

            return self::FAILURE;
        }
        $report = $service->migrateRows($rows, (int) $this->argument('institution_id'));
        $storedReport = [
            'rows' => $report['rows'],
            'valid' => $report['valid'],
            'invalid' => $report['invalid'],
            'created' => $report['created'] ?? 0,
            'status' => $report['status'],
            'errors' => array_slice($report['errors'], 0, 100),
        ];
        LegacyTransferRun::create(['created_by' => auth()->id(), 'run_type' => 'migrate', 'status' => $report['status'], 'summary' => ['rows' => $report['rows'], 'created' => $report['created'] ?? 0, 'invalid' => $report['invalid']], 'report' => $storedReport, 'manifest_sha256' => hash('sha256', json_encode($storedReport))]);
        $this->info('Migration status: '.$report['status']);
        $this->info('Created: '.($report['created'] ?? 0));

        return $report['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
