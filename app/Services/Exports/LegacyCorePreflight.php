<?php

namespace App\Services\Exports;

use PDO;

final class LegacyCorePreflight
{
    // Copy of the legacy placement rule, used only for migration diagnostics.
    // This is not a runtime status list or a seed for the new application.
    private const OCCUPYING_STATUSES = ['YENİ KAYIT', 'MEVCUT', 'MİSAFİR', 'HASTANE', 'ÖĞRENCİ', 'ÖZEL İZİN', 'VERİ DEĞİŞİKLİĞİ'];

    public function __construct(private readonly LegacyReadOnlyConnection $connection) {}

    public function check(array $settings): array
    {
        return $this->connection->run($settings, function (PDO $pdo, string $database): array {
            $fields = config('legacy_core.fields');
            $schemas = [
                'tablo' => array_keys($fields),
                'bloklar' => ['id', 'kod', 'ad', 'aciklama', 'aktif', 'sira'],
                'koguslar' => ['id', 'blok_id', 'kod', 'ad', 'ranza_sayisi', 'kapasite', 'tur', 'aktif', 'sira'],
                'secenek_listeleri' => ['id', 'liste_kodu', 'deger', 'sira', 'aktif'],
            ];
            $counts = [];
            foreach ($schemas as $table => $columns) {
                $this->connection->assertSchema($pdo, $database, $table, $columns);
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            }
            $groupCounts = [];
            $dateIssues = [];
            foreach ($fields as $column => $field) {
                $groupCounts[$field['group']] = ($groupCounts[$field['group']] ?? 0) + 1;
                if ($field['kind'] !== 'date') {
                    continue;
                }
                $q = '`'.$column.'`';
                $zero = "CAST($q AS CHAR) = '0000-00-00'";
                $invalid = "CAST($q AS CHAR) <> '0000-00-00' AND (YEAR($q) = 0 OR MONTH($q) = 0 OR DAY($q) = 0 OR DAY($q) > DAY(LAST_DAY(CONCAT(YEAR($q), '-', LPAD(MONTH($q), 2, '0'), '-01'))))";
                $row = $pdo->query("SELECT COALESCE(SUM($zero),0) AS zero_dates, COALESCE(SUM($invalid),0) AS invalid_dates FROM tablo")->fetch();
                $dateIssues[$column] = array_map('intval', $row);
            }
            $identity = $pdo->query("SELECT COALESCE(SUM(huk_tc IS NULL OR TRIM(huk_tc) = ''),0) AS empty_tc,
                COALESCE(SUM(huk_adi IS NULL OR TRIM(huk_adi) = ''),0) AS empty_name,
                COALESCE(SUM(huk_tc IS NOT NULL AND TRIM(huk_tc) <> '' AND huk_tc NOT REGEXP '^[0-9]{11}$'),0) AS unexpected_tc_format,
                COALESCE(MAX(CHAR_LENGTH(huk_tc)),0) AS max_tc_characters,
                COALESCE(MAX(CHAR_LENGTH(huk_adi)),0) AS max_name_characters FROM tablo")->fetch();
            $identity['duplicate_tc_groups'] = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT huk_tc FROM tablo WHERE huk_tc IS NOT NULL AND TRIM(huk_tc) <> '' GROUP BY huk_tc HAVING COUNT(*) > 1) duplicates")->fetchColumn();
            $placeholders = implode(',', array_fill(0, count(self::OCCUPYING_STATUSES), '?'));
            $occupied = "huk_durum IN ($placeholders) AND COALESCE(TRIM(huk_blok),'') <> '' AND COALESCE(TRIM(huk_kogus),'') <> '' AND COALESCE(TRIM(huk_ranza),'') <> ''";
            $beds = $pdo->prepare("SELECT COUNT(*) FROM (SELECT huk_blok, huk_kogus, huk_ranza FROM tablo WHERE $occupied GROUP BY huk_blok,huk_kogus,huk_ranza HAVING COUNT(*) > 1) collisions");
            $beds->execute(self::OCCUPYING_STATUSES);
            $rooms = (int) $pdo->query('SELECT COUNT(*) FROM koguslar k LEFT JOIN bloklar b ON b.id = k.blok_id WHERE b.id IS NULL')->fetchColumn();
            $missingPlacement = $pdo->prepare("SELECT COUNT(*) FROM tablo t WHERE $occupied AND NOT EXISTS (SELECT 1 FROM koguslar k JOIN bloklar b ON b.id = k.blok_id WHERE b.kod = t.huk_blok AND k.kod = t.huk_kogus)");
            $missingPlacement->execute(self::OCCUPYING_STATUSES);
            $options = [];
            foreach (['huk_durum' => 'durum', 'huk_karar' => 'karar', 'huk_risk' => 'risk'] as $column => $list) {
                $query = $pdo->prepare("SELECT COUNT(*) FROM tablo t WHERE t.`$column` IS NOT NULL AND TRIM(t.`$column`) <> '' AND NOT EXISTS (SELECT 1 FROM secenek_listeleri s WHERE s.liste_kodu = ? AND s.deger = t.`$column`)");
                $query->execute([$list]);
                $options[$list] = ['rows_without_database_option' => (int) $query->fetchColumn()];
            }
            $textDates = [];
            foreach (config('legacy_core.text_date_fields') as $column) {
                $textDates[$column] = (int) $pdo->query("SELECT COUNT(*) FROM tablo WHERE `$column` IS NOT NULL AND TRIM(`$column`) <> ''")->fetchColumn();
            }

            return [
                'scope' => 'core_mapping_preflight_only', 'counts' => $counts,
                'mapped_source_columns' => count($fields), 'field_groups' => $groupCounts,
                'identity' => array_map('intval', $identity), 'dates' => $dateIssues,
                'unvalidated_text_date_rows' => $textDates,
                'placement' => ['occupied_bed_collision_groups' => (int) $beds->fetchColumn(), 'rooms_without_block' => $rooms, 'occupied_rows_without_room' => (int) $missingPlacement->fetchColumn()],
                'options' => $options, 'source_session_timezone' => $pdo->query('SELECT @@session.time_zone')->fetchColumn(),
                'data_written' => false, 'ready_for_import' => false,
            ];
        });
    }
}
