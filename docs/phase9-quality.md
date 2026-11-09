# Faz 9 Kalite ve Güvenlik Kontrol Listesi

Bu belge CTE Portal üretim öncesi kalite kapısıdır. Gerçek hükümlü verisi, şifre, SQL dump, fotoğraf, belge veya log kaynak kod paketine eklenmez.

## CTE-100 Otomatik testler

Çalıştırılacak temel komutlar:

```bash
php artisan migrate:fresh --seed
php artisan test
php artisan cte:preflight
```

Kapsam:

- Auth, RBAC, CSRF ve session timeout testleri
- Hükümlü CRUD, cascade ve kalıcı silme testleri
- Private dosya güvenliği, MIME, hash ve path traversal testleri
- Toplu işlem, kimlik fotoğrafı, birim/operasyon modülleri
- İnfaz hesaplayıcı, rapor, audit, yedek ve legacy dry-run testleri

## CTE-101 Responsive ve erişilebilirlik

Manuel kontrol boyutları:

- 360px mobil
- 768px tablet
- 1366px standart masaüstü
- Geniş ekran
- Yazdırma görünümü

Kontrol maddeleri:

- Form label ve focus görünür olmalı.
- Klavye ile menü ve formlar kullanılabilmeli.
- Kritik butonlar renk dışında metinle de anlaşılmalı.
- Tablo taşmaları yatay scroll ile yönetilmeli.

## CTE-102 İnternetsiz test

Ağ kapalıyken kontrol edilecekler:

- Giriş, dashboard, hükümlü liste/detay
- Birim modülleri
- Operasyon modülleri
- Araçlar/rapor/istatistik
- Fotoğraf ve private dosya indirme
- Hiçbir CDN, harici font veya dış script çağrısı olmamalı.

## CTE-103 Güvenlik

Kontroller:

- `APP_DEBUG=false`
- `.env`, SQL dump, private storage, log ve gerçek veri dağıtım paketinde yok
- Upload MIME/içerik doğrulaması aktif
- Path traversal engeli aktif
- Yetki bypass senaryoları test edilmiş
- Audit metadata içinde TC, ad soyad, parola, token ve açıklama redakte edilir

## CTE-104 Windows preflight

Gerekli kontroller:

- PHP 8.3+ veya hedeflenen PHP sürümü
- `zip`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` eklentileri
- MySQL InnoDB
- `storage/` ve `bootstrap/cache/` yazılabilir
- Apache document root `public/`
- `mod_rewrite` açık

## CTE-105 Paketleme

Paket içeriği:

- Kod
- `.env.example`
- Migration/seed
- `docs/windows-xampp.md`
- `docs/production-runbook.md`
- `docs/release-v1.md`
- Boş private storage dizinleri
- Gerçek veri içermeyen kurulum notları

## CTE-106 Güncelleme ve rollback

Sıra:

1. Bakım modu
2. Manuel yedek
3. Kod güncelleme
4. Composer/npm build
5. Migration
6. Cache temizleme
7. Preflight
8. Sağlık kontrolü
9. Sorun varsa önceki kod + yedekten geri dönüş

## CTE-107 Üretim provası

- Eski sistem salt okunur yapılır.
- Final dump alınır.
- Legacy inspect çalıştırılır.
- Legacy dry-run çalıştırılır.
- Sayılar karşılaştırılır.
- Kabul testleri yapılır.
- Kontrollü açılış yapılır.

## CTE-108 v1.0.0

Sürüm etiketi yalnız şu şartlarda atılır:

- Tüm testler geçer.
- Preflight geçer.
- Güvenlik kontrolü temizdir.
- Kullanıcı kabulü alınır.
- Üretim provası başarılıdır.
