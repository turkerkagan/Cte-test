<?php

namespace Tests\Support;

use PDO;

final class LegacyCoreFixture
{
    /** Only call inside an exclusively created disposable test schema. */
    public static function create(PDO $pdo): void
    {
        $definitions = [];
        foreach (config('legacy_core.fields') as $column => $field) {
            $type = match ($field['kind']) {
                'integer' => 'INT', 'date' => 'DATE', 'time' => 'TIME', default => 'TEXT',
            };
            $definitions[] = '`'.$column.'` '.$type.($column === 'id' ? ' PRIMARY KEY' : ' NULL');
        }
        $pdo->exec('CREATE TABLE tablo ('.implode(',', $definitions).') ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE bloklar (id INT PRIMARY KEY, kod VARCHAR(50), ad VARCHAR(50), aciklama VARCHAR(255), aktif TINYINT, sira INT) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE koguslar (id INT PRIMARY KEY, blok_id INT, kod VARCHAR(100), ad VARCHAR(100), ranza_sayisi INT, kapasite INT, tur VARCHAR(10), aktif TINYINT, sira INT) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE secenek_listeleri (id INT PRIMARY KEY, liste_kodu VARCHAR(30), deger VARCHAR(100), sira INT, aktif TINYINT) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO bloklar VALUES (1,'A','SYNTHETIC',NULL,1,1)");
        $pdo->exec("INSERT INTO koguslar VALUES (1,999,'2','SYNTHETIC',10,10,'normal',1,1)");
        $pdo->exec("INSERT INTO secenek_listeleri VALUES (1,'durum','MEVCUT',1,1)");
        $mode = $pdo->query('SELECT @@session.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = ''");
        try {
            $pdo->exec("INSERT INTO tablo (id,huk_tc,huk_adi,huk_blok,huk_kogus,huk_ranza,huk_durum,huk_karar,izin_donus_saati,huk_cezaevine_giris) VALUES
                (1,'00000000001',NULL,'A','1','1','MEVCUT','SYNTHETIC_CUSTOM','14:00:00','0000-00-00'),
                (2,'00000000001','SYNTHETIC','A','1','1','MEVCUT',NULL,'14:00:00','2026-00-03'),
                (3,NULL,'SYNTHETIC',NULL,NULL,NULL,'TAHLİYE',NULL,NULL,NULL)");
        } finally {
            $pdo->exec('SET SESSION sql_mode = '.$pdo->quote($mode));
        }
    }
}
