<?php

namespace App\Console\Commands;

use App\Models\LegacyTransferRun;
use App\Services\LegacyTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LegacyDryRunCommand extends Command
{
    protected $signature = 'cte:legacy-dry-run {sample_json}';

    protected $description = 'Run legacy mapping validation from a JSON sample without writing target records.';

    public function handle(LegacyTransferService $service): int
    {
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
        $report = $service->dryRun($rows);
        $storedReport = ['rows' => $report['rows'], 'valid' => $report['valid'], 'invalid' => $report['invalid'], 'errors' => array_slice($report['errors'], 0, 100)];
        LegacyTransferRun::create(['created_by' => auth()->id(), 'run_type' => 'dry_run', 'status' => $report['invalid'] > 0 ? 'warning' : 'completed', 'summary' => ['rows' => $report['rows'], 'valid' => $report['valid'], 'invalid' => $report['invalid']], 'report' => $storedReport, 'manifest_sha256' => hash('sha256', json_encode($storedReport))]);
        $this->info('Dry-run rows: '.$report['rows']);
        $this->info('Valid: '.$report['valid'].' Invalid: '.$report['invalid']);

        return self::SUCCESS;
    }
}
