# Birim ve Tablo Oluşturucu

Admin Paneli → **Birim ve Tablo Oluşturucu** → **Yeni birim** yolunu kullanın.

1. Birim adını, benzersiz kodunu, açıklamasını ve kayıt türlerini girin.
2. Alan ekleyin. Her alanın kalıcı anahtarını, görünen başlığını ve türünü belirleyin. Kısa metin, uzun metin, sayı, tarih ve seçim listesi desteklenir. Seçim seçeneklerini ayrı satırlara yazın.
3. Zorunluluk, tabloda gösterim ve filtre olarak kullanım seçeneklerini belirleyin. Yukarı/aşağı düğmeleri form ve sütun sırasını birlikte değiştirir.
4. Görüntüleme ve düzenleme yetkisi verilecek rolleri seçin. Düzenleme görüntülemeyi de kapsar. Ana yönetici tüm birimlere erişebilir; kullanıcıya özel izinler mevcut Kullanıcılar ekranından yönetilir.
5. Kaydedin. Aktif ve görünür birim yetkili kullanıcıların menüsünde ve hükümlü dosyasındaki birim bağlantılarında yer alır. Birimdeki “Hükümlü seç” bağlantısından dosyaya geçip ilgili birim için yeni kayıt açılabilir.

“Menüde göster” yalnızca gezinme görünürlüğünü belirler; erişim yetkisini kaldırmaz. “Aktif” kapatıldığında kayıtlar korunur ve birim adresine erişim durur. Hassas işareti kayıt oluşturma/güncelleme sırasında kayda aktarılır; erişim rol ve kullanıcı izinleriyle denetlenir.

## Veri yapısı

Birim ve alan tanımları `unit_definitions` tablosunda, kayıtlar mevcut `unit_records` tablosunda saklanır. Özel alan değerleri `data` içinde kalıcı alan anahtarlarıyla tutulur. Başlıklar değiştirildiğinde değerler aynı anahtarlardan okunmaya devam eder. Her birim için fiziksel SQL tablosu açılmaz.

Kayıt bulunan birimde alan anahtarını veya türünü değiştirme ve alan kaldırma işlemleri engellenir. Başlık, sıra, listede gösterim ve filtre ayarları değiştirilebilir. Birim kodu oluşturulduktan sonra değiştirilemez. Seçenek veya kayıt türü listesini değiştirirken eski kayıtların düzenlenebilmesi için kullanılan seçenekleri koruyun.

Yerleşik birimler özel iş kurallarını korur; bu oluşturucu yeni özel birimleri yönetir. Yeni birimler ortak form, tablo, arama, sayfalama ve kayıt kapatma davranışlarını kullanır. Otomatik nöbet planlama veya izin çakışma denetimi gibi birime özgü yeni iş kuralları ayrıca geliştirilir.

## Kurulum / doğrulama

Yeni migration normal güncelleme akışında `php artisan migrate --force` ile uygulanır. Ön yüz için `npm run build` çalıştırılır.

Hedefli testler: `php artisan test --compact --filter='UnitDefinitionBuilderTest|PhaseFiveUnitModuleTest'`.

Bellek sınırı düşük sistemlerde tüm paket: `php -d memory_limit=512M vendor/bin/phpunit --no-progress`.
