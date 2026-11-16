<?php

namespace App\Services\Exports;

use App\Services\Imports\OrganizationSnapshot;

final class LegacyOrganizationAdapter
{
    public const COLUMNS = [
        'birimler' => ['id', 'kod', 'ad', 'aciklama', 'aktif', 'created_at', 'updated_at'],
        'unvanlar' => ['id', 'kod', 'ad', 'aktif', 'created_at', 'updated_at'],
        'kullanicilar' => ['id', 'kullanici_adi', 'sicil', 'ad_soyad', 'email', 'telefon', 'sifre', 'birim_id', 'unvan_id', 'sistem_yonetici', 'aktif', 'sifre_degistir', 'oturum_surumu', 'hatali_giris', 'kilitli_baslangic', 'son_giris', 'olusturma_zamani', 'guncelleme_zamani'],
    ];

    public const DEFERRED_COLUMNS = ['kullanicilar' => ['rol_id', 'ozel_not_seviye']];

    private const DATES = ['created_at', 'updated_at', 'kilitli_baslangic', 'son_giris', 'olusturma_zamani', 'guncelleme_zamani'];

    public function adapt(string $source, array $tables): array
    {
        $input = ['format_version' => 1, 'source' => $source];
        $normalizations = [];
        $deferred = [];
        foreach (self::COLUMNS as $table => $columns) {
            $input[$table] = [];
            foreach ($tables[$table] as $row) {
                foreach (self::DEFERRED_COLUMNS[$table] ?? [] as $column) {
                    if (array_key_exists($column, $row)) {
                        $key = $table.'.'.$column;
                        $deferred[$key] = ($deferred[$key] ?? 0) + (int) ($row[$column] !== null && $row[$column] !== '');
                    }
                }
                $mapped = array_intersect_key($row, array_flip($columns));
                foreach (self::DATES as $column) {
                    if (array_key_exists($column, $mapped) && in_array($mapped[$column], ['', '0000-00-00 00:00:00'], true)) {
                        $key = $table.'.'.$column;
                        $normalizations[$key] = ($normalizations[$key] ?? 0) + 1;
                        $mapped[$column] = null;
                    }
                }
                $input[$table][] = $mapped;
            }
        }
        $snapshot = OrganizationSnapshot::fromArray($input);

        return ['snapshot' => $input, 'report' => [
            'format_version' => 1, 'source' => $source, 'scope' => 'organization_only',
            'counts' => $snapshot->counts(), 'empty_or_zero_dates_to_null' => $normalizations,
            'deferred_access_columns_non_null' => $deferred,
            'access_migration_complete' => false,
        ]];
    }
}
