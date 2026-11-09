# Eski veri alan ve tablo eşleme raporu

Kaynak: `storage/app/private/legacy/fihrist-tablo.json`  
Doğrulanan kaynak: 845 satır, 125 kolon.

Ek doğrulanan kaynak: `fihrist.sql` (5 Kasım 2025), 13 tablo ve 1.238 satır.

## 13 SQL tablosunun hedefleri

| Kaynak | Satır | Yeni hedef |
|---|---:|---|
| `tablo` | 642 | `inmate_files`, konum/çalışma atamaları ve birim kayıtları |
| `users` | 10 | `users`, kurum rolleri ve eski bcrypt parola hashleri |
| `duyuru` | 6 | `operation_records / duyurular` |
| `duyuru_okundu` | 10 | duyuru okuma ilişkisi ve ayrı okunma olayları |
| `infaz_nobet` | 60 | `operation_records / nobet` |
| `mudur_nobet` | 77 | `operation_records / nobet` |
| `vardiya_nobet` | 365 | `operation_records / nobet` |
| `kismi_arama` | 23 | `operation_records / kismi-arama` |
| `kullanici_durum` | 5 | kullanıcı son aktivite alanı |
| `kurul_komisyon` | 12 | `operation_records / kurul-komisyon` |
| `sohbet` | 3 | `operation_records / sohbet` |
| `mesaj_okunma` | 11 | sohbet okunma bilgisi ve ayrı okunma olayları |
| `yemek_listesi` | 14 | `operation_records / yemek` |

## Hedef grupları

- Hükümlü ana dosyası: `id`, `huk_tc`, `huk_adi`, kayıt/giriş, durum, risk, karar, sağlık ve not alanları.
- Aktif konum: `huk_blok`, `huk_kogus`, `huk_ranza`.
- Aktif çalışma: `huk_calis`, `huk_karar`.
- Sorumlu başmemurluk: `huk_yas`–`huk_ds_durum` sosyal, sağlık ve risk alanları.
- Değerlendirme: sekiz `huk_*_uyum/duzen/tutum` alanı.
- Revir: portör, kronik, bulaşıcı, UYAP, çalışma ve ceza tehir alanları.
- İnfaz: bütün `infaz_*` alanları; doğum/yaş, cinsel suç yazısı, ceza, kurul, DS/tahliye ve not dahil.
- Eğitim: bütün `egt_*` alanları. Adında “şifre” geçen değerler uygulama anahtarıyla şifreli saklanır.
- Kurs/Hijyen/İSG: `kurs_*` ve `hijyen_*`.
- Manevi rehberlik: bütün `manevi_*`.
- MESEM: bütün `mesem_*`; eski `mesem_nezuniyet` yazım hatası `mesem_mezuniyet` ile birlikte okunur.
- İzin: bütün `izin_*`; çıkış ve dönüş tarihleri tarihsel kayıt sınırlarıdır.
- Ziyaret: üç eski ziyaretçi/tarih/not grubu sınırsız tarihsel ziyaret kayıtlarına ayrılır.

## Kontrollü kullanım dışı alan

- `huk_fotograf`: JSON içeriğinde taşınabilir dosya yolu veya dosya verisi bulunmadığı için metadata olarak aktarılmaz. Gerçek eski fotoğraf klasörü sağlandığında hash kontrollü private dosya aktarımı yapılmalıdır.

## Gerçek aktarım sonucu — 24 Haziran 2026

- Hükümlü: 845
- Birim kaydı: 4039
- Eğitim: 53
- İnfaz: 798
- İzin: 76
- Kurs/Hijyen/İSG: 248
- Manevi rehberlik: 667
- MESEM: 7
- Revir: 844
- Sorumlu başmemurluk: 845
- Ziyaret: 501
- Yetim birim kaydı: 0
- Şifreli eğitim hesabı içeren kayıt: 4

`fihrist.sql` aktarımı öncesinde ve sonrasında SHA-256 doğrulamalı yedek alındı. Güncel 845 hükümlü korunarak SQL kaynağındaki 642 kişi legacy ID, çözülmüş TC ve normalize adla eşleştirildi; 184 mevcut eşleşme korundu, 458 eksik kişi eklendi ve toplam 1.303 hükümlüye ulaşıldı. İkinci tam çalıştırmada yeni hükümlü oluşmadı. Diğer 12 tablonun tüm 596 satırı hedef modüllerde karşılık buldu; okunma tekrarları ayrı olay kayıtlarıyla kayıpsız korundu. Yetim birim ve operasyon-kullanıcı ilişkisi `0` olarak doğrulandı. Son yedek doğrulandıktan sonra 13 geçici `legacy_src_*` tablosu canlı veritabanından kaldırıldı.

Kaynakta tüm değerlendirme alanları boş olduğundan değerlendirme kaydı üretilmedi. Kaynak JSON’da psikososyal SAMBA/öfke/SÖMGP kolonları bulunmadığından bu veriler üretilemez.
