# Çevrimdışı XAMPP Paketi

Bu paket internet erişimi olmayan kurum sunucusunda Composer veya Node.js gerektirmeden kurulmak üzere hazırlanır. Paket `vendor/`, derlenmiş `public/build/`, kaynak kod, migration/seed ve kurulum belgelerini içerir; `.env`, gerçek veriler, loglar, yedekler ve private dosyalar pakete alınmaz.

## Geliştirme bilgisayarında paket oluşturma

```bash
php artisan test
npm ci
npm run build
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan cte:package
```

`release/` klasöründeki ZIP ve `.sha256` dosyasını birlikte taşıyın. Hedefte ZIP hash değerini doğrulayın.

Paket oluşturma komutu `.env.example`, `vendor/`, derlenmiş assetler ve kurulum belgelerini zorunlu tutar. `.env`, SQL, log, yedek, private dosya ve geliştirme çalışma alanları pakete alınmaz. Paket üretildikten sonra ZIP içerik denetimi ve SHA-256 doğrulaması yapılmalıdır.

## Kurum sunucusunda kurulum

1. Paketteki `CtePortal` klasörünü `C:\xampp\htdocs\CtePortal` altına çıkarın.
2. `.env.example` dosyasını `.env` adıyla kopyalayın; MySQL bilgilerini ve en az 12 karakterli ilk admin parolasını girin.
3. Apache VirtualHost `DocumentRoot` değerini yalnız `C:/xampp/htdocs/CtePortal/public` yapın. Proje kökünü kesinlikle web kökü yapmayın.
4. MySQL root hesabına güçlü parola verin; phpMyAdmin'i yalnız localhost ile sınırlayın veya kaldırın.
5. Komut isteminde aşağıdakileri çalıştırın:

```bat
cd C:\xampp\htdocs\CtePortal
C:\xampp\php\php.exe artisan key:generate --force
C:\xampp\php\php.exe artisan migrate --seed --force
C:\xampp\php\php.exe artisan optimize:clear
C:\xampp\php\php.exe artisan cte:preflight
```

6. `/status`, `/giris`, admin girişi, private dosya indirme ve yetkisiz erişim kontrollerini ağ bağlantısı kapalıyken doğrulayın.

Fiziksel cihaz, Windows oturumu, XAMPP paneli ve MySQL yönetimi yalnız yetkili personele açık olmalıdır.
