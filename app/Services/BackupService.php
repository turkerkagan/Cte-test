<?php

namespace App\Services;

use App\Support\PermissionCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public function buildManifest(string $label = 'manual'): array
    {
        return [
            'label' => $label,
            'created_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'database' => config('database.default'),
            'private_storage_files' => $this->privateFiles(),
        ];
    }

    public function createArchive(string $directory, string $label = 'manual'): array
    {
        File::ensureDirectoryExists($directory);
        $name = 'cte_portal_backup_'.now()->format('Ymd_His_u').'_'.bin2hex(random_bytes(4)).'.zip';
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Yedek arşivi oluşturulamadı.');
        }

        $databaseDump = $this->databaseDump();
        $zip->addFromString('database/database.sql', $databaseDump);
        $files = [['archive_name' => 'database/database.sql', 'sha256' => hash('sha256', $databaseDump), 'size' => strlen($databaseDump), 'type' => 'database']];
        foreach ($this->allPrivatePaths() as $privatePath) {
            $contents = Storage::disk('local')->get($privatePath);
            $archiveName = 'private/'.hash('sha256', $privatePath).'.bin';
            $zip->addFromString($archiveName, $contents);
            $files[] = ['archive_name' => $archiveName, 'restore_path' => $privatePath, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'type' => 'private'];
        }
        $manifest = $this->buildManifest($label) + ['format_version' => 2, 'storage_roots' => ['inmate-private-files', 'private/operations'], 'files' => $files];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('README.txt', 'Bu arşiv veritabanı dökümünü, private dosyaları ve SHA-256 bütünlük manifestini içerir. Yalnız yetkili ortamda saklayın.');
        $zip->close();

        return ['path' => $path, 'manifest' => $manifest, 'sha256' => hash_file('sha256', $path)];
    }

    public function verifyArchive(string $path): array
    {
        if (! File::exists($path)) {
            return ['exists' => false, 'valid' => false, 'errors' => ['Arşiv bulunamadı.'], 'sha256' => null, 'size' => 0];
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['exists' => true, 'valid' => false, 'errors' => ['ZIP açılamadı.'], 'sha256' => hash_file('sha256', $path), 'size' => File::size($path)];
        }
        $errors = [];
        $manifestRaw = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            $errors[] = 'Manifest eksik veya geçersiz.';
        } else {
            if (! in_array($manifest['format_version'] ?? null, [1, 2], true)) {
                $errors[] = 'Desteklenmeyen yedek biçimi.';
            }
            if (count(array_filter($manifest['files'], fn ($file) => ($file['type'] ?? '') === 'database' && ($file['archive_name'] ?? '') === 'database/database.sql')) !== 1) {
                $errors[] = 'Veritabanı dökümü manifestte tam bir kez bulunmalıdır.';
            }
            $names = array_column($manifest['files'], 'archive_name');
            if (count($names) !== count(array_unique($names))) {
                $errors[] = 'Manifestte mükerrer arşiv yolu var.';
            }
            foreach ($manifest['files'] as $file) {
                if (($file['type'] ?? '') === 'private' && ! $this->isSafePrivatePath((string) ($file['restore_path'] ?? ''))) {
                    $errors[] = 'Güvensiz private dosya yolu.';
                }
                $contents = isset($file['archive_name']) ? $zip->getFromName($file['archive_name']) : false;
                if (! is_string($contents) || ! hash_equals((string) ($file['sha256'] ?? ''), hash('sha256', $contents))) {
                    $errors[] = 'Dosya bütünlüğü bozuk: '.($file['archive_name'] ?? 'bilinmeyen');
                }
            }
        }
        $zip->close();

        return ['exists' => true, 'valid' => $errors === [], 'errors' => $errors, 'manifest' => $manifest, 'sha256' => hash_file('sha256', $path), 'size' => File::size($path)];
    }

    public function restoreArchive(string $path): array
    {
        $verification = $this->verifyArchive($path);
        if (! ($verification['valid'] ?? false)) {
            throw new RuntimeException('Yedek arşivi doğrulanamadı: '.implode(' ', $verification['errors'] ?? []));
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Yedek arşivi açılamadı.');
        }

        try {
            $databaseSql = $zip->getFromName('database/database.sql');
            if (! is_string($databaseSql) || trim($databaseSql) === '') {
                throw new RuntimeException('Veritabanı dökümü bulunamadı.');
            }

            $privateFiles = collect($verification['manifest']['files'] ?? [])->where('type', 'private');
            foreach ($privateFiles as $file) {
                $restorePath = (string) ($file['restore_path'] ?? '');
                if (! $this->isSafePrivatePath($restorePath)) {
                    throw new RuntimeException('Private dosya geri yükleme yolu eksik veya güvensiz.');
                }
            }

            DB::connection()->getPdo()->exec($databaseSql);
            foreach (($verification['manifest']['format_version'] ?? 1) >= 2 ? ['inmate-private-files', 'private/operations'] : ['inmate-private-files'] as $root) {
                if (! Storage::disk('local')->deleteDirectory($root)) {
                    throw new RuntimeException('Eski private dosyalar temizlenemedi.');
                }
            }
            foreach ($privateFiles as $file) {
                $contents = $zip->getFromName((string) $file['archive_name']);
                if (! is_string($contents)) {
                    throw new RuntimeException('Private dosya arşivden okunamadı.');
                }
                if (! Storage::disk('local')->put((string) $file['restore_path'], $contents)) {
                    throw new RuntimeException('Private dosya geri yüklenemedi.');
                }
            }
        } finally {
            $zip->close();
        }

        PermissionCache::flushAll();

        return [
            'restored' => true,
            'sha256' => $verification['sha256'],
            'database' => true,
            'private_files' => collect($verification['manifest']['files'] ?? [])->where('type', 'private')->count(),
        ];
    }

    private function allPrivatePaths(): array
    {
        return array_merge(Storage::disk('local')->allFiles('inmate-private-files'), Storage::disk('local')->allFiles('private/operations'));
    }

    private function privateFiles(): array
    {
        return collect($this->allPrivatePaths())->map(fn ($path) => ['path_hash' => hash('sha256', $path), 'size' => Storage::disk('local')->size($path), 'sha256' => hash('sha256', Storage::disk('local')->get($path))])->values()->all();
    }

    private function databaseDump(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $tables = match ($driver) {
            'sqlite' => collect($connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))->pluck('name'),
            'mysql', 'mariadb' => collect($connection->select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0]),
            default => throw new RuntimeException("Desteklenmeyen yedek veritabanı sürücüsü: {$driver}"),
        };
        $sql = $driver === 'sqlite' ? "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n" : "SET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($tables as $table) {
            $quotedTable = $driver === 'sqlite' ? '"'.str_replace('"', '""', $table).'"' : '`'.str_replace('`', '``', $table).'`';
            $create = $driver === 'sqlite'
                ? ($connection->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table])->sql ?? null)
                : (array) $connection->selectOne("SHOW CREATE TABLE {$quotedTable}");
            $createSql = is_array($create) ? (array_values($create)[1] ?? null) : $create;
            if ($createSql) {
                $sql .= "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n";
            }
            $columns = $driver === 'sqlite'
                ? collect($connection->select('PRAGMA table_xinfo('.$quotedTable.')'))->filter(fn ($col) => (int) $col->hidden === 0)->pluck('name')->all()
                : collect($connection->select('SHOW COLUMNS FROM '.$quotedTable))->reject(fn ($col) => preg_match('/(?:VIRTUAL|STORED) GENERATED/i', $col->Extra))->pluck('Field')->all();
            $quote = fn ($column) => $driver === 'sqlite' ? '"'.str_replace('"', '""', $column).'"' : '`'.str_replace('`', '``', $column).'`';
            $columnSql = implode(',', array_map($quote, $columns));
            foreach ($connection->table($table)->select($columns)->orderByRaw('1')->cursor() as $row) {
                $values = array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values((array) $row));
                $sql .= "INSERT INTO {$quotedTable} ({$columnSql}) VALUES (".implode(',', $values).");\n";
            }
        }

        if ($driver === 'sqlite') {
            foreach ($connection->select("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name") as $object) {
                $sql .= $object->sql.";\n";
            }
        }

        return $sql.($driver === 'sqlite' ? "COMMIT;\nPRAGMA foreign_keys=ON;\n" : "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    private function isSafePrivatePath(string $path): bool
    {
        return (str_starts_with($path, 'inmate-private-files/') || str_starts_with($path, 'private/operations/'))
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }
}
