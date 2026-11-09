<?php

namespace App\Console\Commands;

use App\Models\LegacyTransferRun;
use App\Services\LegacyTransferService;
use Illuminate\Console\Command;

class LegacyInspectCommand extends Command
{
    protected $signature = 'cte:legacy-inspect {--connection=}';

    protected $description = 'Inspect legacy database tables and columns without importing data.';

    public function handle(LegacyTransferService $service): int
    {
        $report = $service->inspect($this->option('connection') ?: null);
        LegacyTransferRun::create(['created_by' => auth()->id(), 'run_type' => 'inspect', 'status' => 'completed', 'summary' => ['missing' => $report['missing'], 'total_columns' => $report['total_columns']], 'report' => $report, 'manifest_sha256' => hash('sha256', json_encode($report))]);
        $this->info('Legacy inspect completed.');
        $this->info('Missing tables: '.implode(', ', $report['missing']));
        $this->info('Total columns: '.$report['total_columns']);

        return self::SUCCESS;
    }
}
