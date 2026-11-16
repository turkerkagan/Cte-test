<?php

namespace App\Console\Commands;

use App\Services\Exports\LegacyCorePreflight;
use Illuminate\Console\Command;
use Throwable;

final class CheckCoreSource extends Command
{
    protected $signature = 'portal:check-core-source';

    protected $description = 'Eski hükümlü/yerleşim/seçenek kaynağında yalnız anonim adetlerle taşıma ön kontrolü';

    public function handle(LegacyCorePreflight $preflight): int
    {
        try {
            $this->line(json_encode($preflight->check(config('legacy')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->warn('Bu rapor aktarım onayı değildir; sorunlar eşlenmeden gerçek import yapılmaz.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Kaynak ön kontrolü tamamlanamadı; bağlantı veya şema sözleşmesini kontrol edin. Değerler gizlendi.');

            return self::FAILURE;
        }
    }
}
