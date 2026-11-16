<?php

namespace App\Console\Commands;

use App\Services\Imports\ImportTargetGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssertEmptyPortal extends Command
{
    protected $signature = 'portal:assert-empty';

    protected $description = 'İlk kurulumdan önce hedef portal namespace boşluğunu veri yazmadan doğrular';

    public function handle(): int
    {
        try {
            app(ImportTargetGuard::class)->assertSafe();
            $settings = DB::connection()->getConfig();
            $prefix = $settings['prefix'] ?? '';
            $tables = DB::select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$settings['database']]);
            foreach ($tables as $table) {
                if (str_starts_with($table->name, $prefix)) {
                    $this->error('Portal hedefi boş değil; ilk kurulum migrationları çalıştırılmadı.');

                    return self::FAILURE;
                }
            }
            $this->info('Portal namespace boş. Eski tablolar değiştirilmedi.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Hedef doğrulanamadı; bağlantı ve namespace kontrol edilmeli.');

            return self::FAILURE;
        }
    }
}
