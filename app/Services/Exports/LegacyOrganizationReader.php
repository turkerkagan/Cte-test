<?php

namespace App\Services\Exports;

use PDO;
use RuntimeException;

final class LegacyOrganizationReader
{
    public function __construct(private readonly LegacyOrganizationAdapter $adapter, private readonly LegacyReadOnlyConnection $connection) {}

    public function read(string $source, array $settings): array
    {
        return $this->connection->run($settings, function (PDO $pdo, string $database) use ($source): array {
            $tables = [];
            foreach (LegacyOrganizationAdapter::COLUMNS as $table => $columns) {
                $required = array_diff($columns, $table === 'kullanicilar' ? ['telefon'] : []);
                $optional = array_merge(array_diff($columns, $required), LegacyOrganizationAdapter::DEFERRED_COLUMNS[$table] ?? []);
                $actual = $this->connection->assertSchema($pdo, $database, $table, $required, $optional);
                $allowed = array_merge($columns, LegacyOrganizationAdapter::DEFERRED_COLUMNS[$table] ?? []);
                $selected = array_intersect($allowed, $actual);
                $sql = 'SELECT `'.implode('`, `', $selected).'` FROM `'.$table.'` ORDER BY id LIMIT 10001';
                $tables[$table] = $pdo->query($sql)->fetchAll();
                if (count($tables[$table]) > 10000) {
                    throw new RuntimeException('Kaynak grup sınırı aşıldı; parçalı aktarım planı gerekir.');
                }
            }
            $result = $this->adapter->adapt($source, $tables);
            $result['report']['source_session_timezone'] = $pdo->query('SELECT @@session.time_zone')->fetchColumn();

            return $result;
        });
    }
}
