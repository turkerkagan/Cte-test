# CTE Portal Üretim Runbook

Bu runbook kapalı ağ/kurum içi kurulum için hazırlanmıştır. Komutlar üretim sunucusunda yetkili teknik personel tarafından çalıştırılır.

## 1. Ön hazırlık

- Sunucuda PHP, MySQL, Apache/Nginx ve gerekli PHP eklentileri kontrol edilir.
- Uygulama `public/` dizininden servis edilir.
- `.env` dosyası yalnız yerel sunucuda oluşturulur; dağıtım paketine eklenmez.
- `APP_DEBUG=false` olmalıdır.
- İlk admin parolası yalnız `.env` içinde tutulur.

## 2. Bakım modu

```bash
php artisan down --secret="kurum-ici-gecici-token"
```

## 3. Ön yedek

```bash
php artisan cte:backup --label=before-update
```

Yedek SHA-256 değeri ayrı tutanakta saklanır.

## 4. Kod güncelleme

```bash
git pull origin main
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

Kapalı ağda npm/composer paketleri önceden hazırlanmış cache veya paket deposundan sağlanmalıdır.

## 5. Veritabanı güncelleme

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 6. Preflight

```bash
php artisan cte:preflight
php artisan test
```

Üretimde tüm testlerin çalıştırılması mümkün değilse en azından preflight ve kritik smoke test yapılır.

## 7. Legacy aktarım provası

```bash
php artisan cte:legacy-inspect
php artisan cte:legacy-dry-run storage/app/legacy-sample.json
php artisan cte:legacy-compare storage/app/source-counts.json storage/app/target-counts.json
```

Gerçek aktarım yalnız backup + dry-run + yetkili onayı sonrası yapılır:

```bash
php artisan cte:legacy-migrate storage/app/legacy-sample.json 1 --confirm=RUN_IMPORT

# Gerçek fihrist.sql tabloları güvenli bir önekle aynı veritabanına yüklendikten sonra:
php artisan cte:legacy-migrate-all 2 --prefix=legacy_src_ --confirm=RUN_FULL_IMPORT
```

## 8. Kontrollü açılış

```bash
php artisan up
```

Açılış sonrası kontrol:

- `/status`
- giriş
- hükümlü liste/detay
- private dosya indirme
- birim modülü
- operasyon modülü
- rapor/istatistik

## 9. Rollback

Sorun halinde:

1. Bakım modu açılır.
2. Son başarılı kod sürümüne dönülür.
3. Yedek önce `php artisan cte:restore-verify /tam/yol/yedek.zip` ile doğrulanır.
4. Yetkili bakım penceresinde `php artisan cte:restore /tam/yol/yedek.zip --confirm=RESTORE_BACKUP` çalıştırılır. Komut bakım modunu açar, işlem öncesi yedek alır, veritabanı ve private dosyaları geri yükler; hata halinde ön yedeğe dönmeyi dener ve bakım modunu kapatır.
5. Cache temizlenir.
6. Sağlık kontrolü yapılır.

## 10. Kayıt ve tutanak

Her üretim geçişinde şunlar kaydedilir:

- Tarih/saat
- Git commit SHA
- Yedek dosya adı ve SHA-256
- Migration sonucu
- Preflight sonucu
- Kabul eden yetkili
