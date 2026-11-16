# Portal Next veritabanı düzeni

Portal Next’in ana veritabanı **`cte`**’dir. Yeni sistemin 53 tablosu aynı veritabanında **`cte_next_`** önekiyle tek grup oluşturur. Önek veritabanı adı değildir: `cte_next_users` tablosunun tam adresi `cte.cte_next_users` olur.

Eski uygulamanın öneksiz tabloları korunur. Yeni uygulama bu tabloların üzerine yazmaz; eski ve yeni sistem aynı veritabanında kendi tablo kümelerini kullanır. Eski kayıtların yeni veri modeline aktarılması ayrı, doğrulamalı aktarım akışıdır; tablo kurulumu otomatik veri aktarımı yapmaz.

## Bağlantı

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=cte
DB_TABLE_PREFIX=cte_next_
```

Sunucu, port, kullanıcı ve parola kurulum ortamına göre `.env` içinde tanımlanır. Yerel XAMPP kurulumunda mevcut socket ayarı korunur. MySQL ve MariaDB bağlantılarında varsayılan veritabanı `cte`, varsayılan tablo öneki `cte_next_` olarak ayarlanmıştır. Yeni çevrimdışı kurulum sihirbazı da bu düzeni kullanır.

## Tablo grupları

Aşağıda adlar öneksiz gösterilmiştir; veritabanında hepsi `cte_next_` ile başlar.

| Grup | Tablolar |
|---|---|
| Hesaplar ve kurum yapısı | `users`, `departments`, `job_titles`, `staff_nodes`, `account_requests`, `portal_login_events` |
| Sayfalar, yetkiler ve menü | `portal_pages`, `page_permissions`, `menu_configuration`, `menu_items` |
| Kişiler ve iş akışları | `people`, `person_case_data`, `person_notes`, `person_photos`, `person_locations`, `person_statuses`, `person_status_assignments` |
| Barınma ve yerleşim | `housing_blocks`, `housing_rooms`, `housing_beds`, `housing_occupancies` |
| Dinamik modüller ve seçenekler | `modules`, `module_versions`, `module_fields`, `module_records`, `module_record_index`, `module_record_options`, `option_lists`, `option_items`, `case_option_lists`, `case_option_items` |
| Kurum işlemleri | `institution_records`, `announcement_reads`, `duty_unavailable`, `meal_files` |
| Mesajlaşma | `personal_messages`, `message_attachments`, `chat_rooms`, `chat_members`, `chat_messages` |
| Yönetim ve izlenebilirlik | `portal_installations`, `import_mappings`, `audit_events`, `administration_settings`, `administration_backups`, `private_file_deletions` |
| Sistem altyapısı | `migrations`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |

## Yapısal kurallar

- Tablolar InnoDB kullanır; yeni tablolar için karakter kümesi `utf8mb4`, karşılaştırma `utf8mb4_unicode_ci`’dir.
- İlişkiler sayısal kimlikler ve yabancı anahtarlarla kurulur. Kullanıcı adı ve etiketler ilişki anahtarı olarak kullanılmaz.
- Kişi, modül, kurum ve yönetim kayıtları mevcut revision/transaction kurallarını kullanır; denetim olayları `cte_next_audit_events` altında tutulur.
- Telefon arşivi aktiflik alanını, kalıcı ana hesap koruması `cte_next_users.is_master_admin` alanını kullanır.
- Şema değişiklikleri migration dosyalarıyla sürümlenir. `cte_next_migrations` yalnız yeni sistemin şema geçmişidir.
- `migrate:fresh` ve `db:wipe`, paylaşılan `cte` üzerinde engellenir. Kurulum veya güncelleme için yedek alındıktan sonra normal `php artisan migrate --force` kullanılır.
- Eski ve yeni sistemin oturumları, tabloları ve erişim politikaları ayrıdır. Eski kullanıcılar kendiliğinden yeni sisteme kopyalanmaz; ilk yönetici kurulumu veya mevcut aktarım akışı kullanılmalıdır.

## Bu kurulumun doğrulaması — 19 Ekim 2026

Mevcut `cte` veritabanında eski uygulamaya ait 50 tablo bulundu. Yeni tablo kurulumundan önce erişimi kısıtlı SQL yedeği alındı; yedek dosyasının SHA-256 özeti ve eski tabloların şema/veri doğrulama izleri özel yedek manifestine kaydedildi.

Kurulum sonucu ve yedek konumu aynı klasördeki `cte-veritabani-kurulum-20261019.json` kaydında yer alır. Bu işlem önceki bakım çalışmasındaki “canlı migration yapılmadı” durumundan sonra, kullanıcının `cte` altında tablo kurulması talebi doğrultusunda yapılmıştır. Eski verilerin aktarımı, eski tablo silme, sunucu sistem tablolarını güncelleme ve dağıtım bu işlemin parçası değildir.
