# GlossiDigital yayını

Uygulama adresi: https://glossidigital.com/cte/

Kaynak uygulama `/Applications/XAMPP/xamppfiles/htdocs` klasörüdür. İnternet
kopyasının kodları `turkerkagan/Cte-test` reposunda tutulur. GitHub kaynak
yedeğidir; uygulamayı kullanmak ve uzaktaki kayıtları yönetmek için `/cte/`
adresine giriş yapılır. Kaynak kod güncellemelerinin görünmesi için sunucuya
ayrıca yayın yapılmalıdır.

Sunucuda yalnız `public` içeriği web köküne açılır. Laravel uygulaması,
`vendor`, `.env`, kullanıcı dosyaları ve yedekler `/home/glossidi/cte-releases`
altında, web kökünün dışında bulunur. Web giriş dosyası ilgili sürümün
`public/index.php` dosyasını yükler. Eski kurulum ve eski veritabanı geri
dönüş için korunur.

Hosting MySQL veritabanı `glossidi_cte_online` adındadır. Bu veritabanı yerel
`cte` veritabanının 16 Kasım 2026 tarihinde alınan bağımsız kopyasıdır.
Sonraki yerel ve uzak kayıt değişiklikleri birbirini otomatik güncellemez.
Yeniden kaynak kod yayını sırasında veritabanını yeniden içe aktarmayın.

Hosting ortamında aşağıdaki değişkenler kullanılır; gerçek anahtar ve
parolalar yalnız özel `.env` dosyasında bulunur:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://glossidigital.com/cte
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=glossidi_cte_online
CTE_HOSTED_DATABASE=glossidi_cte_online
DB_TABLE_PREFIX=
SESSION_DRIVER=file
SESSION_COOKIE=cte_online_session
SESSION_PATH=/cte
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
