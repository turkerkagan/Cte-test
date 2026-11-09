<?php

namespace App\Console\Commands;

use App\Models\LegacyTransferRun;
use App\Services\LegacyTransferService;
use Illuminate\Console\Command;

class LegacyMigrateAllCommand extends Command
{
    protected $signature = 'cte:legacy-migrate-all {institution_id} {--prefix=legacy_src_} {--confirm=}';

    protected $description = '13 legacy source table imports users, operations, reads, duties, committees, meals and validates inmate matches.';

    public function handle(LegacyTransferService $service): int
    {
        if ($this->option('confirm') !== 'RUN_FULL_IMPORT') {
            $this->error('Tam aktarım engellendi. Ön yedekten sonra --confirm=RUN_FULL_IMPORT kullanın.');

            return self::FAILURE;
        }

        $report = $service->migratePrefixedTables((int) $this->argument('institution_id'), (string) $this->option('prefix'));
        LegacyTransferRun::create([
            'run_type' => 'migrate_all',
            'status' => $report['status'],
            'summary' => ['source_rows' => $report['total_rows'], 'migrated' => $report['migrated'] ?? [], 'inmate_matches' => $report['inmate_matches'] ?? 0],
            'report' => $report,
            'manifest_sha256' => hash('sha256', json_encode($report)),
        ]);

        $this->info('Durum: '.$report['status']);
        $this->info('Kaynak satır: '.$report['total_rows']);
        foreach ($report['migrated'] ?? [] as $table => $count) {
            $this->line($table.': '.$count);
        }
        $this->line('Hükümlü eşleşmesi: '.($report['inmate_matches'] ?? 0).'/'.($report['inmate_source_rows'] ?? 0));

        return $report['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
