<?php

namespace App\Console\Commands;

use App\Services\Imports\ImportConflict;
use App\Services\Imports\OrganizationImporter;
use App\Services\Imports\OrganizationSnapshot;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class ImportOrganization extends Command
{
    protected $signature = 'portal:import-organization {file : storage/app/private/imports altındaki JSON dosyası} {--apply : Ayrı hedef veritabanına atomik aktar}';

    protected $description = 'Birim/unvan/kullanıcı snapshotını doğrular; varsayılan yalnız ön kontroldür';

    public function handle(OrganizationImporter $importer): int
    {
        $root = realpath(storage_path('app/private/imports'));
        $path = realpath((string) $this->argument('file'));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            $this->error('Dosya private/imports altında okunabilir bir JSON olmalı.');

            return self::FAILURE;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->error('Aktarım dosyası okunamadı.');

            return self::FAILURE;
        }
        try {
            $raw = stream_get_contents($stream, 16 * 1024 * 1024 + 1);
        } finally {
            fclose($stream);
        }
        if ($raw === false || strlen($raw) > 16 * 1024 * 1024) {
            $this->error('Aktarım dosyası en fazla16 MiB olabilir.');

            return self::FAILURE;
        }
        try {
            $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                $this->error('Aktarım dosyası nesne biçiminde olmalı.');

                return self::FAILURE;
            }
            $snapshot = OrganizationSnapshot::fromArray($input);
            $this->table(['Veri grubu', 'Kayıt sayısı'], array_map(
                static fn (string $table, int $count): array => [$table, $count],
                array_keys($snapshot->counts()), array_values($snapshot->counts()),
            ));
            if (! $this->option('apply')) {
                $this->info('Ön kontrol geçti; veritabanına bağlanılmadı ve kayıt yazılmadı.');

                return self::SUCCESS;
            }
            $result = $importer->apply($snapshot);
            $this->info('Aktarım tamamlandı: eklenen='.$result['inserted'].', değişmeden kalan='.$result['unchanged']);

            return self::SUCCESS;
        } catch (ValidationException|JsonException $error) {
            $this->error('Aktarım dosyası doğrulanamadı; biçim, ilişki ve parola özeti kontrol edilmeli.');
        } catch (ImportConflict $error) {
            $this->error($error->getMessage());
        } catch (Throwable $error) {
            $this->error('Aktarım tamamlanamadı; hedef ayarlarını/şemayı kontrol edin. Kısmi kayıtlar geri alındı.');
        }

        return self::FAILURE;
    }
}
