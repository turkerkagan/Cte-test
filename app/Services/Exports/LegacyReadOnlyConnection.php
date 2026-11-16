<?php

namespace App\Services\Exports;

use Closure;
use PDO;
use RuntimeException;

final class LegacyReadOnlyConnection
{
    public function run(array $settings, Closure $read): mixed
    {
        $database = $settings['database'] ?? '';
        $host = $settings['host'] ?? '';
        $port = (string) ($settings['port'] ?? '');
        $socket = $settings['socket'] ?? '';
        if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $database)
            || str_starts_with(strtolower($database), 'cte_next_')
            || ! is_string($host) || ! preg_match('/^[a-zA-Z0-9.:-]+$/D', $host)
            || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || ! is_string($socket) || preg_match('/[;\x00-\x1f]/', $socket)
            || empty($settings['username'])) {
            throw new RuntimeException('Kaynak bağlantısı ayrı CTE_SOURCE_DB_* ayarlarında tanımlanmalı; yeni hedef kaynak olamaz.');
        }
        $transport = $socket === '' ? 'host='.$host.';port='.$port : 'unix_socket='.$socket;
        $pdo = new PDO('mysql:'.$transport.';dbname='.$database.';charset=utf8mb4', $settings['username'], $settings['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            return $read($pdo, $database);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function assertSchema(PDO $pdo, string $database, string $table, array $required, array $optional = []): array
    {
        $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $engine->execute([$database, $table]);
        if (strtoupper((string) $engine->fetchColumn()) !== 'INNODB') {
            throw new RuntimeException('Kaynak tablo eksik veya InnoDB değil.');
        }
        $schema = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $schema->execute([$database, $table]);
        $actual = $schema->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($required, $actual) || array_diff($actual, array_merge($required, $optional))) {
            throw new RuntimeException('Kaynak şema sözleşmesi farklı; alan eşlemesi incelenmeli.');
        }

        return $actual;
    }
}
