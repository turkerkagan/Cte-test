<?php

namespace App\Console\Commands;

use App\Services\DeletionQuarantine;
use Illuminate\Console\Command;

class CteRecoverDeletionsCommand extends Command
{
    protected $signature = 'cte:recover-deletions';

    protected $description = 'Bakım modunda kesilmiş kalıcı silme işlemlerinin karantina dosyalarını uzlaştırır.';

    public function handle(DeletionQuarantine $quarantine): int
    {
        if (! app()->isDownForMaintenance()) {
            $this->error('Önce uygulamayı bakım moduna alın ve çalışan işlemlerin bitmesini bekleyin.');

            return self::FAILURE;
        }
        $this->info($quarantine->recover().' karantina işlemi uzlaştırıldı.');

        return self::SUCCESS;
    }
}
