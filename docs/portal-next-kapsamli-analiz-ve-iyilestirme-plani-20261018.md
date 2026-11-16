# portal-next — Kapsamlı Analiz ve İyileştirme Planı

> **Tarih:** 18 Ekim 2026
> **Kapsam:** `/portal-next` (Laravel 12 + Inertia.js 2 + React 19 + Vite)
> **Durum:** Bakım sonuçları bölüm 7–8’de, sonraki `cte` kurulum talebinin sonucu bölüm 9’dadır. İlk bakımda canlı değişiklik yapılmadı; sonraki açık kurulum talebiyle mevcut `cte` altında yeni tablolar kuruldu. Eski tablolar korundu; dağıtım ve commit yapılmadı.
> **Çalışma ağacı durumu:** 125 commit edilmemiş değişiklik (90 değiştirilmiş + 34 yeni dosya) — inceleme bu WIP durumuna aittir.

---

## 1. Genel Değerlendirme

**Genel kalite: İyi (7/10).** Güvenlik temelleri sağlam (CSRF istisnası yok, giriş hız sınırlama + hesap kilidi, SQL enjeksiyonuna karşı whitelist/binding, audit'te hassas alan temizliği, `dangerouslySetInnerHTML`/`console.log`/`@ts-ignore` hiç yok). Ancak:

| Alan | Durum |
|---|---|
| 🟢 Test altyapısı | 424 PHPUnit testi (4930 assertion) + 13 frontend testi, **hepsi geçiyor** |
| 🟢 Typecheck & Build | `tsc --noEmit` temiz, `vite build` 6.5 sn'de başarılı |
| 🔴 Kırık sayfa | **Sol menüdeki "Çalışma merkezi" bağlantısı uygulamayı çökertiyor** |
| 🟠 Atık kod | ~400 satır ölü CSS, 28 dosyada ölü prop, 16+ kopya XSRF kodu, ölü test fixture'ları |
| 🟠 Performans | Yönetici toplu işlemlerinde kayıt-başına işlem/döngüler (N+1 ve iç içe transaction) |
| 🟠 Süreç | 125 dosyalık commit edilmemiş WIP; CI kırık sayfayı yakalayamıyor |

**Kapsam ölçeği:** ~16.3k satır PHP, ~6k satır TSX/TS, ~3.3k satır CSS, 92 test dosyası.

---

## 2. Doğrulanan Kalite Kapıları (çalıştırıldı)

- ✅ `npm run typecheck` — hatasız
- ✅ `npm run test:table` — 13/13 geçti
- ✅ `npm run build` — başarılı. Chunk boyutları: `app` 441 kB (143 kB gzip), **`login-scene` 457 kB (115 kB gzip)**, `StaffTree` 190 kB, `AdminWorkspace` 48 kB
- ✅ `php vendor/bin/phpunit` — **OK (424 test, 4930 assertion, ~50 sn)**

---

## 3. Bulgular

### 🔴 KRİTİK

**K1 — "Çalışma merkezi" sayfası tarayıcıda çöküyor (menüde canlı bağlantı var)**

- `routes/web.php:50` → `/calisma-merkezi`, `WorkspaceController@index` → `Inertia::render('Workspace')` (`WorkspaceController.php:30`)
- Sol menüde **her personel için** "Kullanıcı İşlemleri → Çalışma merkezi" bağlantısı var (`HandleInertiaRequests.php:31`)
- Ancak `app.tsx:12`'deki 36 sayfalık haritada **`Workspace` yok** ve satır 13 `throw new Error('Bilinmeyen sayfa: Workspace')` fırlatıyor

**Sonuç:** Kullanıcı menüden tıkladığında Inertia sayfayı çözemez, hata ekranı/beyaz ekran gelir. Tüm testlerden kaçmış çünkü `WorkspaceTest.php` yalnızca JSON uç noktalarını test ediyor; hiçbir test sunucudaki `Inertia::render` adlarını frontend haritasıyla karşılaştırmıyor. (Doğrulandı: haritada olmayan tek sayfa `Workspace`; `LeaveEditor`/`SentenceEditor` ise bilinçli alt bileşenler.) Özelliğin dosyaları (`WorkspaceController.php`, `Workspace.tsx`, `lib/workspace.ts`) henüz commit edilmemiş — muhtemelen haritaya ekleme adımı unutuldu.

**Düzeltme:** `app.tsx:12` haritasına `Workspace: () => import('./pages/Workspace')` ekleyin. Kalıcı koruma için `Inertia::render(...)` adlarını `app.tsx` haritasıyla karşılaştıran bir PHPUnit testi yazın.

### 🟠 YÜKSEK

**Y1 — `accountAction` ölü prop: 28 dosyada taşınan "Çıkış yap" butonları hiç render edilmiyor**
`AppShell.tsx:110` prop'u `accountAction: _accountAction` ile bilinçli yok sayıyor; 27 sayfa (People, Users, Institution, AdminWorkspace...) başlık alanına buton geçiriyor ama ekranda çıkmıyor. Karar verin: ya render edin, ya prop'u ve 28 kullanımı silin.

**Y2 — XSRF token okuma kodu 16+ noktada kopyalanmış**
30 satırda `XSRF-TOKEN` geçiyor; hepsi `split('; ').find(...)?.slice(11)` sihirli sayısını kullanıyor (`ModuleDesigner.tsx:34` ise daha doğru biçimde `.slice('XSRF-TOKEN='.length)`). `lib/http.ts` içinde tek `xsrfToken()` yardımcısına indirin.

**Y3 — ~400 satır ölü CSS**
`record-action-modal.css` (~300+ satır: `.cte-record-permission-note`, `.cte-empty-rooms-body`, `.cte-ilkgorus-ds-actions` vb.), `institution-original.css` (`.cte-sohbet-container` bloğu, `.btn-view` vb.), `cte-design-system.css` (`.cte-kisayolu-container`). Spot kontrollerde TS/TSX'te **0 kullanım** — koğuş/sohbet ekranları farklı sınıflara geçmiş, eskiler kalmış.

**Y4 — Login sayfasında 1.27 MB Three.js kaynağı**
`Login.tsx:9` dekoratif 3D sahneyi koşulsuz `import()` ediyor (build çıktısı 457 kB / 115 kB gzip) ve hata `catch(() => {})` ile tamamen yutuluyor. En kritik sayfada mobil/yavaş bağlantıda ciddi maliyet. Koşula bağlayın (`prefers-reduced-motion` + masaüstü genişliği) veya hafif CSS/canvas animasyonuyla değiştirin.

### 🟡 ORTA — Backend

| # | Bulgu | Konum |
|---|---|---|
| B1 | **bcrypt 72-bayt parola kontrolü hiç çalışmıyor**: `config('hashing.driver')` null (config/hashing.php yok) → koşul hep false; 72+ baytlık parolalar sessizce kesiliyor | `Security/PasswordPolicy.php:12` |
| B2 | **Yönetici hesap koruması tutarsız**: silme `türker/turker/admin`'i koruyor ama `save()`, `resetPassword()` yalnız `türker`'i, `terminateSessions()` hiçbirini; ad değişince koruma kayboluyor | `Administration/UserAdministration.php:48,97,113,159` |
| B3 | `SESSION_SECURE_COOKIE` `.env.example`'da yok; tanımsızken çerez `Secure` bayrağı olmadan gönderiliyor | `config/session.php:172` |
| B4 | **Telefon arşivleme = fiziksel silme** (diğer tüm türler soft archive yaparken) — geri alınamaz veri kaybı | `Institution/InstitutionRecords.php:238` |
| B5 | **N+1**: kismiarama listesinde satır×option başına seçenek etiketi sorgusu; ayrıca özet için 3 ayrı COUNT + gereksiz `announcement_reads` | `Institution/InstitutionRecords.php:111-137` |
| B6 | **Toplu işlemlerde kayıt-başına `save()`** (her biri kendi transaction'ı, kilidi ve audit'i): nöbet planı gün-başına (~366 gün!), toplu durum (500 kişi), toplu koğuş (500 koğuş) | `DutyPlanning.php:65`, `PersonBatchActions.php:67`, `HousingDefinitions.php:44` |
| B7 | SQL dışa aktarma **tüm tabloların tüm satırlarını belleğe** alıyor (sınır yok) | `Administration/SqlExports.php:19` |
| B8 | Geri yükleme başarısız olursa dosya tabanlı oturum rotasyonu geri alınamıyor (transaction içinde); ayrıca **boş catch** operatöre hata göstermiyor | `StandaloneRecovery.php:510`, `ManagedDatabase.php:312`, `public/recovery.php:47` |
| B9 | `json_encode` bayraksız kullanım (yanındaki komşu çağrı `JSON_THROW_ON_ERROR` kullanırken) → token zayıflaması | `PersonBatchActions.php:62` |
| B10 | LIKE aramalarında `%`/`_` kaçışı yok (enjeksiyon değil ama joker etkisi) | `InstitutionRecords.php:80`, `CaseList.php:175`, `ManagementReports.php:31,51,58` |
| B11 | `MealsController.php:30` mime haritasında varsayılan yok (500 riski); iki migration aynı zaman damgasını paylaşıyor (gizli sıralama varsayımı); `routes/web.php:76` her istekte JSON dosyası okuyor | çeşitli |

### 🟡 ORTA — Frontend

- **F1** — `PageAccessTable.tsx:69` `key={JSON.stringify(selections)}`: başka satır işaretlenince kullanıcının doldurduğu toplu yetki formu sıfırlanıyor (kullanıcıya yansıyan bug).
- **F2** — `JSON.stringify` render/effect bağımlılığı ~10 noktada (`WorkflowList.tsx:33`, `PersonDocuments.tsx:16`...): CPU maliyeti + anahtar sırasına duyarlı yanlış negatif riski.
- **F3** — `any` yoğunluğu: `AdminWorkspace.tsx` (tüm tablo hücreleri), `SqlConversion/DatabaseTools/BuiltinMenuEditor`, `MenuEditor.items: any[]`.
- **F4** — Boş `catch`: `AppShell.tsx:164` (20 sn'lik gösterge polling'i) ve `Login.tsx:9`.
- **F5** — Firmasız `key={index}`: `SentenceEditor.tsx:13`, `HomeBulkOperations.tsx:132`, `CountComparisonTable.tsx:9` vb.
- **F6** — Tarih biçimlendirme dağınık: 3 kopya `Intl.DateTimeFormat` + çıplak `toLocaleString('tr-TR')` + `toLocaleDateString('sv')` hilesi. `lib/format.ts` önerilir.
- **F7** — `tsconfig.json` testleri kapsamıyor; `noUnusedLocals/Parameters` kapalı → atık kod kör noktası.
- **F8** — 19 adet ölü test fixture (~380 satır, `tests/frontend/*Fixture.tsx`) hiçbir test tarafından import edilmiyor; `PortalInteractionsFixture` K1'i maskeliyor olabilir. `package.json`'daki `test:table` script'i artık 2 paket çalıştırıyor → `test:frontend` olarak yeniden adlandırılmalı.
- **F9** — `PersonalMessages.tsx` 479 satırda 47 adet satır içi stil; `AppShell.tsx:126,173` çift `usePage()`; `StaffTree.tsx:31` `!` non-null assertion; `window.confirm` kullanımı PortalDialog ile tutarsız; çift polling (20sn göstergeler + 20sn useLiveList + 10sn Chat).
- **F10** — `preserveState`/`onError` kullanımı tutarsız (`Chat.tsx:83,108,138` tam sayfa yükleme yapıyor); dış bağlantı `rel` nitelikleri ve XSS yüzeyi temiz (olumlu).

### ⚪ DÜŞÜK / Atık Kod (topluca temizlenebilir)

Kullanılmayan import'lar (`BuiltinMenu.php:4`, `SqlExports.php:5`), ölü null kontrolü (`ManagementReports.php:53-62`), yinelenen atama (`PersonBatchImport.php:43`), etkisiz `payload` yasağı (`HomeRecords.php:77`), etkisiz `deleted_at => null` (`PersonalMessagesController.php:127`), `cte_sql_dis_metin()` için gizli require-sırası bağımlılığı, `routes/web.php:76`'da kullanılmayan `$alias`, tek-satır yoğun JSX stili, ikonlarda `aria-hidden` eksikleri, `pages/` altındaki alt bileşenler (`LeaveEditor`, `SentenceEditor`).

✅ İyi haber: `app/` ve `resources/js` içinde **hiç TODO/FIXME/HACK yok** ve **hiç `console.*`/`@ts-ignore` kalıntısı yok** — disiplinli bir kod tabanı.

---

## 4. İyileştirme Planı

### 🚨 P0 — Hemen (bugün)

- [ ] **K1'i düzelt:** `app.tsx:12` haritasına `Workspace: () => import('./pages/Workspace')` ekle.
- [ ] "Sunucudaki her `Inertia::render` adı frontend haritasında var mı" testini PHPUnit'e ekle (CI koruması).
- [ ] 125 dosyalık WIP'i mantıksal commit'ler halinde kaydet (iş kaybı riski + gözden geçirilebilirlik).

### 🔧 P1 — Kısa vade (1-2 hafta)

- [ ] `lib/http.ts` + `lib/format.ts` yardımcılarını oluştur; 16+ XSRF kopyasını ve dağınık tarih biçimlerini tek noktaya indir (Y2, F6).
- [ ] Login sahnesini koşullu yükle (`prefers-reduced-motion` + masaüstü) ve boş `catch`'leri görünür yap (Y4, F4).
- [ ] `accountAction` kararını uygula: render et veya 28 dosyadan sil (Y1).
- [ ] Güvenlik sertleştirme: `SESSION_SECURE_COOKIE=true` belgele, `config/hashing.php` ekle (B1), hesap korumasını tek politikada birleştir (B2), telefon arşivini soft-archive'e çevir (B4).
- [ ] CI eklemeleri: ESLint + Prettier, Larastan/PHPStan (seviye 5+), testleri typecheck'e dahil et (F7), bundle boyut bütçesi, ölü CSS kontrolü (purgecss `--dry` veya sınıf-eskiten script).

### 🏗️ P2 — Orta vade

- [ ] Toplu işlemleri tek transaction + toplu `insert/upsert`'e çevir, audit'i tek kayda indir (B6); `SqlExports`'u `cursor()` ile akışa yaz (B7); kismiarama N+1'ini gider (B5).
- [ ] Atık kod turu: ~400 satır ölü CSS (Y3), 19 ölü test fixture'ı (F8), `test:table` → `test:frontend` yeniden adlandırma.
- [ ] `app.tsx:13`'teki `throw` yerine kullanıcı dostu hata sayfası + React Error Boundary; bilinmeyen sayfa adını sunucu loglarına yaz.
- [ ] F1/F2: `PageAccessTable` form sıfırlanmasını düzelt; `JSON.stringify` bağımlılıklarını alan-bazlı kirli bayrağa çevir.
- [ ] `MenuIcon` haritasını modül seviyesine taşı; AppShell + `useLiveList` + Chat polling'ini tek "yenileme orkestrası"nda birleştir (F9).
- [ ] `AdminWorkspace` için generic `DataTable<Row>` ve paylaşılan yönetim tipleri; `as any` yerine genişletilmiş input tipi (F3).

### 💡 P3 — Fikirler (ürün & DX)

- [ ] **Inertia prefetch:** Sık kullanılan sayfalarda `<Link prefetch>` ile algılanan hızı artır.
- [ ] **Erişilebilirlik otomasyonu:** jsdom testlerine `axe-core` ekleyerek mevcut iyi durumu (focus trap, `aria-live`, skip-link) regresyona karşı kilitle.
- [ ] **Gözlemlenebilirlik:** `MeasurePortalRequests` verisini `PortalHealth` ekranında endpoint-bazlı p95 süreleri ve yavaş sorgu listesi olarak göster.
- [ ] **Veri kalitesi kuyruğu genişletmesi:** TC/telefon/geçersiz tarih kurallarını "incele, otomatik düzeltme yok" felsefesiyle ekle.
- [ ] **WIP hijyeni:** `docs/portal-next-yeniden-analiz-20261016/RAPOR.md` ile bu bulguları birleştirip tek "kalan işler" panosuna dönüştür.

---

## 5. Öncelik Tablosu

| Öncelik | İş | Etki |
|---|---|---|
| P0 | K1: `Workspace` harita girdisi + harita testi | Kırık menü/çökme düzelir |
| P0 | WIP'in commit edilmesi | İş kaybı riski kalkar |
| P1 | Y2/Y4: `lib/http.ts`/`lib/format.ts` + Login sahnesi | Bakım & giriş performansı |
| P1 | B1-B4 güvenlik (hashing, çerez, hesap koruması, telefon arşivi) | Veri/güvenlik sertleştirme |
| P2 | B5-B7 toplu işlem & export performansı | Yönetici ekranlarında hız/dayanıklılık |
| P2 | Y1, Y3, F8 atık kod tasfiyesi | Okunabilirlik, bundle/CSS hafifliği |
| P3 | ESLint/PHPStan/bundle bütçesi/a11y otomasyonu | Uzun vadeli kalite güvencesi |

---

## 6. Sonuç

portal-next, güvenlik temelleri ve test disiplini açısından olgun bir kod tabanı; en acil konu K1 (menüde kırık sayfa) ve büyük WIP'in commit edilmesi. Ardından ölü kod/CSS tasfiyesi ve yönetici toplu işlem performansı, uygulamayı bir üst seviyeye taşıyacak en verimli adımlar.

---

### Kayıt Günlüğü

* **18 Ekim 2026:** Backend + frontend derinlemesine analiz (paralel inceleme), kalite kapıları (`typecheck`, `build`, frontend testleri, PHPUnit 424 test) doğrulandı; bu plan dosyası oluşturuldu.


## 7. Uygulama ve yeniden doğrulama — 18 Ekim 2026

Bu bölüm, orijinal analizdeki önerilerin kodla karşılaştırılması ve kullanıcı tarafından seçilen kapsam doğrultusunda uygulanmasının sonucudur. Önceki bölümlerin iddiaları bu sonuçların yerine geçmez.

### Davranış ve güvenlik kararları

- Telefon kayıtları artık fiziksel silinmez; `is_active=false` ile arşivlenir. Yönetim ekranında Aktif kayıtlar / Arşiv ve revision kontrollü Geri al vardır. Normal rehber yalnız aktif kayıtları gösterir. Önceden fiziksel silinen kayıtlar kendiliğinden geri gelmez.
- Yalnız Türker sistem yöneticisi, yeni `users.is_master_admin` alanıyla kalıcı kimliğe bağlanır. Bu alan panel girdisinden atanamaz. Koruma kullanıcı adı değişse de sürer; `admin` ve `turker` takma adları otomatik korunmaz. Son aktif yönetici ve kendi hesabını silmeme kuralları sürer.
- Ana hesabın panelden düzenlenmesi, silinmesi ve parola sıfırlanması engellenir; yetkili oturum sonlandırma ve kişisel parola değiştirme kullanılabilir kalır.
- Yeni migration yalnız yalıtılmış test veritabanlarında çalıştırıldı. Gerçek kurulumda uygulama kodu ve migration birlikte devreye alınmalıdır. Yeni kod eski şemayla kullanılmamalıdır.
- Eski yedeklerde işaret yoksa yalnız yedekteki Türker yönetici hesabından türetilir. Yeni yedekler işareti korur. İlk kurulum, örgüt aktarımı, bağımsız kurtarma, taşınabilir dönüştürücü ve kurtarma şeması güncellendi.
- Başarısız geri yüklemeden sonra eski oturumları yeniden geçerli kılacak bir değişiklik yapılmadı.

### Bulgu bazında sonuç

| Bulgu | Sonuç |
|---|---|
| K1 | Workspace yükleyicisi eklendi. Sabit Inertia sayfa isimleri/gerçek dosyalar PHP testiyle, menüden açılış gerçek uygulama girişinde tarayıcıyla kontrol ediliyor. |
| Y1 | Kullanılmayan accountAction prop’u ve çağrıları kaldırıldı; ortak çıkış butonu korundu. |
| Y2 | JSON ve dosya istekleri ortak portalFetch / XSRF taşımasına geçirildi; writeJson çağrıları uyumlu kaldı. Multipart başlığı tarayıcıya bırakılıyor. |
| Y3 | Kaynakta kullanılmadığı doğrulanan beş sınıf ailesinden 85 CSS kuralı kaldırıldı. Tahmini 400 satırın tamamı topluca silinmedi. |
| Y4 | Genişlik ve azaltılmış hareket kontrolü dinamik import öncesine taşındı; animasyon hatasında form çalışmaya devam eder. |
| B1 | Teşhis geçersiz: Laravel bcrypt varsayılanını yüklüyor ve mevcut politika uzun parolayı reddediyor. Algoritma değiştirilmedi; 72/74 UTF-8 bayt sınırı testi eklendi. |
| B2 | Kalıcı ana hesap işareti ve ortak politika uygulandı; yönetim, aktarım ve kurtarma yolları uyarlandı. |
| B3 | Örnek ortamda HTTP için false açıkça belirtildi; HTTPS için true kullanımı belgelendi. |
| B4 | Telefon arşivleme / geri alma ve yetki, revision, denetim testleri eklendi. |
| B5 | Dinamik seçenek etiketleri toplu sorgulanıyor; telefon gibi diğer listeler announcement_reads sorgulamıyor. Dinamik alan için sorgu sayısı testi eklendi. COUNT sorguları ölçüm olmadan birleştirilmedi. |
| B6 | Nöbet, toplu koğuş ve toplu durum işlemlerinde işlem kapsamlı ortak yetkilendirme / tanım bağlamı ve iç transaction tekrarlarının azaltılması uygulandı. Kayıt başına denetim, kapasite, doluluk ve revision kontrolleri korundu. Başarısızlıkta rollback ve bağlam temizliği sınandı. |
| B7 | SQL dökümü 500 kayıtlık okumalarla özel dosyaya hazırlanıyor; tamamlanınca indirme başlıyor. Hata ve normal gönderim sonunda temizleniyor. Ayrı PHP süreçlerinde 500 / 15.000 kayıt bellek karşılaştırması yapıldı. |
| B8 | Güvenli oturum iptali korundu. Bağımsız kurtarmada tablo listeleme hatası görünür, ayrıntı sızdırmayan mesaja ve tanılama kaydına dönüştürüldü. |
| B9 | İç önizleme kodlaması JSON_THROW_ON_ERROR kullanıyor. |
| B10 | SQL bağlamaları korunuyor; joker arama davranışı ürün kararı olmadan değiştirilmedi. |
| B11 | Beklenmeyen yemek MIME türü kontrollü reddediliyor. Uygulanmış migration’lar yeniden adlandırılmadı; rota kayıt dosyası okuması için gereksiz yeni önbellek eklenmedi. |
| F1 | Seçim değişince form taslağı korunuyor; sayfa/revision listesi yenileniyor ve onay sıfırlanıyor. Güncellenen veya pasifleşen satırlar seçimle eşitleniyor. |
| F2 | Doğru çalışan küçük veri karşılaştırmaları korunuyor; tüm JSON.stringify kullanımları gelişigüzel değiştirilmedi. |
| F3 | Yönetim satırları, sonuçları, alanları ve menüsü için ortak tipler eklendi; uygulama TS/TSX dosyalarında açık any kullanımı kaldırıldı. Dinamik işlem yanıtları daraltılıyor. |
| F4 | Tekrarlanan bildirim yenileme başarısızlığı güncellik mesajı gösteriyor; iptal ve dekoratif animasyon hatası kullanıcıya gereksiz uyarı üretmiyor. |
| F5 | Sabit sıralı başlıkların indeks anahtarları otomatik değiştirilmedi. |
| F6 | Tarih-saat, yalnız tarih ve İstanbul takvim günü için ayrı ortak yardımcılar eklendi. Saat dilimsiz kurum saatleri İstanbul olarak yorumlanır. |
| F7 | Uygulamada unused kontrolleri açıldı; ayrı test TypeScript yapılandırması eklendi. |
| F8 | Belgelenmiş tarayıcı yardımcıları korundu ve eksik Inertia sayfa alanları tamamlandı. test:frontend ana komut; test:table uyumlu yönlendirme. |
| F9 | Gerekli import ve küçük tekrar temizliği yapıldı; farklı veri ihtiyaçlarını karşılayan polling sistemleri ölçümsüz ortak mimariye taşınmadı. |
| F10 | router.visit tam tarayıcı yenilemesi kabul edilmedi; sohbet geçişlerinde davranış değiştiren genel preserveState eklenmedi. |
| Diğer | Gereksiz kontroller, yinelenen atama ve import’lar temizlendi; Inertia yanıt başlıkları doğru ortak yanıt arayüzüyle uygulanıyor. PHPStan bulguları baseline veya toplu ignore ile gizlenmedi. |
| P3 fikirleri | Genel prefetch, yeni p95 toplama, veri kalitesi ürün genişletmesi ve polling mimarisi bu çalışma kapsamına alınmadı. |

### Kalite kapıları

- PHPUnit: **433 test, 5.041 doğrulama başarılı.**
- Ön yüz: **27 tablo/yardımcı + 17 etkileşim = 44 test başarılı.**
- Uygulama ve bütün ön yüz yardımcıları için TypeScript kontrolü başarılı.
- ESLint, Prettier ve Pint kontrolü başarılı. Üçüncü taraf giriş animasyonu kaynakları biçim/lint kapsamı dışında; tarayıcı yardımcıları korunuyor.
- PHPStan/Larastan **seviye 5, sıfır bulgu**. Uygulama kodu için baseline/ignore listesi yok.
- Üretim derlemesi başarılı; ana paket yaklaşık **143,7 KB gzip**, bütçe **160 KB**. Giriş animasyonu ayrı parça olarak kalır.
- Tarayıcı: gerçek giriş ve menü → Çalışma merkezi; telefon arşivle → Arşiv → Geri al; bilinmeyen sayfa geri dönüşü; mobil ve azaltılmış hareket tercihinde animasyon parçasının istenmemesi başarılı.
- CI bu kontrolleri PHP 8.2 / Windows akışına dahil ediyor. Geliştirme bağımlılıkları üretim paketinde mevcut --no-dev kontrolüne tabi.
- Yerel doğrulama PHP 8.4.6 ve Node 22.22.2 üzerinde yapıldı. Windows, hedef PHP 8.2 ve MariaDB kabul akışı yerelde çalıştırılmadı; CI/dağıtım kabulü ayrıca gereklidir.

### Çalıştırma ve devreye alma notu

Geliştirme kontrolleri: `npm run typecheck`, `npm run lint`, `npm run format:check`, `npm run test:frontend`, `npm run build`, `npm run check:bundle`, `npm run test:browser`, `composer analyse`, `composer format:check`, `php vendor/bin/phpunit --do-not-cache-result`.

Tarayıcı testi öncesinde `npx playwright install chromium` gerekir. Test kendi geçici SQLite veritabanını ve depolamasını oluşturur; canlı kurum veritabanını kullanmaz. Test sonunda sunucu ve geçici veriler temizlenir.

Gerçek dağıtımda ön yedek alınmalı, yeni `2026_10_18_000023_add_master_administrator_identity` migration’ı bakım akışında uygulanmalı ve ardından uygulama açılışı doğrulanmalıdır. HTTPS kurulumunda SESSION_SECURE_COOKIE=true kullanılmalı; yalnız HTTP olan yerel kurulumda false korunmalıdır. Bu çalışma canlı migration, commit veya dağıtım yapmamıştır.

## 8. Eksiklerin tamamlanması — 19 Ekim 2026

18 Ekim sonuçları tekrar incelendi; yerelde yapılabilen hedef PHP ve veritabanı kontrolleri tamamlandı. Bu bölüm önceki doğrulama sayılarının ve ortam sınırlamalarının güncel karşılığıdır.

| Ek bulgu / eksik | Yapılan değişiklik ve doğrulama |
|---|---|
| Panelden ana hesap işareti kazanılması | Modelin genel oluşturma kancası kaldırıldı. Panelden Türker adında yönetici oluşturmak veya `is_master_admin=true` göndermek koruma kazandırmaz. İşaret ilk kurulumda açıkça atanır; migration, aktarım ve kurtarma yolları korunur. Panel, ilk kurulum ve mevcut hesapların migration ile işaretlenmesi için test eklendi. |
| MariaDB bağımsız kurtarma | İşaretin boolean normalizasyonundan sonra PDO'nun `false` değerini boş metin olarak bağlaması, sıkı SQL kipinde kurtarmayı bozuyordu. Boolean değerler eklemeden önce 0/1 sayısına çevriliyor. Eski ve yeni şifreli yedekler gerçek MariaDB üzerinden sınandı. |
| Yedekteki kalıcı kimliğin korunması | Gerçek panel yedeği oluşturma ve geri yükleme testleri eklendi. Yeniden adlandırılmış ana hesabın işareti yeni yedekten korunur; eski yedekte yalnız kendi Türker yöneticisinden türetilir. Aynı sayısal kimlikteki mevcut veritabanı işaretleri yedeğe aktarılmaz. |
| Sayfa yükleme ve görüntüleme hataları | React görüntüleme hatasında geri dönüş görünümü test edildi. Tarayıcıda gerçek Workspace dosyası indirmesi engellenerek hata görünümü, ardından Yeniden dene ile başarılı açılış doğrulandı. |
| Test TypeScript kapsamı | Test yapılandırmasında da kullanılmayan değişken ve parametre kontrolleri açıldı. Bütün yardımcılar bu kontrolleri geçiyor. |
| CI kapsamı | Windows akışına, kendi geçici MariaDB sunucusunda öneksiz ve `cte_next_` önekli servis/kurtarma regresyonları eklendi. Yeni kontrollerin süresi için iş zaman sınırı 30 dakikaya çıkarıldı. |

### Güncel doğrulama

- **PHP 8.2.4:** 438 PHPUnit testi, 5.058 doğrulama başarılı. Önceki 433 testin tamamı bu toplamda yer alır.
- **Ön yüz:** 27 tablo/yardımcı ve 18 etkileşim testi; toplam **45 test başarılı**.
- **MariaDB 10.4.28 / PHP 8.2.4:** öneksiz kurulumda **234**, `cte_next_` önekli kurulumda **277** kontrol başarılı. Telefon arşivi ve eski revision reddi; 1.201 kayıtlık SQL dökümünün Türkçe, NULL, kaçışlar ve hesaplanan sütunlarla gerçekten yeniden yüklenmesi; eski/yeni bağımsız kurtarma bu kontroller içindedir.
- Veritabanı sunucusu ağ erişimi kapalı, ayrı geçici veri dizininde çalıştırıldı. Test veritabanları silindi, sunucu durduruldu ve geçici veri dizini kaldırıldı. Gerçek kurum veritabanından veri kopyalanmadı.
- Tarayıcı akışı PHP 8.2 üzerinde başarılı: gerçek giriş ve menü, telefon arşivi/geri alma, bilinmeyen sayfa, başarısız sayfa dosyası indirmesi ve yeniden deneme, mobil/azaltılmış hareket tercihi.
- TypeScript, ESLint, Prettier, PHP 8.2 üzerinde Pint, PHPStan/Larastan seviye 5 ve üretim derlemesi başarılı. PHP 8.2 biçim düzeltmesinden sonra yönetim/dönüştürücü akışlarına ait 34 test ayrıca geçti. Ana paket **143.688 bayt gzip**, sınır **160.000 bayt**.

### Kalan ortam işlemleri

Onaylanan bakım kapsamındaki yerel kodlama ve kontroller tamamlandı. Windows işletim sistemi ve CI'daki MariaDB 10.11 üzerinde gerçek çalıştırma bu macOS ortamında yapılmadı; bunlar Windows CI kabulüne bırakılmıştır. Canlı migration, dağıtım ve commit yapılmadı. Genel polling değişimi, p95 ölçümü ve yeni ürün özellikleri kapsam dışında kalır; orijinal raporun tarihsel onay kutuları ek kodlama talimatı değildir.

## 9. Sonraki talep: ana veritabanı `cte` — 19 Ekim 2026

Kullanıcı yeni sistemin de `cte` veritabanını kullanmasını ve tabloların bunun altında düzenli tutulmasını istedi. Gerçek bağlantı okunarak `.env` dosyasının zaten `cte` / `cte_next_` olarak ayarlandığı, ancak bu veritabanında yeni sisteme ait tabloların henüz kurulmadığı doğrulandı.

- MySQL/MariaDB varsayılanları `cte` ve `cte_next_` olarak düzenlendi. Yeni çevrimdışı kurulum yapılandırıcısı bu düzeni kullanır.
- Mevcut 50 eski tablonun özel SQL yedeği alındı; yedek bütünlüğü, tablo şeması özetleri ve veri sağlama toplamları kaydedildi.
- Kullanıcının yeni kurulum talebi doğrultusunda gerçek `cte` veritabanında **53 yeni `cte_next_` tablosu** oluşturuldu. **30 migration tamamlandı; bekleyen migration yok.**
- Yeni tablolar InnoDB / utf8mb4_unicode_ci kullanır; eski tablolara yabancı anahtar bağlantısı yoktur. `is_master_admin` alanı dahil güncel şema doğrulandı.
- Eski 50 tablonun kurulum öncesi ve sonrası şema/veri doğrulamaları aynı çıktı. Eski kayıtlar yeni tablolara otomatik aktarılmadı; yeni kullanıcı ve kişi tabloları boştur.
- Tablo grupları `docs/veritabani-duzeni.md`, somut kurulum sonucu ve özel yedek konumu `docs/cte-veritabani-kurulum-20261019.json` altında belgelendi. README yeni sistemin kurulum ve veri düzenine göre güncellendi.
- Paylaşılan `cte` üzerinde tüm tabloları silen komutların engellenmesi için ek regresyon testi eklendi. Önceki bakım bölümlerindeki “canlı migration yapılmadı” ifadeleri o aşamanın tarihsel durumudur.
- PHP 8.2 üzerinde **439 test ve 5.061 doğrulama** başarılı; PHPStan seviye 5 ve Pint kontrolleri başarılı.
