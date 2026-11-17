# GlossiDigital yayını

Uygulama adresi: https://glossidigital.com/cte-test/

17 Kasım 2026 tarihinde yayımlandı. Giriş ekranı, oturum gerektiren ana
sayfa, yönetim, veritabanı, erişim politikaları ve çalışma merkezi Chrome
ile doğrulandı. Derlenmiş dosyalarda HTTP hatası veya JavaScript hatası
görülmedi. Kaynak uygulama sürümü `1f796be107df2cc5a8ac74dd8fa667793dff5780`.

Kaynak uygulama `/Applications/XAMPP/xamppfiles/htdocs` klasörüdür. İnternet
kopyasının kodları `turkerkagan/Cte-test` reposunda tutulur. GitHub kaynak
yedeğidir; uygulamayı kullanmak ve uzaktaki kayıtları yönetmek için `/cte-test/`
adresine giriş yapılır. Kaynak kod güncellemelerinin görünmesi için sunucuya
ayrıca yayın yapılmalıdır.

Sunucuda yalnız `public` içeriği web köküne açılır. Laravel uygulaması,
`vendor`, `.env`, kullanıcı dosyaları ve yedekler `/home/glossidi/cte-releases`
altında, web kökünün dışında bulunur. Web giriş dosyası ilgili sürümün
`public/index.php` dosyasını yükler. Eski kurulum ve eski veritabanı geri
dönüş için korunur.

Hosting MySQL veritabanı `glossidi_cte_online` adındadır. Bu veritabanı yerel
`cte` veritabanının 17 Kasım 2026 tarihinde alınan bağımsız kopyasıdır.
İlk aktarımda 103 tablo ve 6.674 kaydın tablo bazında satır sayıları ve
içerik SHA-256 özetleri eşleşti. Yerel tetikleyicinin `DELIMITER` istemci
komutları aktarım için dönüştürüldü; `root` definer kaydı hosting
veritabanı sahibine uyarlandı. Bekleyen Laravel migration'ı yoktu.
Sonraki yerel ve uzak kayıt değişiklikleri birbirini otomatik güncellemez.
Yeniden kaynak kod yayını sırasında veritabanını yeniden içe aktarmayın.

Hosting PHP 8.5.12 kullanıyor. `proc_open` ve `shell_exec` işlevleri
sunucu tarafından kapatılmıştır. Python ile Excel birleştirme ve
`mysqldump`/`mysql` dış komutlarını çağıran yedekleme/geri yükleme
işlemleri bu ortamda çalışamaz. Hosting sağlayıcısının uygulama için
uygun çalışma izni ve gerekli araçları sağlaması gerekir. Bu kısıt,
normal web ekranlarının ve veritabanı bağlantısının doğrulanmasından
ayrı değerlendirilmelidir.

Hosting ortamında aşağıdaki değişkenler kullanılır; gerçek anahtar ve
parolalar yalnız özel `.env` dosyasında bulunur:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://glossidigital.com/cte-test
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=glossidi_cte_online
CTE_HOSTED_DATABASE=glossidi_cte_online
DB_TABLE_PREFIX=
SESSION_DRIVER=file
SESSION_COOKIE=cte_online_session
SESSION_PATH=/cte-test
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
```

`APP_KEY` taşıma sırasında korunur. `CTE_HOSTED_DATABASE` yalnız belirtilen
veritabanına izin verir; URL ile bağlantı değiştirme ve tablo önekleri
reddedilir. `migrate:fresh` ve `db:wipe` hosting veritabanını da korur.

Yayın doğrulaması: üretim arayüzünü derleyin, PHP ve arayüz testlerini
çalıştırın, yedeği alın, sürümü özel alana yükleyin, mevcut veritabanı
kopyasının tablo/satır özetlerini karşılaştırın, giriş ve yönetim
sayfalarını kontrol edin. Kaynak kod, `.env`, SQL dökümleri ve özel
dosyaların HTTP ile okunamadığını doğrulayın. Geçici kurulum yardımcıları
yayın sonunda kaldırılmalıdır.
