# CTE Portal çalışma kuralları

- Bu çalışma klasörü yerel uygulamanın tek kaynak noktasıdır. İnternet deneme reposu `turkerkagan/Cte-test` ile uygulama kodları, migration dosyaları ve testler aynı kalmalıdır.
- Değişiklikleri önce bu klasörde yapın. Doğrulama sonrası `scripts/sync-test-repo.py` ile Cte-test checkout'una aktarın, `--check` ile eşitliği doğrulayın ve test reposuna commit/push yapın. Deneme sunucusu bağlıysa aynı commit'in yayına alındığını doğrulayın. Bağlantı yoksa bunu açıkça bildirin.
- `.env`, bağlantı şifreleri, SQL dökümleri, yedekler, loglar ve kullanıcı dosyaları GitHub'a eklenmez. Veritabanı şeması migration'larla eşitlenir. Kayıt değerleri kaynak kod yayınıyla eşitlenmiş sayılmaz.
- Kullanıcı veritabanı eşitliği de istemektedir. Yetkili veri kopyası ayrı özel aktarım ve bütünlük doğrulaması gerektirir. Mevcut kayıtları yedeksiz değiştirmeyin; uzaktaki test kayıtlarını sessizce yereldeki kayıtlarla ezmeyin. Sürekli çift yönlü kayıt eşitliği kurulmuş değildir.
- Ortama özel ayarlar `.env` üzerinden yönetilir; yerel ve uzak için uygulama kodunu çatallamayın.
