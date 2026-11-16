<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Exports\LegacyOrganizationAdapter;
use App\Services\Institution\InstitutionRecords;
use App\Services\Workflows\CaseFields;
use Illuminate\Support\Facades\DB;

final class SqlExports
{
    public function download(User $actor, string $kind, ?string $only = null): string
    {
        app(ManagementAccess::class)->actor($actor);
        $tables = app(ManagedDatabase::class)->tables();
        if ($only) {
            abort_unless(in_array($only, $tables), 422);
            $tables = [$only];
        }
        if ($kind === 'legacy-template') {
            $sql = "-- Eski CTE operasyonel şema; veri içermez.\nCREATE TABLE `tablo` (\n";
            $columns = [];
            foreach (config('legacy_core.fields') as $key => $field) {
                $columns[] = '`'.$key.'` '.match ($field['kind']) {
                    'integer' => 'INT', 'date' => 'DATE', 'time' => 'TIME',default => 'TEXT'
                }.' NULL';
            }
            $sql .= implode(",\n", $columns)."\n);\n";
            $defs = ['bloklar' => ['id', 'kod', 'ad', 'aktif', 'sira'], 'koguslar' => ['id', 'blok_id', 'kod', 'ad', 'ranza_sayisi', 'kapasite', 'tur', 'aktif', 'sira'], 'telefon_rehberi' => ['id', 'isim', 'telefon', 'sira'], 'vardiya_nobet' => ['id', 'basmemur_adi', 'gunduz_tarih', 'gece_tarih', 'izin1_tarih', 'izin2_tarih'], 'mudur_nobet' => ['id', 'kullanici_id', 'mudur_adi', 'personel_adi_snapshot', 'nobet_tarihi', 'nobet_gunu', 'vekil_nobetci'], 'infaz_nobet' => ['id', 'kullanici_id', 'personel_adi', 'personel_adi_snapshot', 'nobet_tarihi', 'nobet_gunu'], 'calisma_yerleri' => ['id', 'ad', 'aktif', 'sira'], 'secenek_listeleri' => ['id', 'liste_kodu', 'deger', 'sira', 'aktif'], 'kurul_komisyon_basliklari' => ['id', 'kod', 'baslik', 'ust_kolon', 'alt_kolon', 'sira', 'aktif'], 'infaz_nobet_musait_olmayan' => ['id', 'personel_adi', 'tarih', 'aciklama'], 'birimler' => LegacyOrganizationAdapter::COLUMNS['birimler'], 'unvanlar' => LegacyOrganizationAdapter::COLUMNS['unvanlar'], 'kullanicilar' => LegacyOrganizationAdapter::COLUMNS['kullanicilar'], 'kurul_komisyon' => ['id', 'sira_no', ...array_merge(...array_map(fn ($code) => [$code.'_ust', $code.'_alt'], ['disiplin_kurulu', 'idare_gozlem_kurulu', 'egitim_kurulu', 'isyurdu_yonetim_kurulu', 'arsiv_ayiklama_komisyonu', 'mektup_okuma_komisyonu', 'koli_acma_komisyonu', 'kitap_sayim_komisyonu', 'muayene_kabul_genel', 'muayene_kabul_isyurdu']))], 'duyuru' => ['id', 'baslik', 'icerik'], 'kismi_arama' => ['id', 'blok', 'kogus', 'arama_tarihi', 'kullanici', 'kayit_tarihi']];
            foreach ($defs as $name => $keys) {
                $sql .= 'CREATE TABLE `'.$name.'` ('.implode(',', array_map(fn ($k) => '`'.$k.'` TEXT NULL', $keys)).");\n";
            }

            return $sql;
        }
        $schema = "-- CTE güncel şema\n";
        foreach ($tables as $table) {
            $physical = DB::connection()->getTablePrefix().$table;
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
                $r = (array) DB::selectOne('SHOW CREATE TABLE `'.$physical.'`');
                $schema .= array_values($r)[1].";\n";
            } else {
                $r = DB::selectOne('SELECT sql FROM sqlite_master WHERE type=? AND name=?', ['table', $physical]);
                $schema .= ($r->sql ?? '').";\n";
            }
        }
        if ($kind === 'schema') {
            return $schema;
        }
        abort(422, 'Veri dökümü dosya olarak hazırlanmalıdır.');
    }

    /** Materialize before sending headers so errors never become successful partial downloads. */
    public function dataFile(User $actor, ?string $only = null): string
    {
        app(ManagementAccess::class)->actor($actor);
        $tables = app(ManagedDatabase::class)->tables();
        if ($only !== null) {
            abort_unless(in_array($only, $tables, true), 422);
            $tables = [$only];
        }
        $directory = storage_path('app/private/operations/exports');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Döküm alanı oluşturulamadı.');
        }
        $path = tempnam($directory, 'sql-');
        if ($path === false) {
            throw new \RuntimeException('Döküm dosyası hazırlanamadı.');
        }
        $handle = null;
        try {
            if (! chmod($path, 0600) || ($handle = fopen($path, 'wb')) === false) {
                throw new \RuntimeException('Döküm dosyası açılamadı.');
            }
            $write = static function (string $text) use ($handle): void {
                $offset = 0;
                while ($offset < strlen($text)) {
                    $written = fwrite($handle, substr($text, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Döküm yazılamadı.');
                    }
                    $offset += $written;
                }
            };
            $connection = DB::connection();
            if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            DB::transaction(function () use ($actor, $only, $tables, $write, $connection) {
                app(ManagementAccess::class)->actor($actor);
                $write($this->download($actor, 'schema', $only));
                $write("-- CTE yeni sistem veri aktarımı\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n");
                foreach ($tables as $table) {
                    if (in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches'], true)) {
                        continue;
                    }
                    $builder = $connection->getSchemaBuilder();
                    $columns = array_column(array_filter($builder->getColumns($table), fn ($column) => $column['generation'] === null), 'name');
                    $indexes = $builder->getIndexes($table);
                    $primary = array_values(array_filter($indexes, fn ($index) => $index['primary']));
                    $order = $primary[0]['columns'] ?? $columns;
                    $query = DB::table($table)->select($columns);
                    foreach ($order as $column) {
                        $query->orderBy($column);
                    }
                    // Bounded queries avoid PDO MySQL buffering an entire table for cursor().
                    $query->chunk(500, function ($rows) use ($table, $write) {
                        foreach ($rows as $row) {
                            $write(app(SqlDump::class)->insertStatement($table, (array) $row));
                        }
                    });
                }
                $write("COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
            });
            if (! fflush($handle)) {
                throw new \RuntimeException('Döküm tamamlanamadı.');
            }
            fclose($handle);

            return $path;
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
    }

    public function recoverySchema(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);
        $manifest = ['version' => 1, 'tables' => [], 'seed' => [], 'generated_at' => now()->toIso8601String()];
        $prefix = DB::connection()->getTablePrefix();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
            return ['available' => false];
        }
        foreach (app(ManagedDatabase::class)->tables() as $table) {
            $manifest['tables'][$table] = array_values((array) DB::selectOne('SHOW CREATE TABLE `'.$prefix.$table.'`'))[1];
        }
        foreach ($manifest['tables'] as &$ddl) {
            foreach (array_keys($manifest['tables']) as $table) {
                $ddl = str_replace('`'.$prefix.$table.'`', '`__PREFIX__'.$table.'`', $ddl);
            }
        }unset($ddl);
        foreach (['migrations', 'person_statuses', 'case_option_lists', 'case_option_items', 'portal_pages'] as $name) {
            $q = DB::table($name);
            if ($name === 'portal_pages') {
                $q->whereNull('module_id');
            }$manifest['seed'][$name] = $q->get()->map(fn ($r) => (array) $r)->all();
        }
        $manifest['seed']['portal_installations'] = [['id' => 1, 'installed_at' => now()->toDateTimeString()]];
        $file = resource_path('recovery/native-schema.json');
        $tmp = tempnam(dirname($file), '.schema-');
        file_put_contents($tmp, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $file);

        return ['available' => true, 'tables' => count($manifest['tables'])];
    }

    public function update(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);
        $this->recoverySchema($actor);
        $codes = ['disiplin_kurulu' => 'discipline', 'idare_gozlem_kurulu' => 'administration', 'egitim_kurulu' => 'education', 'isyurdu_yonetim_kurulu' => 'workshop', 'arsiv_ayiklama_komisyonu' => 'archive', 'mektup_okuma_komisyonu' => 'letters', 'koli_acma_komisyonu' => 'packages', 'kitap_sayim_komisyonu' => 'books', 'muayene_kabul_genel' => 'general_acceptance', 'muayene_kabul_isyurdu' => 'workshop_acceptance'];
        $columns = [];
        foreach ($codes as $old => $new) {
            $columns[$old.'_ust'] = $new.'_top';
            $columns[$old.'_alt'] = $new.'_bottom';
        }
        $payload = ['version' => 3, 'generated_at' => now()->toIso8601String(), 'prefix' => DB::connection()->getTablePrefix(), 'fields' => app(CaseFields::class)->all(), 'boards' => app(BoardDefinitions::class)->read()['values']['items'], 'board_codes' => $codes, 'board_columns' => $columns, 'institution_tables' => [], 'seed' => []];
        foreach (array_keys(app(FieldDefinitions::class)->institutionDefaults()) as $kind) {
            $payload['institution_tables'][$kind] = app(InstitutionRecords::class)->table($kind);
        }
        foreach (['migrations', 'person_statuses', 'case_option_lists', 'case_option_items', 'portal_pages'] as $name) {
            $q = DB::table($name);
            if ($name === 'portal_pages') {
                $q->whereNull('module_id');
            }$payload['seed'][$name] = $q->get()->map(fn ($r) => (array) $r)->all();
        }
        $payload['seed']['portal_installations'] = [['id' => 1, 'installed_at' => now()->toDateTimeString()]];
        $payload['seed']['administration_settings'] = DB::table('administration_settings')->where('key', 'workflow_fields')->get()->map(function ($r) {
            $r->updated_by = null;

            return (array) $r;
        })->all();
        $root = app(ManagedFiles::class)->root();
        $name = 'donusturucu-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($root.'/'.$name, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Dönüştürücü arşivi yazılamadı.');
        }
        try {
            $zip->addFile(resource_path('recovery/portable-converter.php'), 'donusturucu.php');
            $zip->addFile(resource_path('recovery/sql-reader.php'), 'sql-reader.php');
            $parser = preg_replace("~require_once\\s+dirname\\(__DIR__,\\s*3\\)\\s*\\.\\s*'/resources/recovery/sql-reader.php'~", "require_once __DIR__.'/sql-reader.php'", file_get_contents(app_path('Services/Administration/SqlDump.php')));
            $zip->addFromString('SqlDump.php', $parser);
            $zip->addFromString('schema.json', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('schema.sql', $this->download($actor, 'schema'));
            $zip->addFromString('README.txt', "PHP 8.2+ / JSON / OpenSSL. Laravel ve veritabanı bağlantısı gerekmez.\nphp donusturucu.php eski.sql yeni.sql\nÇıktı boş yeni uygulama veritabanı için şema ve veridir; mevcut verilerin üzerine çalıştırmayın. Mevcut kurulumda panelin önizlemeli aktarımını kullanın.\nHassas alanlar varsa hedef kurulumun APP_KEY değerini CTE_APP_KEY ortam değişkenine güvenli biçimde sağlayın; anahtar arşivde saklanmaz.\nEşlenmeyen dolu tablolar sessizce atılmaz; dönüşüm durur. Fotoğraf ve belge dosyalarını ayrıca taşıyın.\n");
        } finally {
            $zip->close();
        }chmod($root.'/'.$name, 0600);
        app(AuditLog::class)->record($actor, 'administration_conversion', 0, 'updated', ['version' => 3]);

        return ['file' => $name, 'download' => url('/yonetim/dosya-indir?path='.rawurlencode($name)), 'schema_tables' => count(app(ManagedDatabase::class)->tables())];
    }
}
