# CTE Portal

CTE Portal, kurum içi ceza infaz yönetim işlemleri için Laravel 13 tabanlı, yerel ağda ve internetsiz çalışmaya uygun tasarlanan web uygulamasıdır.

## Mevcut durum

Güncel iş planı `plan.md` dosyasında tutulur. Tamamlanan her görev ilgili kod değişikliği ve doğrulama sonucu ile birlikte kaydedilir.

## Temel kurulum

```bash
composer install
npm install
npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

## Windows / XAMPP kurulumu

Windows üzerinde XAMPP/Apache ile kurulum için ayrıntılı belge:

```text
docs/windows-xampp.md
```

Bu belgede VirtualHost, `public/` document root, `mod_rewrite`, PHP eklentileri, storage izinleri, `.env`, veritabanı, güncelleme ve test adımları yer alır.

İnternetsiz sunucuya taşınacak, bağımlılıkları içinde hazır ZIP paketini üretmek için:

```bash
composer install --no-dev --optimize-autoloader
npm run build
php artisan cte:package
```

Ayrıntılı kurulum: `docs/offline-xampp-package.md`.

## Güvenlik notu

`.env`, SQL dump, gerçek kişi verisi, hükümlü fotoğrafları, yedek zip dosyaları, loglar ve private storage içeriği kaynak kod paketine eklenmemelidir.

## Testler

```bash
php artisan test
```

## İnternetten deneme

Aynı kaynak kodunun özel test reposu: https://github.com/turkerkagan/Cte-test

Kurulum ve veritabanı işlemleri: [docs/internet-test.md](docs/internet-test.md).
Kaynak eşitliği `python3 scripts/sync-test-repo.py --check /yerel/Cte-test` ile kontrol edilir.
