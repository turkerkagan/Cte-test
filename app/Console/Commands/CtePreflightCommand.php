<?php

namespace App\Console\Commands;

use App\Services\PreflightService;
use Illuminate\Console\Command;

class CtePreflightCommand extends Command
{
    protected $signature = 'cte:preflight {--json}';

    protected $description = 'Run CTE Portal quality, security, packaging and production readiness checks.';

    public function handle(PreflightService $service): int
    {
        $result = $service->run();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['passed'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result['checks'] as $name => $passed) {
            $this->line(($passed ? '[OK] ' : '[FAIL] ').$name);
        }

        if (! $result['passed']) {
            $this->error('Preflight failed: '.implode(', ', $result['failed']));

            return self::FAILURE;
        }

        $this->info('Preflight passed.');

        return self::SUCCESS;
    }
}
