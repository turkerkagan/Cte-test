<?php

namespace App\Services\Imports;

use App\Services\Security\MasterAdministrator;
use Illuminate\Support\Facades\DB;

final class OrganizationImporter
{
    public function __construct(private readonly ImportTargetGuard $guard) {}

    public function apply(OrganizationSnapshot $snapshot): array
    {
        $this->guard->assertSafe();
        $this->assertTransactionalTables();

        return DB::transaction(function () use ($snapshot): array {
            $result = ['inserted' => 0, 'unchanged' => 0];
            foreach ($snapshot->tables as $table => $rows) {
                foreach ($rows as $id => $attributes) {
                    $checksum = $this->checksum($attributes);
                    $mapping = DB::table('import_mappings')->where([
                        'source_name' => $snapshot->source, 'source_table' => OrganizationSnapshot::SOURCE_TABLES[$table], 'source_id' => $id,
                    ])->lockForUpdate()->first();
                    if ($mapping !== null) {
                        $existing = DB::table($table)->where('id', $mapping->target_id)->lockForUpdate()->first();
                        if ($mapping->target_table !== $table || (int) $mapping->target_id !== $id || $existing === null) {
                            throw new ImportConflict('Eşleme hedefi bulunamadı veya kimliği değişmiş.');
                        }
                        $current = [];
                        foreach ($attributes as $key => $value) {
                            $current[$key] = is_bool($value) ? (bool) $existing->{$key}
                                : (is_int($value) ? (int) $existing->{$key} : $existing->{$key});
                        }
                        if ($mapping->checksum !== $checksum || $mapping->target_checksum !== $this->checksum($current)) {
                            throw new ImportConflict('Kaynak veya hedef değişmiş; otomatik üzerine yazma durduruldu.');
                        }
                        $result['unchanged']++;

                        continue;
                    }
                    if (DB::table($table)->where('id', $id)->exists()) {
                        throw new ImportConflict('Hedef kimliği daha önce kullanılmış; eşlemesiz kayıt değiştirilemez.');
                    }
                    $now = now();
                    $write = $attributes + ['created_at' => $now, 'updated_at' => $now];
                    if ($table === 'users') {
                        $write['is_master_admin'] = MasterAdministrator::fromLegacy($attributes);
                    }
                    DB::table($table)->insert($write);
                    DB::table('import_mappings')->insert([
                        'source_name' => $snapshot->source, 'source_table' => OrganizationSnapshot::SOURCE_TABLES[$table], 'source_id' => $id,
                        'target_table' => $table, 'target_id' => $id, 'checksum' => $checksum,
                        'target_checksum' => $checksum, 'import_version' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $result['inserted']++;
                }
            }

            return $result;
        });
    }

    private function assertTransactionalTables(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            return;
        }
        $tables = DB::select(
            'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)',
            array_map(fn ($name) => DB::connection()->getTablePrefix().$name, ['departments', 'job_titles', 'users', 'import_mappings']),
        );
        if (count($tables) !== 4 || collect($tables)->contains(fn ($table) => strtoupper($table->engine) !== 'INNODB')) {
            throw new ImportConflict('Aktarım için tüm hedef tabloların InnoDB olması gerekir.');
        }
    }

    private function checksum(array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
