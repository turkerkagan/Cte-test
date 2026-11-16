<?php

namespace Tests\Support;

use App\Services\Exports\LegacyOrganizationAdapter;
use PDO;

final class LegacyOrganizationFixture
{
    /** Only call inside an exclusively created disposable test schema. */
    public static function create(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE birimler (id INT PRIMARY KEY, kod VARCHAR(50), ad VARCHAR(120), aciklama VARCHAR(255), aktif TINYINT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE unvanlar (id INT PRIMARY KEY, kod VARCHAR(50), ad VARCHAR(120), aktif TINYINT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE kullanicilar (id INT PRIMARY KEY, kullanici_adi VARCHAR(50), sicil VARCHAR(50), ad_soyad VARCHAR(150), email VARCHAR(100), sifre VARCHAR(255), birim_id INT, unvan_id INT, sistem_yonetici TINYINT, aktif TINYINT, sifre_degistir TINYINT, oturum_surumu INT, hatali_giris INT, kilitli_baslangic TIMESTAMP NULL, son_giris TIMESTAMP NULL, olusturma_zamani TIMESTAMP NULL, guncelleme_zamani TIMESTAMP NULL, rol_id INT, ozel_not_seviye VARCHAR(20)) ENGINE=InnoDB');
        $input = OrganizationSnapshotFixture::make();
        foreach (LegacyOrganizationAdapter::COLUMNS as $table => $columns) {
            foreach ($input[$table] as $row) {
                if ($table === 'kullanicilar') {
                    unset($row['telefon']); // Older supported schema lacks this optional field.
                    $row += ['rol_id' => 4, 'ozel_not_seviye' => 'yazar'];
                } else {
                    $row += ['created_at' => '2020-02-29 08:09:10', 'updated_at' => null];
                }
                $statement = $pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`, `', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            }
        }

        return $input;
    }
}
