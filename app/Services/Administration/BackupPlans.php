<?php

namespace App\Services\Administration;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BackupPlans
{
    public function targets(): array
    {
        $targets = ['local' => ['label' => 'Sunucu yedek alanı', 'path' => app(ManagedDatabase::class)->root()], 'archive' => ['label' => 'İkinci arşiv alanı', 'path' => storage_path('app/private/administration/backup-archive')]];
        foreach (app(ManagementSettings::class)->read('backup_targets', ['items' => []])['values']['items'] as $item) {
            if ($item['is_active']) {
                $targets[$item['id']] = ['label' => $item['name'], 'path' => storage_path('app/private/administration/backup-targets/'.$item['directory'])];
            }
        }

        return $targets;
    }

    public function saveTargets(User $actor, array $input): void
    {
        $d = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'items' => ['present', 'array', 'list', 'max:30'], 'items.*' => ['array:id,name,directory,is_active'], 'items.*.id' => ['required', 'regex:/^target_[a-z0-9_-]{1,40}$/D', 'distinct'], 'items.*.name' => ['required', 'string', 'max:100'], 'items.*.directory' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,99}$/D', 'distinct'], 'items.*.is_active' => ['required', 'boolean']])->validate();
        $available = ['local', 'archive', ...array_column(array_filter($d['items'], fn ($r) => $r['is_active']), 'id')];
        foreach ($this->read()['values']['items'] as $plan) {
            if ($plan['enabled'] && ! in_array($plan['target'], $available)) {
                throw ValidationException::withMessages(['items' => 'Bu hedefin etkin yedek planını önce kapatın veya başka hedefe taşıyın.']);
            }
        }
        app(ManagementSettings::class)->save($actor, 'backup_targets', ['items' => $d['items']], $d['revision']);
    }

    public function read(): array
    {
        $state = app(ManagementSettings::class)->read('backup_plans', ['items' => []]);
        $runs = app(ManagementSettings::class)->read('backup_plan_runs', ['items' => []])['values']['items'];
        foreach ($state['values']['items'] as &$plan) {
            $plan = $this->normalize($plan);
            $run = $runs[$plan['id']] ?? [];
            $plan['last_run'] = $run['at'] ?? null;
            $plan['status'] = $run['status'] ?? 'Henüz çalışmadı';
            $plan['retry_at'] = $run['retry_at'] ?? null;
            $plan['attempts'] = (int) ($run['attempts'] ?? 0);
            $plan['last_local_success'] = $run['last_local_success'] ?? null;
            $plan['last_target_success'] = $run['last_target_success'] ?? null;
            $plan['next_run'] = $plan['enabled'] ? ($plan['retry_at'] ?? $this->next($plan, CarbonImmutable::now())->toIso8601String()) : null;
        }unset($plan);

        return $state + ['targetState' => app(ManagementSettings::class)->read('backup_targets', ['items' => []]), 'targets' => array_map(fn ($t) => $t['label'], $this->targets())];
    }

    public function save(User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'items' => ['present', 'array', 'list', 'max:30'],
            'items.*' => ['array:id,name,target,frequency,time,weekday,monthday,keep,enabled'], 'items.*.id' => ['required', 'regex:/^[a-z0-9_-]{1,40}$/D', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:100'], 'items.*.target' => ['required', 'in:'.implode(',', array_keys($this->targets()))],
            'items.*.frequency' => ['required', 'in:daily,weekly,monthly'], 'items.*.time' => ['required', 'date_format:H:i'], 'items.*.weekday' => ['required', 'integer', 'between:1,7'],
            'items.*.monthday' => ['required', 'integer', 'between:1,28'], 'items.*.keep' => ['required', 'integer', 'between:1,365'], 'items.*.enabled' => ['required', 'boolean']])->validate();
        $plans = array_map(fn (array $plan) => $this->normalize($plan), $data['items']);
        foreach ($plans as $plan) {
            $this->next($plan, CarbonImmutable::now());
        }
        app(ManagementSettings::class)->save($actor, 'backup_plans', ['items' => $plans], $data['revision']);
    }

    private function normalize(array $plan): array
    {
        return array_replace($plan, ['weekday' => (int) $plan['weekday'], 'monthday' => (int) $plan['monthday'],
            'keep' => (int) $plan['keep'], 'enabled' => (bool) $plan['enabled']]);
    }

    private function next(array $plan, CarbonImmutable $after): CarbonImmutable
    {
        for ($n = 0; $n <= 370; $n++) {
            $date = $after->startOfDay()->addDays($n)->setTimeFromTimeString($plan['time']);
            if ($date->lte($after)) {
                continue;
            }
            if ($plan['frequency'] === 'weekly' && $date->dayOfWeekIso !== $plan['weekday']) {
                continue;
            }
            if ($plan['frequency'] === 'monthly' && $date->day !== $plan['monthday']) {
                continue;
            }

            return $date;
        }
        throw new RuntimeException('Plan tarihi hesaplanamadı.');
    }

    public function run(User $actor, string $id, bool $scheduled = false): ?array
    {
        $database = app(ManagedDatabase::class);
        $lock = fopen($database->root().'/.schedule-lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Yedek planı kilidi açılamadı.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Yedek planı kilitlenemedi.');
            }
            $plan = collect($this->read()['values']['items'])->firstWhere('id', $id);
            abort_if(! $plan, 404);
            $settings = app(ManagementSettings::class);
            $state = $settings->read('backup_plan_runs', ['items' => []]);
            $previous = $state['values']['items'][$id] ?? [];
            if ($scheduled && ! $this->due($plan, $previous)) {
                return null;
            }

            $run = array_replace($previous, [
                'at' => now()->toIso8601String(), 'status' => 'Bekliyor', 'retry_at' => null,
                'attempts' => ($previous['status'] ?? null) === 'Başarılı' ? 0 : (int) ($previous['attempts'] ?? 0),
                'backup_id' => null,
                'last_successful_backup_id' => $previous['last_successful_backup_id'] ?? (($previous['status'] ?? null) === 'Başarılı' ? ($previous['backup_id'] ?? 0) : 0),
            ]);
            try {
                // Mark the attempt before creating files, including a manual rerun after today's success.
                $this->saveRun($actor, $id, $run, $state);
                $backup = $this->pendingBackup($actor, $id, $previous);
                if ($backup === null) {
                    $backup = $database->create($actor, 'plan:'.$id);
                    $run['last_local_success'] = now()->toIso8601String();
                } else {
                    $run['last_local_success'] = $backup['created_at'];
                }
                $run['backup_id'] = $backup['id'];
                // Persist the local copy before delivery. A failed delivery must reuse it.
                $this->saveRun($actor, $id, $run, $state);
                $source = $database->backup($actor, $backup['id']);
                $target = $this->targets()[$plan['target']]['path'];
                app(BackupDelivery::class)->deliver($source['path'], $target, $backup['name']);
                $this->prune($actor, $plan, $target);

                $run = array_replace($run, [
                    'date' => now()->toDateString(), 'at' => now()->toIso8601String(),
                    'status' => 'Başarılı', 'attempts' => 0, 'retry_at' => null,
                    'last_target_success' => now()->toIso8601String(),
                    'last_successful_backup_id' => $backup['id'],
                ]);
                $this->saveRun($actor, $id, $run, $state);

                return $backup;
            } catch (\Throwable $error) {
                $attempts = min(1000, $run['attempts'] + 1);
                $run = array_replace($run, [
                    'at' => now()->toIso8601String(), 'status' => 'Başarısız', 'attempts' => $attempts,
                    'retry_at' => now()->addMinutes(min(60, 5 * (2 ** min(4, $attempts - 1))))->toIso8601String(),
                ]);
                $this->saveRun($actor, $id, $run, $state);
                throw $error;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function due(array $plan, array $previous): bool
    {
        if (! $plan['enabled']) {
            return false;
        }
        if (($previous['status'] ?? null) !== 'Başarılı' && isset($previous['retry_at'])) {
            return CarbonImmutable::now()->gte(CarbonImmutable::parse($previous['retry_at']));
        }
        if (($previous['status'] ?? null) === 'Bekliyor') {
            return true;
        }
        $today = CarbonImmutable::now()->startOfDay()->setTimeFromTimeString($plan['time']);

        return CarbonImmutable::now()->gte($today)
            && ($previous['date'] ?? null) !== $today->toDateString()
            && ($plan['frequency'] !== 'weekly' || $today->dayOfWeekIso === $plan['weekday'])
            && ($plan['frequency'] !== 'monthly' || $today->day === $plan['monthday']);
    }

    private function pendingBackup(User $actor, string $id, array $previous): ?array
    {
        // Also recover a local copy created immediately before a process interruption.
        $completed = $previous['last_successful_backup_id'] ?? (($previous['status'] ?? null) === 'Başarılı' ? ($previous['backup_id'] ?? 0) : 0);
        $row = DB::table('administration_backups')->whereNull('archived_at')
            ->where('summary->reason', 'plan:'.$id)
            ->where('id', '>', $completed)
            ->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        app(ManagedDatabase::class)->backup($actor, $row->id);

        return ['id' => $row->id, 'name' => $row->name, 'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'summary' => json_decode($row->summary, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function saveRun(User $actor, string $id, array $run, array &$state): void
    {
        $state['values']['items'][$id] = $run;
        app(ManagementSettings::class)->save($actor, 'backup_plan_runs', $state['values'], $state['revision']);
        $state = app(ManagementSettings::class)->read('backup_plan_runs', ['items' => []]);
    }

    private function prune(User $actor, array $plan, string $target): void
    {
        $database = app(ManagedDatabase::class);
        $old = DB::table('administration_backups')->whereNull('archived_at')
            ->where('summary->reason', 'plan:'.$plan['id'])->orderByDesc('id')
            ->skip($plan['keep'])->take(10000)->get(['id', 'name']);
        foreach ($old as $entry) {
            $expired = $database->backup($actor, $entry->id)['path'];
            // Retention runs only after a verified replacement has reached the target.
            DB::transaction(function () use ($actor, $entry, $expired, $target, $database) {
                $remote = $target.'/'.$entry->name;
                if ($remote !== $expired && (is_link($remote) || (is_file($remote) && ! unlink($remote)))) {
                    throw new RuntimeException('Eski hedef yedeği temizlenemedi.');
                }
                $database->archive($actor, $entry->id);
                if (! unlink($expired)) {
                    throw new RuntimeException('Eski yerel yedek temizlenemedi.');
                }
            });
        }
    }
}
