<?php

namespace App\Console\Commands;

use App\Models\LegacyTransferRun;
use App\Services\LegacyTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LegacyCompareCommand extends Command
{
    protected $signature = 'cte:legacy-compare {source_json} {target_json}';

    protected $description = 'Compare source and target legacy transfer counts.';

    public function handle(LegacyTransferService $service): int
    {
        $sourcePath = (string) $this->argument('source_json');
        $targetPath = (string) $this->argument('target_json');
        if (! File::exists($sourcePath) || ! File::exists($targetPath)) {
            $this->error('JSON file not found.');

            return self::FAILURE;
        }
        $source = json_decode(File::get($sourcePath), true);
        $target = json_decode(File::get($targetPath), true);
        if (! is_array($source) || ! is_array($target)) {
            $this->error('Both files must contain JSON objects.');

            return self::FAILURE;
        }
        $report = $service->compareCounts($source, $target);
        $matched = collect($report)->every(fn ($row) => $row['matched']);
        LegacyTransferRun::create(['created_by' => auth()->id(), 'run_type' => 'compare', 'status' => $matched ? 'completed' : 'mismatch', 'summary' => ['matched' => $matched], 'report' => $report, 'manifest_sha256' => hash('sha256', json_encode($report))]);
        $this->info($matched ? 'Counts matched.' : 'Counts mismatch found.');

        return $matched ? self::SUCCESS : self::FAILURE;
    }
}
