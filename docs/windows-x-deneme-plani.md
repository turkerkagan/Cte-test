# Windows XAMPP — x klasöründe çevrimdışı deneme

Doğrulanan ortam: Windows 64 bit, PHP 8.0.30, web kökü
`D:\xampp\htdocs`. Kullanıcı yalnız web köküne dosya kopyalayabiliyor;
elle EXE/BAT çalıştıramıyor. Mevcut eski PHP uygulaması çalışıyor.

Laravel 12 uygulaması PHP >= 8.2 gerektirir. Hazır vendor ve arayüz
dosyaları bu çalışma ortamı gereksinimini ortadan kaldırmaz. Güncel PHP
desteği doğrulanmadan tam uygulama mevcut sunucuya kopyalanmamalıdır.

## 1. Ortamı salt okunur kontrol et

`deploy/windows/ortam-kontrol.php` dosyasını sunucuda
`D:\xampp\htdocs\x-kontrol\index.php` adıyla kopyala. Tarayıcıda
`http://SUNUCU-IP/x-kontrol/` adresini aç ve sonucu paylaş.

Dosya PHP 8.0 sözdizimi kullanır; dosya yazmaz, veritabanına bağlanmaz,
komut veya program çalıştırmaz. Sunucu adı, parola, APP_KEY ve .env
içeriği gösterilmez. Test sonrasında dosya kaldırılabilir.

Kontrol, seçili PHP eklentilerini ve okunabiliyorsa Apache modüllerini
listeler. CGI/FastCGI modülü bulunması, klasör için çalıştırma izni
bulunduğu anlamına gelmez. AllowOverride/handler ayarları ve yeni PHP
Windows çalışma bağımlılıkları sunucu yöneticisi tarafından ayrıca
doğrulanmalıdır.

## 2. Yalnız deneme uygulamasına güncel PHP tanımla

Bilgi işlem mevcut uygulamanın PHP 8.0 ayarlarını koruyarak yalnız yeni
uygulama için PHP >= 8.2 ile bir Apache handler veya ayrı web sitesi
tanımlayabilir. Yeni PHP dosyaları çevrimdışı getirilebilir; tüm XAMPP'ın
değiştirilmesi veya sunucunun internete bağlanması zorunlu değildir.
Gerçek yöntem mevcut Apache yapılandırmasına bağlıdır.

EXE'yi kullanıcının elle başlatamaması ile Apache'nin onaylı bir PHP
çalışma ortamını çağırabilmesi farklı durumlardır. Dosya kopyalama
yetkisi, CGI çalıştırma izni bulunduğunu göstermez. Bu doğrulanmadan
çalışır bir klasör paketi sözü verilmemelidir.

## 3. Tam çevrimdışı paket hazırla

Çalışma ortamı netleşince `vendor`, derlenmiş `public/build` ve Excel
araçlarının Windows Python bağımlılıkları pakete dahil edilir. Sunucuda
Composer/npm/pip indirmesi yapılmaz. Paket Windows üzerinde doğrulanır;
veritabanı dökümleri ve özel .env dosyaları GitHub'a eklenmez.

## 4. Veritabanını ayır ve dene

Önce eski uygulama ve veritabanı yedeklenir. Deneme için `cte_x_test`
gibi ayrı bir veritabanı kullanılır. `.env` içinde DB_DATABASE ve
CTE_HOSTED_DATABASE bu ayrı veritabanına işaret eder; APP_URL gerçek
deneme adresine ayarlanır. Web girişi yalnız Laravel'in `public`
klasörünü sunmalıdır; private dosyalar HTTP'ye açılmamalıdır.

Önce giriş, liste, kayıt, dosya ve Excel işlemleri denenir. Kullanıcı
doğrulamasından önce eski çalışan uygulama değiştirilmez.

## Bilgi işleme iletilecek talep

Mevcut eski uygulamayı etkilemeden, `D:\xampp\htdocs\x` klasöründeki
Laravel deneme uygulaması için PHP 8.2 veya üzeri çalışma desteği
tanımlanmasını istiyoruz. Bağımlılıklar hazır ve çevrimdışı getirilecek;
sunucuda Composer/npm kurulumu veya internet erişimi gerekmeyecek.
Deneme ayrı bir MySQL veritabanında yapılacak.

Referanslar:
- https://laravel.com/framework/docs/12.x/deployment
- https://www.php.net/manual/en/install.windows.apache2.php
- https://httpd.apache.org/docs/2.4/howto/htaccess.html
