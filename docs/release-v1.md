# CTE Portal v1.0.0 Sürüm Kabul Rehberi

v1.0.0 etiketi yalnız üretim provası ve kullanıcı kabulü sonrası atılır.

## Kabul kriterleri

- Faz 0-9 plan maddeleri tamamlanmış olmalı.
- `php artisan test` başarılı olmalı.
- `php artisan cte:preflight` başarılı olmalı.
- Kaynak paket secret/PII taraması temiz olmalı.
- Temiz kurulum gerçek hükümlü/demo verisi içermemeli.
- Private storage public web kökünden servis edilmemeli.
- Legacy dry-run başarılı olmadan gerçek aktarım yapılmamalı.

## Sürüm adımları

```bash
git status
php artisan test
php artisan cte:preflight
git tag -a v1.0.0 -m "CTE Portal v1.0.0"
git push origin v1.0.0
```

## Kullanıcı kabul formu

- Kurum:
- Test tarihi:
- Test eden kullanıcı:
- Commit SHA:
- Yedek SHA-256:
- Kabul edilen modüller:
- Eksik/riske alınan maddeler:
- Onay:

## Kritik smoke test

- Giriş/çıkış
- Yetkisiz erişim engeli
- Hükümlü arama/liste/detay
- Konum/çalışma geçmişi
- Private dosya yükleme/indirme
- Kalıcı silme onayı
- Birim modülleri
- Operasyon modülleri
- İnfaz hesaplayıcı
- Rapor/CSV/statistik
- Audit/yedek/legacy komutları
