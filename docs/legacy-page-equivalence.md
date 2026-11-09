# Eski sayfa işlev eşdeğerliği

Bu envanter eski `htdocs` sayfalarının tasarımını değil, kullanıcıya sunduğu işlevi yeni Laravel sayfasına bağlar.

| Eski sayfa | Temel işlev / butonlar | Yeni karşılık |
|---|---|---|
| `index.php` | Hükümlü listesi, filtre, sıra, ekle, düzenle, bilgi, sil, boş koğuş, izin ve tahliye bilgisi | `/` |
| `duyurular.php` | Duyuru ekle/sil, okundu, tümünü okundu yap | `/operasyon/duyurular` |
| `mudurnobet.php` | Müdür nöbeti, uygun değil, otomatik plan, düzeltme | `/operasyon/nobet?record_type=Müdür nöbeti` |
| `vardiyanobet.php` | Gündüz/gece vardiyası, personel ve çakışma | `/operasyon/nobet?record_type=Vardiya` |
| `infaznobet.php` | İnfaz nöbeti, otomatik plan, ana sayfa göstergesi | `/operasyon/nobet?record_type=İnfaz nöbeti` |
| `kurulkomisyon.php` | Kurul/komisyon, sıra, üyeler, karar | `/operasyon/kurul-komisyon` |
| `yemek.php` | Haftalık/diyet/vejetaryen belge yükleme ve açma | `/operasyon/yemek` |
| `sohbet.php` | Genel/özel mesaj, alıcı ve okundu | `/operasyon/sohbet` |
| `telefon.php` | Arama ve telefon rehberi | `/telefon-rehberi` |
| `ilkgorus.php` | İlk görüş, sosyal/sağlık/risk/DS alanları, konum ve çalışma | `/birim-modulleri/sorumlu-basmemurluk` |
| `revir.php` | Kronik, bulaşıcı, portör, UYAP, tanı/tedavi/sevk | `/birim-modulleri/revir` |
| `infaz.php` | UYAP, ceza, DS/tahliye, kurul, mahkeme, not | `/birim-modulleri/infaz` |
| `izin.php` | İzin türü, gün, çıkış/dönüş, düzenleme | `/birim-modulleri/izin` |
| `izin_rapor.php` | Günlük izin çıkış/dönüş raporu ve yazdırma | `/raporlar/izin` |
| `teblig.php` | Seçili hükümlüler için tebliğ kaydı ve form | `/birim-modulleri/teblig-bakanlik-sevk`, `/raporlar/teblig` |
| `bakanlik_sevk.php` | Bakanlık sevk alanları ve toplu form | `/birim-modulleri/teblig-bakanlik-sevk`, `/raporlar/bakanlik-sevk` |
| `ziyaret.php` | Ziyaretçi, tarih ve not; ekle/güncelle | `/birim-modulleri/ziyaret` |
| `manevi.php` | Statü, görüşme, faaliyet, kurs, belge, ödül | `/birim-modulleri/manevi-rehberlik` |
| `egitim.php` | Eğitim profili, okul/fakülte/bölüm, öğrenci ve hesaplar | `/birim-modulleri/egitim` |
| `kurslar.php` | Kurs, öğretmen, süre, başlangıç/bitiş | `/birim-modulleri/kurs-hijyen-isg?record_type=Kurs` |
| `hijyen.php` | Hijyen ve iş güvenliği durumları | `/birim-modulleri/kurs-hijyen-isg?record_type=Hijyen` |
| `psikososyal.php` | Görüşme, SAMBA, öfke, SÖMGP, filtre | `/birim-modulleri/psikososyal` |
| `mesem.php` | Görüşme, belge, sınıf, dal, SGK, dilekçe, durum | `/birim-modulleri/mesem` |
| `mesemstat.php` | MESEM sayaçları, dağılımlar ve çalışma yeri matrisi | `/araclar/mesem-istatistik` |
| `koguslar.php` | Koğuş kapasite/mevcut/boş ranza | `/sayim` |
| `calisma.php` | Çalışma yeri listesi ve görevlendirme | `/operasyon/kogus-sayim-calisma?record_type=Çalışma özeti` |
| `sayim.php` | Canlı sayım, anlık görüntü, önceki sayımla fark, yazdırma | `/sayim` |
| `kismiarama.php` | Tarih, koğuş araması, yıllık sıra, 3/6/12 ay raporu | `/operasyon/kismi-arama` |
| `degerlendir.php` | Sekiz değerlendirme alanı, TC/ad filtre, güncelle | `/birim-modulleri/degerlendirme` |
| `detay.php` | Hükümlünün bütün yetkili birim geçmişi | `/hukumlu-dosyalari/{id}/duzenle?tab=overview` |
| `kisayol.php` | Bir durumdaki kayıtları topluca yeni duruma geçir | `/hukumlu-kisayollari` |
| `fotograf.php` | Dosyadan/webcam fotoğraf ekle, görüntüle, sil | `/hukumlu-dosyalari/{id}/kimlik/fotograf` |
| `toplu.php` | CSV içe alma, toplu durum/konum/silme | `/hukumlu-dosyalari/toplu` |
| `kimlik.php` | Ara, seç, tümünü seç/temizle, kart ölçüleri, önizleme, yazdırma | `/kimlik-kartlari` |
| `custom_rapor.php` | Yetkili kolon seç, ara, raporla, Excel/PDF/CSV | `/araclar/raporlar` |
| `istatistik.php` | Ana sayaçlar, durum/risk/karar/sağlık/eğitim/demografi dağılımları | `/araclar/istatistik` |
| `infaz_ozet.php` | Hükümlü infaz özet bilgisi | Hükümlü profili ve `/birim-modulleri/infaz` |
| `infaz.sevk.rapor.php` | İl içi/dışı sevk kriterleri, sıra, yazdırma ve Excel | `/raporlar/sevk` |
| `infaz_apc.php` | APC hesabı | `/araclar/infaz-hesaplayici?tool=apc` |
| `infaz_tarih_aralik.php` | İki tarih arasındaki gün hesabı | `/araclar/infaz-hesaplayici?tool=date-interval` |
| `infaz.1.3.php` | Terör 1/3 hesabı | `/araclar/infaz-hesaplayici?tool=terror-one-third` |
| `infaz.para.ceza.php` | Para cezası gün/tarih hesabı | `/araclar/infaz-hesaplayici?tool=fine` |
| `infaz.ondabir.php` | Onda bir hesabı | `/araclar/infaz-hesaplayici?tool=one-tenth` |
| `infaz.ds1.php` | DS 1 yıl hesabı | `/araclar/infaz-hesaplayici?tool=ds-one` |
| `infaz.ds3.php` | DS 3 yıl hesabı | `/araclar/infaz-hesaplayici?tool=ds-three` |
| `infaz.7550sk.php` | 7550 sayılı kanun tarih hesabı | `/araclar/infaz-hesaplayici?tool=law-7550` |

## Ortak davranışlar

- Eski sayfalardaki ayrı oturum kontrolleri merkezi Laravel oturumuna taşındı.
- Görüntüleme izni olmayan URL `403` verir ve menüde görünmez.
- Görüntüleme izni olan kullanıcı sayfayı salt okunur açar; ekleme/düzenleme/silme/dışa aktarma ayrı izinlerdir.
- Eski tek satırlık `tablo` alanları yeni sistemde hükümlü, konum, çalışma ve tarihsel birim kayıtlarına ayrılmıştır.
- Silme işlemi yalnız yetkili, parola ve çift onaylı kalıcı silme akışından yapılır.
- Kaynakta ayrı tablo/veri bulunmayan özellikler üretilmez; manifestte engelli olarak tutulur.
