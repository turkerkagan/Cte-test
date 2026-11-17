# Railway deneme yayını

Kaynak repo `turkerkagan/Cte-test`. Dockerfile PHP 8.4/Apache, derlenmiş
arayüz, üretim Composer paketleri, MySQL istemcisi ve Excel motorunun
Python/openpyxl/xlrd bağımlılıklarını birlikte hazırlar.

Uygulama tek instance olarak çalıştırılır. Railway MySQL ayrı bir
servistir; veritabanı için kalıcı volume ve uygulama için
`/var/www/html/storage` konumunda ayrı kalıcı volume bağlanır. MySQL'e
uygulamadan Railway'nin özel ağıyla erişilir; kullanıcı tarayıcısının
veritabanına doğrudan bağlanması gerekmez.

Railway'nin ürettiği gerçek `*.up.railway.app` adresi APP_URL olarak
ayarlanır. Domainin UYAP erişimi kendi ağında doğrulanmalıdır. Deneme
önce boş hedefte hazırlanır; veri kopyası ayrıca özel kanaldan aktarılır.
SQL, APP_KEY, parolalar ve kullanıcı dosyaları GitHub'a eklenmez.

Ortam değişkenleri:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://RAILWAY-TARAFINDAN-VERILEN-ADRES
APP_KEY=OZEL-ALANDA-KORUNAN-ANAHTAR
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
CTE_HOSTED_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
DB_TABLE_PREFIX=
DB_SOCKET=
CTE_TRUST_PROXY=true
CTE_RUN_MIGRATIONS=false
CTE_SCHEDULER_ENABLED=false
SESSION_DRIVER=file
SESSION_COOKIE=cte_railway_session
SESSION_PATH=/
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
LOG_CHANNEL=stderr
LOG_LEVEL=warning
```

İlk veri kopyası tamamlanmadan otomatik migration açılmaz. Başlangıç
betiği kayıtları içe aktarmaz, sıfırlamaz ve varsayılan yönetici yaratmaz.
Şema/veri aktarımı ve mevcut kullanıcı doğrulaması tamamlandıktan sonra
zamanlayıcı CTE_SCHEDULER_ENABLED=true ile açılabilir. Yeniden yayınlarda
APP_KEY ve storage volume korunmalıdır.

Başlangıçta yalnız uygulamayla ilgili ortam değişkenleri özel bir .env
dosyasına yazılır; bu dosya imaja/GitHub'a eklenmez. Böylece mevcut
yedekleme ve bağımsız kurtarma araçları da aynı yapılandırmayı okuyabilir.
Railway hesap tokenleri bu dosyaya aktarılmaz.

CTE_TRUST_PROXY yalnız Railway gibi güvenilen reverse proxy arkasında
açılır. Yerel XAMPP varsayılan false değerini korur. Proxy kapalıyken
tarayıcıdan gelen X-Forwarded-* başlıklarına güvenilmez.

Ücretsiz deneme koşulu 17 Kasım 2026 itibarıyla en fazla 30 gün veya
5 dolar kredinin tükenmesine kadardır. Bir ay kesintisiz ücretsiz
çalışma garantisi değildir. Deneme sonrası veri saklama ve plan
koşulları ayrıca kontrol edilmelidir. Bu hazırlık ücretli plan satın
alma veya otomatik yükseltme talimatı içermez.

Kaynaklar:
- https://docs.railway.com/pricing/free-trial
- https://railway.com/guides/laravel
- https://docs.railway.com/databases/mysql
- https://docs.railway.com/volumes
