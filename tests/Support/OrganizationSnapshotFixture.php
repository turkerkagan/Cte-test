<?php

namespace Tests\Support;

final class OrganizationSnapshotFixture
{
    public static function make(): array
    {
        return [
            'format_version' => 1,
            'source' => 'g03_synthetic',
            'birimler' => [['id' => 7, 'kod' => 'sentetik_birim', 'ad' => 'SENTETİK BİRİM', 'aciklama' => null, 'aktif' => 1]],
            'unvanlar' => [['id' => 9, 'kod' => 'sentetik_unvan', 'ad' => 'İnfaz Koruma Memuru', 'aktif' => 1]],
            'kullanicilar' => [[
                'id' => 11, 'kullanici_adi' => 'g03_synthetic_user', 'sicil' => '000012',
                'ad_soyad' => 'SENTETİK KULLANICI', 'email' => null, 'telefon' => '00012345678',
                'sifre' => password_hash('synthetic-only-password', PASSWORD_BCRYPT, ['cost' => 4]),
                'birim_id' => 7, 'unvan_id' => 9, 'sistem_yonetici' => 1, 'aktif' => 1,
                'sifre_degistir' => 1, 'oturum_surumu' => 3, 'hatali_giris' => 2,
                'kilitli_baslangic' => '2026-10-03 12:00:00', 'son_giris' => null,
                'olusturma_zamani' => '2020-02-29 08:09:10', 'guncelleme_zamani' => null,
            ]],
        ];
    }
}
