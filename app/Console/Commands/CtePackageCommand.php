<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class CtePackageCommand extends Command
{
    protected $signature = 'cte:package {--output=release : Paket klasörü} {--allow-dev : Geliştirme bağımlılıkları varken paketlemeye izin ver}';

    protected $description = 'Create a self-contained offline Windows/XAMPP installation package.';

    private const EXCLUDED_PREFIXES = ['.git/', '.codex/', '.cursor/', '.idea/', '.vscode/', 'node_modules/', 'release/', 'backups/', 'storage/logs/', 'storage/framework/cache/', 'storage/framework/sessions/', 'storage/framework/views/', 'storage/app/private/'];

    private const EXCLUDED_FILES = ['.env', '.env.backup', '.env.production', '.DS_Store', '.phpunit.result.cache', 'database/database.sqlite'];

    public function handle(): int
    {
        foreach (['vendor/autoload.php', 'public/build/manifest.json', '.env.example', 'artisan'] as $required) {
            if (! File::exists(base_path($required))) {
                $this->error("Eksik paket girdisi: {$required}");

                return self::FAILURE;
            }
        }
        if (! $this->option('allow-dev') && File::exists(base_path('vendor/phpunit/phpunit'))) {
            $this->error('vendor içinde geliştirme bağımlılıkları var. Önce composer install --no-dev --optimize-autoloader çalıştırın.');

            return self::FAILURE;
        }

        $output = base_path((string) $this->option('output'));
        File::ensureDirectoryExists($output);
        $path = $output.DIRECTORY_SEPARATOR.'cte-portal-offline-xampp-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Offline paket ZIP dosyası oluşturulamadı.');
        }
        $fileList = $this->buildFileList();
        foreach ($fileList as $relative) {
            $zip->addFile(base_path($relative), 'CtePortal/'.$relative);
        }
        $zip->addFile(base_path('.env.example'), 'CtePortal/.env.example');
        foreach (['storage/app/private', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $directory) {
            $zip->addEmptyDir('CtePortal/'.$directory);
        }
        $zip->addFromString('CtePortal/PAKET-BILGISI.txt', "CTE Portal çevrimdışı XAMPP paketi\nSürüm: 1.0.0-rc\nOluşturma: ".now()->toIso8601String()."\nDosya sayısı: ".count($fileList)."\nKurulum: docs/offline-xampp-package.md\n");
        $zip->close();
        $sha = hash_file('sha256', $path);
        File::put($path.'.sha256', $sha.'  '.basename($path).PHP_EOL);
        $this->info("Offline paket: {$path}");
        $this->info("SHA-256: {$sha}");

        return self::SUCCESS;
    }

    public function buildFileList(): array
    {
        return collect(File::allFiles(base_path()))
            ->map(fn ($file) => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $path) => in_array($path, self::EXCLUDED_FILES, true)
                || str_ends_with($path, '.log') || str_ends_with($path, '.sql') || str_ends_with($path, '.zip')
                || collect(self::EXCLUDED_PREFIXES)->contains(fn (string $prefix) => str_starts_with($path, $prefix)))
            ->sort()->values()->all();
    }

    public function inspectArchive(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['valid' => false, 'errors' => ['Paket ZIP dosyası açılamadı.'], 'files' => 0];
        }

        $errors = [];
        $required = ['CtePortal/artisan', 'CtePortal/vendor/autoload.php', 'CtePortal/public/build/manifest.json', 'CtePortal/.env.example', 'CtePortal/PAKET-BILGISI.txt'];
        foreach ($required as $file) {
            if ($zip->locateName($file) === false) {
                $errors[] = 'Eksik paket dosyası: '.$file;
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = str_starts_with($name, 'CtePortal/') ? substr($name, 10) : $name;
            if (in_array($relative, self::EXCLUDED_FILES, true)
                || str_ends_with($relative, '.sql')
                || str_ends_with($relative, '.log')
                || collect(self::EXCLUDED_PREFIXES)->contains(fn (string $prefix) => str_starts_with($relative, $prefix))) {
                $errors[] = 'Yasaklı paket içeriği: '.$name;
            }
        }

        $files = $zip->numFiles;
        $zip->close();

        return ['valid' => $errors === [], 'errors' => $errors, 'files' => $files, 'sha256' => hash_file('sha256', $path)];
    }
}
