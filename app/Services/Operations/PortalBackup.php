<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final class PortalBackup
{
    public function create(string $directory, string $dumpBinary): string
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('Tutarlı dosya/veri yedeği için bakım modu ve durmuş yazıcı görevler gerekir.');
        }
        $directory = $this->privateDirectory($directory);
        $temporary = $directory.'/work-'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Yedek çalışma klasörü oluşturulamadı.');
        }
        $credentials = $temporary.'/connection.cnf';
        $zipPath = $temporary.'/backup.zip';
        $zip = new ZipArchive;
        try {
            $settings = DB::connection()->getConfig();
            $tables = $this->tables($settings);
            $counts = [];
            $contentHashes = [];
            foreach ($tables as $table) {
                $counts[$table] = (int) DB::selectOne('SELECT COUNT(*) AS n FROM `'.$table.'`')->n;
                $contentHashes[$table] = $this->fingerprint($table);
            }
            $this->credentials($credentials, $settings);
            $this->run([$dumpBinary, '--defaults-file='.$credentials, '--single-transaction', '--skip-lock-tables', '--skip-add-locks', '--skip-comments', '--no-tablespaces', '--hex-blob', '--result-file='.$temporary.'/database.sql', $settings['database'], ...$tables]);
            if (! is_file($temporary.'/database.sql') || filesize($temporary.'/database.sql') === 0) {
                throw new RuntimeException('Veritabanı yedeği boş.');
            }
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Yedek arşivi oluşturulamadı.');
            }
            $entries = [];
            $add = function (string $path, string $entry) use ($zip, &$entries): void {
                if (! $zip->addFile($path, $entry)) {
                    throw new RuntimeException('Yedek dosyası eklenemedi.');
                }
                $entries[$entry] = ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
            };
            $add($temporary.'/database.sql', 'database.sql');
            if (! is_file(base_path('.env'))) {
                throw new RuntimeException('APP_KEY korunması için yapılandırma dosyası gerekli.');
            }
            $add(base_path('.env'), 'configuration.env');
            $files = storage_path('app');
            if (is_dir($files)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($files, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($files) + 1));
                    if (str_starts_with($relative, 'private/operations/')) {
                        continue;
                    }
                    if ($file->isLink()) {
                        throw new RuntimeException('Dosya yedeğinde sembolik bağlantı kabul edilmez.');
                    }
                    if ($file->isFile()) {
                        $add($file->getPathname(), 'files/'.$relative);
                    }
                }
            }
            $manifest = ['format' => 1, 'at' => now()->toIso8601String(), 'prefix' => $settings['prefix'] ?? '', 'tables' => $counts, 'contentHashes' => $contentHashes, 'entries' => $entries, 'sourceCommit' => app(PortalHealth::class)->snapshot()['version']];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('Yedek arşivi kapatılamadı.');
            }
            $this->verify($zipPath);
            $final = $directory.'/portal-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';
            if (! rename($zipPath, $final)) {
                throw new RuntimeException('Yedek kalıcı olarak yazılamadı.');
            }
            chmod($final, 0600);

            return $final;
        } finally {
            foreach (['connection.cnf', 'database.sql', 'backup.zip'] as $name) {
                if (is_file($temporary.'/'.$name)) {
                    unlink($temporary.'/'.$name);
                }
            }
            @rmdir($temporary);
        }
    }

    public function verify(string $archive): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Yedek okunamadı.');
        }
        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false || strlen($raw) > 16 * 1024 * 1024) {
                throw new RuntimeException('Yedek manifesti bulunamadı veya çok büyük.');
            }
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_string($manifest['prefix'] ?? null) || ! is_array($manifest['contentHashes'] ?? null) || ($manifest['format'] ?? null) !== 1 || ! isset($manifest['entries']['database.sql'], $manifest['entries']['configuration.env']) || ! is_array($manifest['tables'] ?? null) || $manifest['tables'] === []) {
                throw new RuntimeException('Yedek manifesti eksik.');
            }
            if ($zip->numFiles !== count($manifest['entries']) + 1) {
                throw new RuntimeException('Arşivde manifest dışı veya tekrarlanan dosya var.');
            }
            foreach ($manifest['entries'] as $name => $expected) {
                if (! in_array($name, ['database.sql', 'configuration.env'], true) && ! preg_match('~^files/(?:[\pL\pN_. -]+/)*[\pL\pN_. -]+$~uD', $name)) {
                    throw new RuntimeException('Arşiv dosya yolu reddedildi.');
                }
                if (str_contains($name, '..') || str_contains($name, '\\')) {
                    throw new RuntimeException('Arşiv dosya yolu reddedildi.');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Yedek dosyası eksik.');
                }
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $stream);
                fclose($stream);
                if ($size !== $expected['bytes'] || ! hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Yedek checksum doğrulaması başarısız.');
                }
            }
            foreach ($manifest['tables'] as $table => $count) {
                if (! is_string($manifest['contentHashes'][$table] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['contentHashes'][$table]) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $table) || ! is_int($count) || $count < 0 || ! str_starts_with($table, $manifest['prefix'])) {
                    throw new RuntimeException('Yedek tablo sözleşmesi reddedildi.');
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function rehearse(string $archive, string $target, string $directory, string $mysqlBinary): array
    {
        if (! preg_match('/^cte_restore_[a-z0-9_]{8,48}$/D', $target)) {
            throw new RuntimeException('Yalnız ayrı cte_restore_* prova hedefi kabul edilir.');
        }
        $manifest = $this->verify($archive);
        $directory = $this->privateDirectory($directory);
        if (count(scandir($directory)) > 3) {
            throw new RuntimeException('Prova klasörü boş olmalıdır.');
        }
        $settings = DB::connection()->getConfig();
        $config = $settings;
        $config['database'] = $target;
        $config['prefix'] = '';
        $config['url'] = null;
        config(['database.connections.portal_restore' => $config]);
        DB::purge('portal_restore');
        // CREATE fails if the target exists. Never drop/overwrite source or another existing schema.
        DB::statement('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $zip = new ZipArchive;
        $credentials = $directory.'/connection.cnf';
        try {
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Prova arşivi okunamadı.');
            }
            foreach (array_keys($manifest['entries']) as $name) {
                $path = $directory.'/'.$name;
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                $input = $zip->getStream($name);
                $output = fopen($path, 'xb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Prova dosyası açılamadı.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                chmod($path, 0600);
            }
            $zip->close();
            $this->credentials($credentials, $settings);
            $input = fopen($directory.'/database.sql', 'rb');
            try {
                $process = new Process([$mysqlBinary, '--defaults-file='.$credentials, '--binary-mode', '--database='.$target]);
                $process->setInput($input);
                $process->setTimeout(1800);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException('Geri yükleme başarısız; yalnız ayrı prova hedefi inceleme için korundu.');
                }
            } finally {
                fclose($input);
            }
            foreach ($manifest['tables'] as $table => $count) {
                if ((int) DB::connection('portal_restore')->selectOne('SELECT COUNT(*) AS n FROM `'.$table.'`')->n !== $count || ! hash_equals($manifest['contentHashes'][$table], $this->fingerprint($table, 'portal_restore'))) {
                    throw new RuntimeException('Prova kayıt sayısı eşleşmedi.');
                }
            }
            foreach ($manifest['entries'] as $name => $expected) {
                if (! hash_equals($expected['sha256'], hash_file('sha256', $directory.'/'.$name))) {
                    throw new RuntimeException('Prova dosya checksum eşleşmedi.');
                }
            }

            return ['status' => 'passed', 'tables' => count($manifest['tables']), 'files' => count($manifest['entries']), 'sourceUnchanged' => true];
        } finally {
            if (is_file($credentials)) {
                unlink($credentials);
            }
            DB::disconnect('portal_restore');
        }
    }

    private function fingerprint(string $table, ?string $connection = null): string
    {
        $db = DB::connection($connection);
        $keys = $db->select('SHOW KEYS FROM `'.$table.'` WHERE Key_name = ?', ['PRIMARY']);
        $columns = array_map(fn ($key) => $key->Column_name, $keys);
        if ($columns === []) {
            $columns = array_map(fn ($column) => $column->Field, $db->select('SHOW COLUMNS FROM `'.$table.'`'));
        }
        $order = implode(',', array_map(fn ($column) => '`'.str_replace('`', '``', $column).'`', $columns));
        $hash = hash_init('sha256');
        foreach ($db->cursor('SELECT * FROM `'.$table.'` ORDER BY '.$order) as $row) {
            $values = array_map(fn ($value) => $value === null ? null : base64_encode((string) $value), (array) $row);
            hash_update($hash, json_encode($values, JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    private function tables(array $settings): array
    {
        $prefix = $settings['prefix'] ?? '';
        $tables = [];
        foreach (DB::select('SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$settings['database']]) as $row) {
            if (! str_starts_with($row->name, $prefix)) {
                continue;
            }
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $row->name) || $row->kind !== 'BASE TABLE' || $row->engine !== 'InnoDB') {
                throw new RuntimeException('Yedek yalnız transaction destekli portal tablolarını kabul eder.');
            }
            $tables[] = $row->name;
        }
        if ($tables === [] || ! in_array($prefix.'portal_installations', $tables, true)) {
            throw new RuntimeException('Hazırlanmış portal şeması bulunamadı.');
        }
        sort($tables);

        return $tables;
    }

    private function privateDirectory(string $directory): string
    {
        if (! is_dir($directory)) {
            throw new RuntimeException('Önceden hazırlanmış özel klasör gerekli.');
        }
        $real = realpath($directory);
        foreach ([realpath(public_path()), realpath(base_path())] as $root) {
            if ($root && ($real === $root || str_starts_with(strtolower($real).DIRECTORY_SEPARATOR, strtolower($root).DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Yedek/prova klasörü uygulama ve web kökünün dışında olmalıdır.');
            }
        }
        @chmod($real, 0700);
        file_put_contents($real.'/.htaccess', "Require all denied\n");

        return $real;
    }

    private function credentials(string $path, array $settings): void
    {
        $text = "[client]\n";
        if (! empty($settings['unix_socket'])) {
            $text .= "protocol=SOCKET\n";
        }
        $keys = ! empty($settings['unix_socket']) ? ['username', 'password', 'unix_socket'] : ['host', 'port', 'username', 'password'];
        foreach ($keys as $key) {
            $name = ['username' => 'user', 'unix_socket' => 'socket'][$key] ?? $key;
            if (isset($settings[$key]) && $settings[$key] !== '') {
                $value = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $settings[$key]);
                $text .= $name.'="'.$value."\"\n";
            }
        }
        file_put_contents($path, $text, LOCK_EX);
        chmod($path, 0600);
    }

    private function run(array $arguments): void
    {
        $process = new Process($arguments);
        $process->setTimeout(1800);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Veritabanı aracı başarısız; tamamlanmış yedek üretilmedi.');
        }
    }
}
