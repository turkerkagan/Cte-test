<?php

namespace App\Console\Commands;

use App\Services\Imports\ImportTargetGuard;
use Illuminate\Console\Command;
use RuntimeException;

final class PortalDoctor extends Command
{
    protected $signature = 'portal:doctor';

    protected $description = 'Portal ortamını veri bağlantısı kurmadan kontrol eder';

    public function handle(): int
    {
        $checks = ['PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>=')];
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip'] as $extension) {
            $checks['PHP '.$extension] = extension_loaded($extension);
        }

        try {
            app(ImportTargetGuard::class)->assertSafe();
            $checks['Yetkili portal veritabanı ve öneksiz tablolar'] = true;
        } catch (RuntimeException $error) {
            $checks['Yetkili portal veritabanı ve öneksiz tablolar'] = false;
        }
        $checks['Uygulama anahtarı'] = filled(config('app.key'));
        $checks['Derlenmiş yerel arayüz'] = is_file(public_path('build/manifest.json'));
        $checks['Geliştirme sunucusuna bağımlılık yok'] = ! is_file(public_path('hot'));
        foreach ([storage_path(), app()->bootstrapPath('cache')] as $index => $directory) {
            $checks[$index === 0 ? 'Storage yazılabilir' : 'Bootstrap cache yazılabilir'] = is_writable($directory);
        }

        $this->table(['Kontrol', 'Sonuç'], array_map(
            static fn (string $label, bool $passed): array => [$label, $passed ? 'OK' : 'EKSİK'],
            array_keys($checks), array_values($checks),
        ));
        $this->line('Veritabanına bağlanılmadı; Windows/Apache açılışı ayrıca doğrulanmalıdır.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
