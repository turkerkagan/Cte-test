# Durum geçişlerinin sağlamlaştırılması

Bu değişiklikler mevcut iş akışlarına ortak geçiş kontrolleri ekler. Klinik, eğitim ve kurul süreçleri için kurum tarafından tanımlanmamış bir onay sırası varsayılmaz.

## Uygulanan kurallar

- Konum/görev atamalarında hükümlü kimliği rotadan alınır; farklı bir gövde kimliği reddedilir. Model doğrulaması form, toplu işlem ve aktarım yollarında da çalışır.
- Aktif atama `ended_at IS NULL` anlamındadır; gelecekte başlayacak açık atama kabul edilmez. Kapatılan atama yeniden açılmaz; yeni atama oluşturulur. Tekrar kapatma ilk tarihi korur.
- Aktif hükümlü ve ranza atamaları generated kolonlar üzerindeki benzersiz indekslerle korunur. Kaynak kayıtlar transaction içinde kilitlenir. Yerleşim aidiyeti, kurum, aktiflik ve kapasite doğrulanır. Kapasite 0, tanımlı bir üst sınır bulunmadığı anlamındadır.
- Dosya pasifleştirilince açık konum/görevler kapanır. Geçmişi bulunan dosya veya yerleşim kaydı başka kuruma/üst birime doğrudan taşınamaz. Aktif ataması bulunan kaynak pasifleştirilemez.
- Yapılandırılmış durum/risk/karar sözlüklerinde yeni değerler aktif tanımlardan seçilir. Değiştirilmeyen tarihsel değerler korunur. Sözlük henüz kurulmamışsa serbest metin uyumluluğu devam eder.
- Birim ve operasyon kayıtlarında kapatma `closed_at` alanını yazar; planlanan bitişi değiştirmez. Kapalı kayıtlar düzenlemeyle yeniden açılamaz; oluşturucu bilgisi korunur.
- İzinlerde tarih/saat biçimi ve açık uçlu tarih çakışmaları doğrulanır. Fiilî erken dönüş, yeni izinlere planlanan dönüş tarihine kadar engel olmaz. Eski kapalı kayıtlarda `closed_at` yoksa mevcut bitiş tarihi kullanılır; bilinmeyen fiilî dönüş tarihi üretilmez.
- Sayım, özet ve izinli listesi aynı izin sorgusunu kullanır. Açık ve başlamış izin kaydı esas alınır; hiç izin geçmişi bulunmayan eski kayıtlarda özel izin etiketi uyumluluk amacıyla kullanılır. Geciken dönüş, fiilen kapatılana kadar izinli kabul edilir.
- Nöbetlerde gerçek ve aktif personel, kurum, geçerli tür ve tam tarih aralığı aranır. Nöbet ve uygunsuzluk birbirini iki yönde kontrol eder. Otomatik plan tek transaction içinde, manuel kayıtlarla aynı kurum kilidini kullanır.
- Duyuru okuma işlemi idempotenttir. Yayınlanmayan/kapalı duyuru okundu yapılamaz. Okuma yetkisiyle açılan operasyon listelerinde taslaklar gizlenir; yönetim yetkisi bulunan kullanıcılar bunları yönetebilir.
- Sohbet GET istekleri veri değiştirmez. Görünen konuşma için CSRF korumalı POST ile okundu bilgisi gönderilir. Yalnız alıcı işaretleyebilir; genel düzenleme uçları mesajı değiştiremez. Kapalı/yayınlanmayan mesajlar görünmez.
- Yetkide `inherit` geçişi model olaylarını ve cache temizliğini çalıştırır. Cache hem değişiklik anında hem commit sonrasında geçersizleştirilir. Pasif rol rol yetkisi vermez. Oturum sürümü, veritabanı dışındaki session sürücülerinde de iptali uygular.
- Yükleme metadata hataları disk yazımını geri alır. Yemek belgesi doğrulanmadan kayıt oluşturulmaz; eski dosya yeni metadata commit edilmeden kaldırılmaz.
- Kalıcı silme ve fotoğraf silme, taşımadan önce kalıcı karantina günlüğü yazar. Kesinti sonrasında aşağıdaki kurtarma komutu metadata ile fiziksel dosyaları uzlaştırır.
- Yedek biçimi v2, hükümlü dosyalarıyla yemek belgelerini de kapsar. Generated kolonlar INSERT dökümünden çıkarılır; SQLite indeksleri de yedeklenir. Dosya yazma hataları başarı olarak raporlanmaz. v1 arşivler desteklenir; içermedikleri yemek dosyalarını geri getiremezler.
- Geri yüklemede bakım modu ön yedekten önce başlar. Geri yükleme ve rollback birlikte başarısız olursa uygulama bakımda kalır. Daha önce bakımda olan uygulama kendiliğinden açılmaz.
- Tekrarlanan legacy aktarımı mevcut düzenlemeleri, kapanma durumlarını ve silinmiş operasyonların silinmişliğini korur. Sohbet mesajları okuma geçmişinden önce aktarılır. Kaynak anahtarları kurumla birlikte eşleştirilir.
- Menü döngüsü engellenir; alt menüler özyinelemeli gösterilir. Kamera tekrar başlatmaları, modal sahipliği, bozuk localStorage ve geçersiz alt konum seçimleri kontrol edilir.
- Kayıtlı özel raporlar yalnız oluşturucusuna, paylaşılan raporlar rapor görüntüleme yetkisi bulunanlara listelenir.

## Kurulum ve kurtarma

Yeni migration: `2026_11_07_000001_enforce_transition_invariants.php`.

Dağıtım sırasında veritabanı yedeği alın ve `php artisan migrate` çalıştırın. Mevcut açık atamalarda mükerrer hükümlü/ranza bulunursa migration veri silmeden durur. Bu kayıtların gerçek geçmişi uzlaştırılmalıdır; otomatik olarak bir kayıt seçilip diğerleri kapatılmaz.

Kesintiye uğramış silme işlemleri için önce bakım moduna geçin, çalışan isteklerin bitmesini bekleyin, ardından:

```sh
php artisan cte:recover-deletions
```

Başarısız restore ve rollback sonrasında önce hata nedeni giderilmeli ve tutarlı yedek geri yüklenmelidir; uygulama bu durumda kendiliğinden açılmaz.

## Doğrulama

`TransitionInvariantTest`, atama kimliği sahteciliği, SQL seviyesinde benzersizlik, kapanma, kapasite, cache, eski önizleme, nöbet, izin, sohbet, dosya, yedek, karantina, menü, oturum iptali ve başarısız rollback senaryolarını kapsar. Tüm testler izole test veritabanında çalıştırılır.

Yerel XAMPP ayarındaki `cte_portal` veritabanı bulunmadığından mevcut kurulumda migration uygulanamamıştır. Yapılandırılmış MySQL kullanıcısının geçici test veritabanı oluşturma yetkisi de bulunmadığından MySQL testleri bu ortamda doğrulanamamıştır. SQLite testleri gerçek uygulama verisini değiştirmez.

Atama tarih aralıkları gün hassasiyetinde `[başlangıç, bitiş)` olarak değerlendirilir; aynı gün devir mümkündür. Bitiş fiilî kapanma olduğu için gelecekte olamaz. Geçmişe dönük toplu konum değişikliğinde eski konum, yeni konumun başlangıç tarihinde kapanır; böylece iki konumun tarih aralıkları üst üste binmez. İzin çakışmaları ise mevcut gün hassasiyetini korur; saatlerin biçimi doğrulanır ancak aynı gün içindeki ayrı izinler otomatik olarak birleştirilmez.
