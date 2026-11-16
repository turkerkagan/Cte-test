<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagementSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AdministrationBackup extends Command
{
    protected $signature = 'cte:administration-backup {--scheduled}';

    protected $description = 'Yönetim panelinin şifreli veri yedeğini oluşturur.';

    public function handle(): int
    {
        if ($this->option('scheduled')) {
            $plans = app(BackupPlans::class);
            $state = $plans->read();
            $items = $state['values']['items'];
            if ($items !== [] || $state['revision'] > 0) {
                $actor = User::where('is_system_admin', true)->where('is_active', true)->where('must_change_password', false)->first();
                if (! $actor) {
                    return self::FAILURE;
                }$failed = false;
                foreach ($items as $plan) {
                    try {
                        $plans->run($actor, $plan['id'], true);
                    } catch (\Throwable $e) {
                        report($e);
                        $failed = true;
                    }
                }

                return $failed ? self::FAILURE : self::SUCCESS;
            }
        }
        $settings = app(ManagementSettings::class);
        $plan = $settings->read('backup_plan', ['enabled' => false, 'time' => '02:00', 'keep' => 14])['values'];
        $last = $settings->read('backup_schedule', ['date' => ''])['values']['date'];
        if ($this->option('scheduled') && (! $plan['enabled'] || now()->format('H:i') < $plan['time'] || $last === now()->toDateString())) {
            return self::SUCCESS;
        }
        $actor = User::where('is_system_admin', true)->where('is_active', true)->where('must_change_password', false)->orderBy('id')->first();
        if (! $actor) {
            $this->error('Etkin yönetici bulunamadı.');

            return self::FAILURE;
        }
        try {
            $backup = app(ManagedDatabase::class)->create($actor, $this->option('scheduled') ? 'scheduled' : 'command');
            if ($this->option('scheduled')) {
                $state = $settings->read('backup_schedule');
                $settings->save($actor, 'backup_schedule', ['date' => now()->toDateString()], $state['revision']);
                foreach (DB::table('administration_backups')->whereNull('archived_at')->orderByDesc('id')->skip($plan['keep'])->take(10000)->pluck('id') as $id) {
                    app(ManagedDatabase::class)->archive($actor, $id);
                }
            }
            $this->info('Yedek oluşturuldu: '.$backup['name']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Yedek tamamlanamadı; önceki yedekler korundu. Ayrıntıları sunucu günlüğünden kontrol edin.');

            return self::FAILURE;
        }
    }
}
