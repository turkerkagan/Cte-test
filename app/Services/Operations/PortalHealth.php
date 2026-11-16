<?php

namespace App\Services\Operations;

use App\Services\Administration\BackupPlans;
use App\Services\Security\PortalReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PortalHealth
{
    public function snapshot(): array
    {
        $database = false;
        try {
            DB::select('SELECT 1');
            $database = true;
        } catch (Throwable) {
        }
        $version = is_file(base_path('release.json')) ? json_decode(file_get_contents(base_path('release.json')), true) : [];
        $state = app(OperationalState::class)->read();
        $cleanup = null;
        $pendingMigrations = null;
        $backupPlans = [];
        if ($database) {
            if (Schema::hasTable('private_file_deletions')) {
                $queue = DB::table('private_file_deletions')->selectRaw('COUNT(*) as pending, MIN(created_at) as oldest')->first();
                $cleanup = ['pending' => (int) $queue->pending, 'oldest' => $queue->oldest];
            }
            $applied = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $available = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);
            $pendingMigrations = array_values(array_diff($available, $applied));
            $backupPlans = array_map(fn ($plan) => array_intersect_key($plan, array_flip([
                'id', 'name', 'enabled', 'status', 'attempts', 'retry_at', 'last_local_success', 'last_target_success',
            ])), app(BackupPlans::class)->read()['values']['items']);
        }

        return ['version' => $version['sourceCommit'] ?? 'yerel geliştirme', 'php' => PHP_VERSION,
            'database' => $database, 'installed' => app(PortalReadiness::class)->ready(),
            'maintenance' => app()->isDownForMaintenance(), 'debugDisabled' => ! config('app.debug'),
            'assets' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
            'backup' => $state['backup'], 'job' => $state['job'], 'day' => $state['day'] ?? null,
            'backupPlans' => $backupPlans, 'cleanup' => $cleanup, 'pendingMigrations' => $pendingMigrations,
            'scheduler' => $state['scheduler'] ?? null,
            'schedulerHealthy' => $this->schedulerHealthy($state['scheduler'] ?? null),
            'measurementEnabled' => (bool) config('operations.measure_requests'),
            'routes' => collect($state['routes'])->map(fn ($row, $route) => ['route' => $route, ...$row])->sortByDesc('max_ms')->values()->take(30)->all(),
            'institutionAcceptance' => 'Kurum onayı ayrıca kaydedilmelidir.'];
    }

    private function schedulerHealthy(?array $heartbeat): bool
    {
        try {
            $at = isset($heartbeat['at']) ? CarbonImmutable::parse($heartbeat['at']) : null;

            return ($heartbeat['success'] ?? false) && $at !== null
                && $at->betweenIncluded(CarbonImmutable::now()->subMinutes(3), CarbonImmutable::now()->addSeconds(30));
        } catch (Throwable) {
            return false;
        }
    }
}
