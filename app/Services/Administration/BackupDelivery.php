<?php

namespace App\Services\Administration;

use RuntimeException;

final class BackupDelivery
{
    /** Publish a complete, hash-verified copy; incomplete copies never use the final name. */
    public function deliver(string $source, string $target, string $name): void
    {
        abort_if(is_link($target), 422, 'Yedek hedefi sembolik bağlantı olamaz.');
        abort_if(file_exists($target) && ! is_dir($target), 422, 'Yedek hedefi bir dizin olmalıdır.');
        if (! is_dir($target) && ! mkdir($target, 0700, true) && ! is_dir($target)) {
            throw new RuntimeException('Yedek hedefi oluşturulamadı.');
        }
        if (realpath(dirname($source)) === realpath($target)) {
            return;
        }
        $destination = $target.'/'.$name;
        abort_if(is_link($destination), 422, 'Yedek dosyası sembolik bağlantı olamaz.');
        $checksum = hash_file('sha256', $source);
        if (is_file($destination) && hash_equals($checksum, hash_file('sha256', $destination))) {
            return;
        }
        $temporary = $target.'/.delivery-'.bin2hex(random_bytes(12));
        try {
            if (! copy($source, $temporary) || ! chmod($temporary, 0600)
                || ! hash_equals($checksum, hash_file('sha256', $temporary))) {
                throw new RuntimeException('Hedef kopya doğrulanamadı; ana yedek korundu.');
            }
            if (is_file($destination) && ! unlink($destination)) {
                throw new RuntimeException('Eksik hedef kopya değiştirilemedi.');
            }
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Hedef kopya yayımlanamadı; ana yedek korundu.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
