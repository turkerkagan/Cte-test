<?php

namespace App\Console\Commands;

use App\Services\Exports\LegacyOrganizationReader;
use App\Services\Exports\PrivateOrganizationExport;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ExportOrganization extends Command
{
    protected $signature = 'portal:export-organization {source : Sabit kaynak eşleme adı} {--write= : Private/imports altında yeni çıktı klasörü adı}';

    protected $description = 'Eski organizasyonu salt okunur kontrol eder; --write ile özel snapshot üretir';

    public function handle(LegacyOrganizationReader $reader, PrivateOrganizationExport $writer): int
    {
        $source = (string) $this->argument('source');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $source)) {
            $this->error('Kaynak eşleme adı geçersiz.');

            return self::FAILURE;
        }
        try {
            $result = $reader->read($source, config('legacy'));
            $this->table(['Veri grubu', 'Kayıt sayısı'], array_map(
                static fn ($table, $count) => [$table, $count],
                array_keys($result['report']['counts']), array_values($result['report']['counts']),
            ));
            foreach (['empty_or_zero_dates_to_null' => 'Boş/sıfır tarih → null', 'deferred_access_columns_non_null' => 'Ayrı yetki aktarımına bırakılan alan'] as $section => $label) {
                foreach ($result['report'][$section] as $field => $count) {
                    $this->line($label.': '.$field.'='.$count);
                }
            }
            $this->warn('Bu çıktı yalnız organizasyonu kapsar; sayfa/özel not yetkileri henüz taşınmaz.');
            if ($this->option('write') !== null) {
                $path = $writer->write((string) $this->option('write'), $result);
                $this->info('Özel snapshot ve kişisel veri içermeyen rapor hazır: '.$path);
            } else {
                $this->info('Salt okunur ön kontrol geçti; dosya oluşturulmadı.');
            }

            return self::SUCCESS;
        } catch (ValidationException $error) {
            $this->error('Kaynak veri doğrulanamadı; değerler gizlendi. Hatalı alanlar: '.implode(', ', array_keys($error->errors())));
        } catch (Throwable $error) {
            $this->error('Dışa aktarım tamamlanamadı; bağlantı, şema ve private çıktı ayarlarını kontrol edin. Kaynağa yazılmadı.');
        }

        return self::FAILURE;
    }
}
