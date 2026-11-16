# Portal Next

Laravel, Inertia ve React ile geliştirilen kurum portalı.

İnternet kopyası: [glossidigital.com/cte-test](https://glossidigital.com/cte-test/).
Hosting kurulumu ve bağımsız veritabanı kopyası için
[yayın notlarına](docs/glossidigital-hosting.md) bakın.

## Veritabanı

Ana yerel veritabanı **`cte`**’dir. Portal Next tabloları öneksiz kullanır.
Örnekler: `cte.users`, `cte.people`, `cte.institution_records`.

Eski uygulamanın öneksiz tabloları korunur. Yeni şema kurulumu eski kullanıcı ve kayıtları otomatik aktarmaz. Ayrıntılı tablo grupları ve kurallar için [veritabanı düzeni](docs/veritabani-duzeni.md) belgesine bakın.

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=cte
DB_TABLE_PREFIX=
```

Bağlantı sunucusu, kullanıcı ve parola `.env` dosyasında tutulur. Hostingde
veritabanı adı farklıysa `CTE_HOSTED_DATABASE` ile aynı ad açıkça tanımlanır.
Mevcut `.env` dosyasının üzerine yazmayın. Web kökü `public` dizini olmalıdır.

### XAMPP htdocs kökünde çalışma

Uygulama doğrudan `htdocs` içindedir. `http://localhost/` giriş adresidir; ana liste `/portal`, yönetim `/yonetim`, erişim politikaları `/yonetim/erisim-politikalari` adresindedir. Kök `.htaccess`, istekleri `public` dizinine aktarır ve kaynak, yapılandırma, yedek ve eski uygulama dizinlerini HTTP erişimine kapatır. Önceki `/portal-next/public/...` adresleri yeni kök adrese yönlenir.

Klasör taşırken gizli dosyaları da taşıyın. Özellikle `.env` içindeki mevcut `APP_KEY` korunmalı, `APP_URL=http://localhost` olmalıdır. Anahtar üretmek mevcut şifreli veriler ve oturumlar için taşıma yöntemi değildir. Taşıma sonrası `php artisan config:clear`, `php artisan route:clear` ve `php artisan view:clear` çalıştırılabilir.

## Kurulum ve güncelleme

PHP 8.2 veya üzeri ve MySQL/MariaDB gerekir. Geliştirme derlemesi Node.js 22 kullanır. Yeni çevrimdışı kurulum yapılandırıcısı `cte` veritabanını ve `cte_next_` tablo grubunu seçer.

Yedek alındıktan sonra şema güncellemeleri:

```sh
php artisan migrate --force
```

İlk yönetici, yalnız hazırlanmış boş yeni kullanıcı tablosunda oluşturulur. Parola terminalde gizli olarak sorulur:

```sh
php artisan portal:install-admin türker
```

Mevcut kullanıcılar aktarılacaksa önce aktarım yöntemi belirlenmelidir; rastgele ilk yönetici veya örnek kayıt oluşturmayın. Paylaşılan `cte` üzerinde `migrate:fresh` ve `db:wipe` engellenmiştir.

19 Ekim 2026 yerel kurulumu: [doğrulama kaydı](docs/cte-veritabani-kurulum-20261019.json). Ön yedek, web erişimine kapalı `storage/app/private/administration/setup-backups` altında saklanır.

## Geliştirme kontrolleri

```sh
php vendor/bin/phpunit --do-not-cache-result
composer analyse
composer format:check
npm run typecheck
npm run lint
npm run format:check
npm run test:frontend
npm run build
npm run check:bundle
npm run test:browser
```

Tarayıcı testleri için önce `npx playwright install chromium` çalıştırılır. Otomatik testler yalıtılmış test veritabanlarını kullanır.
