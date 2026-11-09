# CTE Portal Windows / XAMPP Kurulum Belgesi

Bu belge CTE Portal uygulamasını Windows üzerinde XAMPP benzeri yerel bir PHP/MySQL ortamında güvenli biçimde çalıştırmak için hazırlanmıştır. Amaç, uygulamayı doğrudan proje kökünden değil yalnızca `public/` klasöründen servis etmek, `.env` ve `storage` gibi hassas dizinlerin web üzerinden açılmasını engellemek ve internetsiz kurum ortamına uygun bir kurulum standardı oluşturmaktır.

## 1. Gerekli sürümler

- Windows 10/11 veya kurum imajına uygun Windows Server sürümü
- Apache 2.4+
- PHP 8.4+
- MySQL 8.0+ veya MariaDB 10.6+
- Composer 2+
- Node.js LTS ve npm

## 2. Zorunlu PHP eklentileri

`php.ini` içinde aşağıdaki eklentiler aktif olmalıdır:

```ini
extension=bcmath
extension=ctype
extension=curl
extension=fileinfo
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=tokenizer
extension=xml
extension=zip
```

Değişiklikten sonra Apache yeniden başlatılmalıdır.

## 3. Proje klasörü

Önerilen klasör:

```text
C:\xampp\htdocs\cte-portal
```

Hazırlanan çevrimdışı uygulama paketi `C:\xampp\htdocs\cte-portal` klasörüne açılır.

Mevcut kurulumda güncelleme için:

```bat
cd C:\xampp\htdocs\cte-portal
git pull origin main
```

## 4. Apache VirtualHost

Apache `httpd-vhosts.conf` dosyasına aşağıdaki örnek eklenmelidir. En önemli nokta `DocumentRoot` değerinin proje kökü değil `public` klasörü olmasıdır.

```apache
<VirtualHost *:80>
    ServerName cte-portal.local
    DocumentRoot "C:/xampp/htdocs/cte-portal/public"

    <Directory "C:/xampp/htdocs/cte-portal/public">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog "logs/cte-portal-error.log"
    CustomLog "logs/cte-portal-access.log" common
</VirtualHost>
```

Windows `hosts` dosyasına ek:

```text
127.0.0.1 cte-portal.local
```

Dosya konumu:

```text
C:\Windows\System32\drivers\etc\hosts
```

## 5. mod_rewrite kontrolü

Apache `httpd.conf` içinde şu satırın başındaki `#` kaldırılmış olmalıdır:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Ayrıca VirtualHost içindeki `AllowOverride All` zorunludur. Aksi halde Laravel route yapısı çalışmaz.

## 6. Ortam dosyası

`.env.example` kopyalanır:

```bat
copy .env.example .env
```

Uygulama anahtarı oluşturulur:

```bat
php artisan key:generate
```

Üretime yakın yerel kullanım için kritik değerler:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=file
SESSION_LIFETIME=30
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

`CTE_INITIAL_ADMIN_PASSWORD` alanı gerçek parola ile yalnızca lokal `.env` içinde doldurulmalıdır. Bu değer dağıtım paketine eklenmemelidir.

## 7. Veritabanı

MySQL içinde boş veritabanı ve kullanıcı oluşturulur:

```sql
CREATE DATABASE cte_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cte_portal_app'@'localhost' IDENTIFIED BY 'GUÇLÜ_PAROLA_YAZIN';
GRANT ALL PRIVILEGES ON cte_portal.* TO 'cte_portal_app'@'localhost';
FLUSH PRIVILEGES;
```

`.env` içinde:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cte_portal
DB_USERNAME=cte_portal_app
DB_PASSWORD=GUÇLÜ_PAROLA_YAZIN
```

## 8. Bağımlılıklar ve build

```bat
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Geliştirme/test sırasında `--no-dev` kullanılmayabilir. Testleri çalıştırmak için dev bağımlılıkları gerekir.

## 9. Storage ve izinler

Aşağıdaki klasörler yazılabilir olmalıdır:

```text
storage
storage\app
storage\app\private
storage\framework
storage\framework\cache
storage\framework\sessions
storage\framework\views
storage\logs
bootstrap\cache
```

Windows'ta Apache hangi kullanıcıyla çalışıyorsa bu klasörlere yazma izni verilmelidir. Kurum bilgisayarında dosya izinleri sıkıysa proje klasörü için BT yönetici izni gerekebilir.

## 10. Güvenlik kontrol listesi

- Uygulama yalnızca `public/` klasöründen servis ediliyor.
- Dizin listeleme kapalı: `Options -Indexes`.
- `.env`, `storage`, `vendor`, `database` klasörleri web kökü dışında kalıyor.
- `APP_DEBUG=false`.
- Session driver `file`, cache store `file`, queue `sync`.
- Session lifetime 30 dakika.
- Session encryption açık.
- Log seviyesi `warning` veya daha üstü.
- Gerçek SQL dump, fotoğraf, yedek zip ve kişisel veri kaynak kod paketine eklenmiyor.

## 11. Sağlık kontrolü

Kurulumdan sonra:

```text
http://cte-portal.local/status
```

Beklenen sonuç:

```json
{
  "application": "CTE Portal",
  "status": "ok",
  "checks": {
    "database": true,
    "private_storage": true,
    "production_security": true
  }
}
```

`status` değeri `degraded` ise veritabanı, private storage izni veya production security ayarlarından biri hatalıdır.

## 12. Güncelleme akışı

```bat
cd C:\xampp\htdocs\cte-portal
git pull origin main
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Güncelleme öncesi veritabanı ve `storage\app\private` klasörü ayrıca yedeklenmelidir.

## 13. Sık hata notları

### 404 / route çalışmıyor

- `mod_rewrite` kapalı olabilir.
- VirtualHost `DocumentRoot` yanlışlıkla proje köküne verilmiş olabilir.
- `AllowOverride All` eksik olabilir.

### 500 hata sayfası

- `.env` eksik olabilir.
- `APP_KEY` oluşturulmamış olabilir.
- `storage` veya `bootstrap/cache` yazılamıyor olabilir.
- PHP eklentilerinden biri kapalı olabilir.

### Login sonrası çıkış yapıyor

- `storage/framework/sessions` yazılamıyor olabilir.
- `SESSION_DOMAIN` yerel alan adıyla uyumsuz olabilir.
- Saat/tarih ayarı bozuk olabilir.

## 14. Lokal test komutları

Akşam bilgisayarda kontrol için:

```bat
php artisan test --filter=ProductionSecurityTest
php artisan test --filter=AdminUserManagementTest
php artisan test --filter=PermissionGateAndCacheTest
php artisan test
```
