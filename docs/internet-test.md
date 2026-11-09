# İnternet deneme ortamı

CTE Portal için bağımsız internet deneme ortamı. Ana Cte reposundan ayrı bir kaynak kopyasıdır; gerçek veritabanı, kişi kayıtları, yüklenen dosyalar ve Git geçmişi içermez.

## Yayın durumu

Repo hazırlanmıştır. Canlı yayın için Railway hesabı ve bu repoya erişimi gerekir. GitHub repo adresi uygulamanın giriş adresi değildir.

## Railway kurulumu

1. Yeni projeye GitHub üzerinden `turkerkagan/Cte-test` uygulamasını ve ayrı bir MySQL servisini ekleyin. MySQL servis adı `MySQL` olsun.
2. Uygulamanın `storage` dizini için `/var/www/html/storage` yoluna kalıcı volume bağlayın. Tek uygulama instance kullanın. Veritabanının kendi kalıcı volume'u da bulunmalıdır.
3. Uygulama değişkenlerini aşağıdaki gibi ayarlayın. Şifreleri yalnızca Railway Variables alanına girin; repoya eklemeyin.
4. Networking bölümünde 8080 portuna domain oluşturun; HTTPS adresini APP_URL olarak ayarlayıp yayınlayın.

```dotenv
APP_NAME="CTE Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://VERILEN-ADRES
APP_KEY=BASE64-ANAHTARI
APP_LOCALE=tr
APP_FALLBACK_LOCALE=tr
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
CTE_BOOTSTRAP_ADMIN=true
CTE_INITIAL_ADMIN_LOGIN=turker
CTE_INITIAL_ADMIN_PASSWORD=RASTGELE-EN-AZ-16-KARAKTER
SESSION_DRIVER=file
SESSION_LIFETIME=30
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
LOG_LEVEL=warning
FILESYSTEM_DISK=local
PORT=8080
CTE_TRUST_PROXY=true
```

APP_KEY bir kere `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` ile üretilip sabit tutulmalıdır. Yayınlarda değiştirilmemelidir.

Başlangıç betiği migration'ları çalıştırır. `CTE_BOOTSTRAP_ADMIN=true` yalnızca kullanıcı tablosu boşsa mevcut seeder ile kurum, roller ve yönetici oluşturur. Veritabanı kaydı ve dosya aktarımı kaynak kod yayınına dahil değildir; yetkili veri kopyası ayrı ve özel aktarım ile yapılmalıdır. İlk başarılı kurulumdan sonra bu değişkeni false yapın ve CTE_INITIAL_ADMIN_PASSWORD değişkenini kaldırın. Yeniden yayın mevcut kullanıcı şifresini sıfırlamaz. Giriş yolu `/giris`.

CTE_TRUST_PROXY=true yalnızca Railway reverse proxy arkasında kullanılmalıdır. Yerelde varsayılan false değerini koruyun.

MySQL bağlantısı Railway'nin özel ağı üzerinden yapılır. Veritabanına public TCP erişimi açmayın. Veritabanı yedeklerini sağlayıcıda yapılandırın; storage volume'unu ayrıca yedekleyin. Test verileri de yeniden yayınlarda kalıcıdır. Railway ücret ve kullanım limitleri hesabınızın planına bağlıdır.

## Doğrulama

Docker kurulumu mevcut bir ortamda `docker build -t cte-test .` ile imajı doğrulayın. Yerel PHP testleri için bağımlılıkları kurup `php artisan test` çalıştırın. Container derlemesi ve canlı MySQL bağlantısı yayın sırasında ayrıca kontrol edilmelidir.
