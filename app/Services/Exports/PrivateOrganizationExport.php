<?php

namespace App\Services\Exports;

use RuntimeException;
use Throwable;

final class PrivateOrganizationExport
{
    public function write(string $name, array $result): string
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $name)) {
            throw new RuntimeException('Çıktı adı yalnız harf/rakam/_/- içerebilir.');
        }
        $root = storage_path('app/private/imports');
        if (! is_dir($root) && ! mkdir($root, 0700, true)) {
            throw new RuntimeException('Private çıktı dizini hazırlanamadı.');
        }
        // Refuse redirected storage paths; exports contain password hashes.
        $canonical = realpath($root);
        $normalize = static function (string $path): string {
            $path = rtrim(str_replace('\\', '/', $path), '/');

            return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        };
        if ($canonical === false || $normalize($canonical) !== $normalize($root)) {
            throw new RuntimeException('Private çıktı yolu sembolik bağlantı içeremez.');
        }
        $directory = $root.DIRECTORY_SEPARATOR.$name;
        if (file_exists($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('Çıktı adı mevcut; üzerine yazılmaz.');
        }
        try {
            foreach (['snapshot', 'report'] as $part) {
                $json = json_encode($result[$part], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if (strlen($json) > 16 * 1024 * 1024) {
                    throw new RuntimeException('Çıktı sınırı aşıldı.');
                }
                $temp = $directory.DIRECTORY_SEPARATOR.$part.'.partial';
                $stream = fopen($temp, 'xb');
                if ($stream === false) {
                    throw new RuntimeException('Private dosya oluşturulamadı.');
                }
                try {
                    if (! chmod($temp, 0600) || fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                        throw new RuntimeException('Private dosya güvenli yazılamadı.');
                    }
                } finally {
                    fclose($stream);
                }
                if (! rename($temp, $directory.DIRECTORY_SEPARATOR.$part.'.json')) {
                    throw new RuntimeException('Private dosya tamamlanamadı.');
                }
            }
        } catch (Throwable $error) {
            foreach (['snapshot.partial', 'snapshot.json', 'report.partial', 'report.json'] as $file) {
                if (is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                    unlink($directory.DIRECTORY_SEPARATOR.$file);
                }
            }
            rmdir($directory);
            throw $error;
        }

        return $directory.DIRECTORY_SEPARATOR.'snapshot.json';
    }
}
