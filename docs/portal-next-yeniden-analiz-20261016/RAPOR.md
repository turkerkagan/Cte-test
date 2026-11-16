# Next Portal — Yeniden analiz ve iyileştirme raporu

Tarih: 16 Ekim 2026  
Kapsam: `portal-next` çalışma ağacının önceki düzeltmeler dahil mevcut hali.  
Yöntem: kaynak incelemesi, mevcut otomatik testler, derleme, tür ve sözdizimi kontrolleri, izole hata üretme deneyleri.

## 1. Yönetici değerlendirmesi

Sistem önceki incelemeye göre daha sağlam: yetki kontrolleri, geri yükleme sonrası oturum geçersizleştirme, ortak tablo etkileşimleri ve kullanıcı silme sonrası dosya temizleme için regresyon koruması mevcut. Buna rağmen genel testlerin başarılı olması bütün hata senaryolarının kapsandığı anlamına gelmiyor.

Yeni incelemede dört çalışma zamanı sorunu dört ayrı tanı testiyle yeniden üretildi. En önemli bulgu, ikinci yedek hedefinin sürekli başarısız olması halinde zamanlanmış denemelerin yeni yerel yedekler üretmeye devam etmesi ve saklama sınırının uygulanmaması. İkinci önemli bulgu, indirme sonrası fiziksel olarak silinemeyen mesaj eklerinin normal temizlik akışında tekrar ele alınmaması.

Bu turda uygulama kodu değiştirilmedi. Rapor, kanıt dosyaları ve yalnızca sentetik veri kullanan tanı testleri eklendi. Önceki çalışma ve kullanıcı değişiklikleri korundu. Bulgular aşağıda düzeltme önceliğine göre ayrıldı.

## 2. Doğrulanmış bulgular

Öncelikler: **P1** ilk düzeltme dilimi; **P2** sonraki kontrollü sürüm. Bunlar gerçekleşmiş üretim olayları değil, izole ortamda yeniden üretilen mevcut davranışlardır.

### B1 — P1: Arşiv hedefi hatasında yedek birikmesi ve eksik sağlık görünümü

**Kaynak:** `app/Services/Administration/BackupPlans.php:91–140`, `app/Services/Administration/ManagedDatabase.php:222`, `routes/console.php:15`.

Yerel yedek, ikinci hedefe kopyalanmadan önce oluşturuluyor. Kopyalama/hedef kontrolü başarısız olursa saklama sınırını uygulayan bölüm çalışmıyor. Başarısız çalışma kaydında tamamlanmış gün bilgisi bulunmadığından sonraki zamanlanmış deneme aynı gün yeniden yerel yedek oluşturabiliyor. Zamanlayıcı bu komutu her dakika çağırıyor; gerçek deneme sıklığı çalışma süresine ve çakışma kilidine bağlı.

**Deney:** İzole arşiv hedefi, uygulamanın reddettiği bir sembolik bağlantı olarak kuruldu. `keep=1` ile üç zamanlanmış deneme yapıldı. Üç deneme de 422 ile reddedildi; üç aktif yerel yedek kaldı. Plan durumu “Başarısız” iken genel `backup.success` değeri `true` kaldı. Yerel kopyanın başarıyla oluşması doğru; ancak bu değer ikinci hedefin başarısını göstermiyor.

**Etki:** Sürekli hedef hatasında kontrolsüz disk büyümesi; operatörün yerel yedek başarısını uçtan uca plan başarısı sanması. Diskin dolduğu ayrıca test edilmedi.

**Önerilen düzeltme:** Yerel oluşturma ve hedef teslimatını ayrı durumlar olarak modelleyin. Başarısız kopyalamada aynı doğrulanmış yedeğin teslimatını tekrar deneyin; denemeler arası artan bekleme ve sınır koyun. Yerel geçici yedekler için bağımsız kota uygulayın. Son geçerli yedeği koruyun; yeni doğrulanmış kopya oluşmadan körlemesine silmeyin. Sağlık ekranında son deneme, son yerel başarı ve son hedef başarısı ayrı görünsün.

**Kabul ölçütü:** Üç ardışık hedef hatası yeni üç tam yedek üretmemeli; hedef hatası görünür olmalı; hedef düzeldiğinde bekleyen kopya teslim edilebilmeli.

### B2 — P1: İndirme sonrası silinemeyen mesaj eki temizlik dışında kalıyor

**Kaynak:** `app/Http/Controllers/PersonalMessagesController.php:101–130`, `app/Console/Commands/CleanPrivateDocuments.php:43–60`.

Alıcı indirdiğinde kayıt `deleted_at` ile işaretleniyor; fiziksel dosya silme işlemi transaction tamamlandıktan sonra yapılıyor. `delete()` false dönerse sonuç işlenmiyor. Zamanlanmış temizlik yalnızca `deleted_at` boş kayıtları tarıyor. Kullanıcı silme akışına eklenen kalıcı silme kuyruğu bu indirme akışında kullanılmıyor.

**Deney:** Dosya silme çağrısı bir defalığına false dönecek şekilde taklit edildi. İndirme 200 döndü; kayıt silinmiş işaretlendi. Süre dolmuş hale getirip gerçek temizlik komutu çalıştırıldı: komut başarılı tamamlandı, dosya hâlâ diskteydi, kuyrukta kayıt yoktu. Yeniden indirme 404 döndü.

**Etki:** Uygulamada görünmeyen özel dosyalar depoda kalabilir. Disk kullanımı ve dosya saklama politikası güvenilir biçimde uygulanmaz.

**Önerilen düzeltme:** İndirme sonrası silmeyi de aynı transaction içinde kalıcı silme kuyruğuna kaydedin. Kuyruk tüketicisinin soft-delete edilmiş ek referansını nasıl ele alacağını açıkça tanımlayın; mevcut tüketiciye yalnızca enqueue eklemek yeterli olmayabilir. Silme başarısızlığı tekrar denenebilmeli ve gözlemlenebilmelidir.

**Kabul ölçütü:** İlk silme başarısız olduğunda dosya kuyrukta kalmalı; sonraki başarılı denemede hem dosya hem iş kaydı temizlenmeli. Yeniden çalıştırma güvenli olmalı.

### B3 — P2: Silinmiş mesaj ve eki için farklı erişim kuralları

**Kaynak:** `app/Http/Controllers/PersonalMessagesController.php:36–44, 108–112`.

Mesaj okuma sorgusu kullanıcının `sender_deleted` / `recipient_deleted` durumunu dikkate alıyor. Ek indirme sorgusu yalnızca gönderen veya alıcı kimliğine bakıyor.

**Deney:** Gönderen kendi mesajını sildi. Mesaj detay isteği 404, bilinen ek adresine istek 200 döndü.

**Etki:** Silinen öğenin erişim davranışı tutarsız. Bu deney başka bir kullanıcının dosyasına erişim açığı göstermiyor; kullanıcı mesajın asıl tarafı olmaya devam ediyor.

**Önerilen düzeltme:** Silme yalnızca gelen/giden kutusunda gizleme ise bunu ürün dili ve kurallarda açık hale getirin. Silme ilgili kullanıcının erişimini kaldırıyorsa ek indirmede aynı taraf bazlı kontrolü kullanın. Diğer tarafın meşru erişimini kaldırmayın.

**Kabul ölçütü:** Gönderen/alıcı ve silinmiş/silinmemiş kombinasyonları için mesaj ve ek erişimleri aynı tanımlı politikayı izlemeli.

### B4 — P2: Mesaj listesinde N+1 ek sayımı

**Kaynak:** `app/Http/Controllers/PersonalMessagesController.php:26–29`.

Her mesaj için ayrı ek sayma sorgusu çalışıyor.

**Deney:** 80 sentetik mesajla liste istendi; yalnızca `message_attachments` sayımı için 80 ayrı SQL sorgusu kaydedildi. Bu sayı toplam sayfa sorgusu değildir.

**Etki:** Mesaj sayısı büyüdükçe gereksiz veritabanı gidiş gelişleri oluşur. Gerçek ortam gecikmesi veya eşzamanlı kullanıcı kapasitesi bu deneyle ölçülmedi.

**Önerilen düzeltme:** Sayfadaki mesaj kimlikleri için tek gruplu sorgu veya uygun toplu sayım kullanın. Mevcut son kullanma tarihi ve silinme koşullarını koruyun. Gerçek MariaDB sorgu planına bakmadan yeni indeks eklemeyin.

**Kabul ölçütü:** 1 ve 80 mesajda ek sayımı için sorgu sayısı sabit kalmalı; sonuçlar mevcut davranışla aynı olmalı.

## 3. Kaynak incelemesiyle görülen kullanıcı deneyimi eksikleri

### U1 — P2: Düzenleme penceresinde kaydedilmemiş değişiklik koruması yok

`resources/js/components/PortalDialog.tsx:17–25` Escape, dış alana tıklama ve kapatma düğmesinde yalnızca `busy` durumunu kontrol ediyor. `ModuleRecordDialog.tsx:28–31` değişiklik yapılmış olup olmadığını izlemiyor. `resources/js/pages/ModuleRecordEditor.tsx:27–31` form değerlerini bileşen içinde tutuyor.

Bileşen kapatılıp kaldırıldığında kaydedilmemiş değerleri koruyacak ortak mekanizma bulunmuyor. Bu tespit kaynak akışı üzerinden yapıldı; bu turda yeni tarayıcı etkileşim testi yapılmadı.

**Öneri:** Değişmiş formu kapatırken “Düzenlemeye devam et / Değişiklikleri bırak” seçimi; kayıt başarılıysa doğrudan kapanış. Hassas veriyi varsayılan olarak localStorage’a yazmayın. Çakışmada kullanıcının girdisini koruyup mevcut kayıtla alan bazında karşılaştırın.

### U2 — Tek kullanımlık eklerin teslimat politikası netleştirilmeli

Varsayılan indirme politikasında alıcı isteği tamamlanırken dosya siliniyor. Sunucunun yanıtı hazırlaması, tarayıcının dosyayı eksiksiz kaydettiğini kanıtlamaz. Bağlantı kesilmesi halinde tekrar indirme mümkün olmayabilir. Bu bilinçli ürün politikası olabilir; doğrulanmış ağ arızası sonucu olarak sunulmuyor.

**Öneri:** Kısa süreli, kullanıcıya bağlı yeniden indirme penceresi veya açık teslim alma adımı değerlendirin. Gizlilik/saklama beklentisini değiştirmeden önce politika kararı verin.

## 4. Önceki talepler ve düzeltmelerin durumu

| Alan | Güncel inceleme sonucu |
|---|---|
| Veri tablosunda İşlemler sütunu | Kaynak taramasında standart başlık/kolon tanımlarında eşleşme bulunmadı. Ortak DataTable ayrı işlem sütunu üretmiyor. Bu, dinamik tüm sayfalar için yeni bir görsel test iddiası değildir. |
| Satıra tıklayınca düzenleme | DataTable `onRowClick`, Enter/Space ve iç kontrolleri ayırma desteği içeriyor; People bunu kullanıyor. Modül düzenleme penceresi mevcut. |
| Ad soyad hover | RecordHoverCard mevcut; farede 700 ms bekleme ve klavye odağında açılma sağlıyor. Yetkili liste verisi kullanılıyor. |
| Geri yükleme sonrası oturumlar | Oturum kuşağı/session version regresyonları genel test paketinde geçti. |
| Sohbet yazma yetkisi | Sadece okuma yetkisinin yazmaya dönüşmesini engelleyen regresyonlar geçti. |
| Hesap silme sonrası ek temizliği | Kalıcı kuyruk ve regresyon mevcut; B2 farklı bir akış olan indirme sonrasını kapsıyor. |
| Kullanıcı adı uzunluğu | 100 karakteri destekleyen sonraki migration mevcut; eski 50 karakter varsayımı hata olarak tekrar edilmedi. |
| Misafir profil değişikliği | PortalActivity yazma HTTP yöntemlerini engelliyor. Controller tek başına incelenerek yetki açığı sonucuna varılmadı. |
| Windows dağıtımı | Windows CI, kurulum/geri yükleme kontrolleri ve zamanlanmış görev betikleri mevcut. Bu turda Windows üzerinde çalıştırılmadı. |

## 5. Temiz kod, bakım ve atık kod değerlendirmesi

Ölçüm kapsamı `app`, `routes`, `database`, `resources/js`, `resources/css` altındaki PHP/TS/TSX/CSS dosyalarıdır; vendor, node_modules ve üretilmiş build çıktıları kapsam dışıdır.

- 278 kaynak dosyası, 22.499 satır.
- 500 satırı aşan 5 dosya.
- 200 karakteri aşan 1.161 kaynak satırı. Bu tek başına hata veya atık kod kanıtı değil, okunabilirlik işaretidir.
- En büyük dosya `record-action-modal.css`: 1.269 satır.
- `StandaloneRecovery.php`: 593; `CountParser.php`: 580; `app.css`: 519; `institution-original.css`: 512 satır.
- Strict TypeScript kullanılmayan yerel değişken/parametre kontrolü başarılı. Bu kontrol kullanılmayan CSS seçicilerini, PHP sınıflarını veya dinamik rota kodlarını kanıtlamaz.

**Bakım öncelikleri:**

1. `BackupPlans` içindeki oluşturma, kopyalama, saklama ve çalışma kaydı sorumluluklarını küçük, test edilebilir adımlara ayırın. Önce B1 davranış testini ekleyin, ardından refactor yapın.
2. Mesaj eki erişim ve yaşam döngüsü kurallarını ortak servis/politikada toplayın. B2 ve B3 farklı endpointlerde aynı kuralların dağılmasının sonuçları.
3. Tek satıra sıkışmış koşulları ve birleşik ifadeleri biçimlendirin. `PortalActivity` ve `BackupPlans` incelemeyi zorlaştıran somut örnekler. Davranış değişikliğiyle büyük biçimlendirmeyi aynı değişiklikte karıştırmayın.
4. Modal CSS’ini sorumluluğa göre bölmek için önce seçici envanteri çıkarın. Benzer görünen stilleri referans ve görsel kontrol olmadan silmeyin.
5. Ortak form kapatma ve çakışma davranışını merkezileştirin; sayfa başına farklı koruma üretmeyin.
6. Atık kod temizliğini kanıta dayandırın: statik import/rota taraması, dinamik kullanım kontrolü, ardından küçük silme değişikliği ve regresyon. Bu inceleme “hiç atık kod yok” sonucu vermiyor.

## 6. Doğrulama sonuçları ve sınırlar

| Kontrol | Bu turdaki sonuç | Kanıt |
|---|---|---|
| Genel PHP testleri | 402 test / 4.743 assertion başarılı | phpunit.log, phpunit.xml |
| Yeni tanı deneyleri | 4 test / 19 assertion; mevcut sorunlar yeniden üretildi | ReauditEvidenceTest.php, probes.log, probes.xml |
| Frontend SSR testleri | 23 başarılı | frontend.log |
| Strict TypeScript | Çıkış kodu 0; unused locals/parameters dahil | typescript.log boş hata çıktısı |
| Üretim frontend derlemesi | Başarılı; çıktı geçici klasöre yazıldı | build.log |
| PHP sözdizimi | app/routes/database/config altında 187 dosya, hata yok | metrics.json |

**Tanı testleri hakkında:** `ReauditEvidenceTest.php` uygulamanın normal test dizinine eklenmedi. Testlerin yeşil olması sorunların düzeltildiğini değil, raporlanan davranışların tekrar üretilebildiğini gösterir. Düzeltme sırasında beklentiler doğru davranışı doğrulayacak regresyon testlerine dönüştürülmelidir. Testler yalnızca testing ortamı ve bellek içi SQLite üzerinde çalışmayı kabul eder; özel dosyalar geçici dizinde tutulur.

Tekrar çalıştırma (proje kökünde):

```sh
/Applications/XAMPP/xamppfiles/bin/php vendor/bin/phpunit docs/portal-next-yeniden-analiz-20261016/ReauditEvidenceTest.php
```

Bu turda canlı veri tabanı değiştirilmedi, migration uygulanmadı, gerçek mesaj gönderilmedi ve üretim dosyaları silinmedi. Yeni hata deneylerinde SQLite kullanıldı; MariaDB kilitlenme/eşzamanlılık davranışları ayrıca doğrulanmalı. Önceki turdaki MariaDB ve tarayıcı sonuçları yeni çalıştırılmış gibi sayılmadı. Canlı yük testi, güncel bağımlılık zafiyet taraması, Windows görevlerinin gerçek çalışma koşulları ve tam ekran okuyucu testi bu raporun doğrulama kapsamı dışında.

Mevcut CI Windows üzerinde PHP/frontend ve MariaDB kurulum/geri yükleme adımları içeriyor. İncelenen workflow’da gerçek tarayıcı etkileşim testi adımı yok. Özellikle satıra tıklama, hover, klavye ile modal kapatma, kaydedilmemiş değişiklik ve 409 çakışma akışları için bu eksik tamamlanmalı.

Dağıtım öncesi önceki düzeltmelerin yeni `private_file_deletions` migration’ı ve kurtarma şemasıyla birlikte hedef ortamda uygulanmış olması doğrulanmalı. Önceki incelemedeki canlı migration durumu bu turda yeniden ölçülmedi.

## 7. Uygulamayı ileri taşıyacak öneriler

Mevcut filtre, yetki, yedekleme, kayıt geçmişi ve planlama yapılarını temel alan öneriler; bütün bu özelliklerin bugün hiç bulunmadığı iddia edilmiyor. Öncelik sırası güvenilirlik, günlük işlem verimi, sonra karar desteği.

| Öneri | İlk sürümün somut kapsamı | Beklenen fayda / ölçüm | Göreli efor |
|---|---|---|---|
| Güvenilirlik merkezi | Son başarılı yerel/hedef yedeği, bekleyen dosya silme işleri, tekrar denemeler, zamanlayıcı gecikmesi | Hata fark etme süresi; yaşlanmış iş sayısı | Orta |
| Alan bazında çakışma karşılaştırması | “Benim girdim / Güncel kayıt” farkları; yeniden doğrulanmış birleştirme | 409 sonrası kaybedilen iş ve tamamlanamayan kayıt sayısı | Orta |
| Yetki simülatörü | Yönetici seçtiği rol/kullanıcı için ekran ve alan erişiminin nedenini görsün | Yetki sorununu çözme süresi; yanlış yetkilendirme | Orta |
| İşlem önizleme | Toplu işlem öncesi etkilenecek kayıt sayısı, değişecek alanlar, kapsam dışı kayıt nedenleri | Yanlış toplu işlem ve iptal oranı | Orta |
| Vardiya devir özeti | Kullanıcının yetkisi dahilinde son değişiklikler, açık işler ve yaklaşan süreler | Devir teslim hazırlama süresi | Orta |
| Veri kökeni ve kalite paneli | Alanın kaynağı, son doğrulama zamanı, çelişki ve eksik kayıt iş listesi | Düzeltilen tutarsız kayıt oranı | Orta–yüksek |
| Klavye komut paleti | Yetkili ekranlara gitme, mevcut listede arama/filtre açma, yeni kayıt | Sık işlerde gereken tıklama/süre | Düşük–orta |
| İzole geri yükleme provası | Yedeği üretimden ayrı ortamda açma; kayıt/dosya bütünlüğü raporu | Başarıyla doğrulanmış yedek yaşı; geri dönüş süresi | Yüksek |
| Planlama senaryo karşılaştırması | Görev/nöbet planını yayınlamadan önce alternatiflerin yük dağılımını karşılaştırma | Dağılım dengesizliği, elle düzeltme sayısı | Yüksek |

Yetki simülatörü ve komut paleti mevcut erişim kurallarını kullanmalı. Veri kökeni/kalite paneli alan bazlı yetkiyi aşmamalı. Vardiya özeti varsayılan olarak uygulama içinde kalmalı; dış mesajlaşma veya otomatik kişisel veri paylaşımı gerektirmemeli. Senaryo karşılaştırması karar desteği sunmalı; nihai planı yetkili kullanıcı onaylamalı.

## 8. Önerilen uygulama sırası

1. **Güvenilirlik düzeltmeleri:** B1 ve B2; ardından hedef hatası, tekrar deneme, disk silme hatası ve idempotent çalışma regresyonları.
2. **Tutarlılık ve performans:** B3 ürün politikasını netleştirme, B4 toplu sayım; MariaDB sorgu planı ve HTTP erişim testleri.
3. **Form güvenliği:** Kaydedilmemiş değişiklik koruması ve çakışma karşılaştırması; gerçek tarayıcı testleri.
4. **Bakım:** Dar kapsamlı biçimlendirme, servis sorumluluklarının ayrılması, doğrulanmış atık kod temizliği. Her adımda ilgili testleri çalıştırma.
5. **Ürün geliştirme:** Önce güvenilirlik merkezi ve işlem önizleme; kullanıcı geri bildirimiyle komut paleti/devir özeti; sonra senaryo planlama.

Her düzeltmede başarı ölçütü yalnızca testlerin geçmesi olmamalı: raporlanan hata artık yeniden üretilememeli, yetkisiz işlemler reddedilmeye devam etmeli ve mevcut veri/saklama sözleşmesi açıkça korunmalı.
