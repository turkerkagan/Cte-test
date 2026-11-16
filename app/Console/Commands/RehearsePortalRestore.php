<?php

namespace App\Console\Commands;

use App\Services\Operations\PortalBackup;
use Illuminate\Console\Command;
use Throwable;

final class RehearsePortalRestore extends Command
{
    protected $signature = 'portal:rehearse-restore {archive} {target} {directory} {--mysql=mysql} {--confirm : Yeni ayrı prova şeması ve dosyaları oluştur}';

    protected $description = 'Yedeği yalnız yeni cte_restore_* şemasında ve boş özel klasörde prova eder';

    public function handle(PortalBackup $backup): int
    {
        if (! $this->option('confirm')) {
            $this->error('Yeni ayrı prova hedefi oluşturmak için --confirm gerekli.');

            return self::FAILURE;
        }
        try {
            $result = $backup->rehearse($this->argument('archive'), $this->argument('target'), $this->argument('directory'), $this->option('mysql'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Prova başarısız. Mevcut uygulama veritabanı değiştirilmedi; oluşmuş ayrı prova hedefi inceleme için korunur.');

            return self::FAILURE;
        }
    }
}
