<?php

namespace App\Services\Administration;

use App\Services\Security\MasterAdministrator;
use App\Services\Security\SessionGeneration;
use Dotenv\Dotenv;
use Illuminate\Encryption\Encrypter;
use PDO;
use RuntimeException;

/** No framework boot, routes, migrations or working application database are required. */
final class StandaloneRecovery
{
    public function __construct(public readonly string $base, public readonly array $env) {}

    public function privateRoot(): string
    {
        $root = $this->base.'/storage/app/private/administration/emergency';
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        return $root;
    }

    public function database(): string
    {
        return $this->env['DB_DATABASE'] ?? '';
    }

    public function prefix(): string
    {
        return $this->env['DB_TABLE_PREFIX'] ?? '';
    }

    public function guard(string $database): void
    {
        if ($database !== 'cte' || $this->database() !== 'cte' || $this->prefix() !== '') {
            throw new RuntimeException('Kurtarma yalnız öneksiz cte veritabanında kullanılabilir.');
        }
    }

    public function connect(?string $database = null, bool $server = false): PDO
    {
        $database ??= $this->database();
        $this->guard($database);
        $socket = $this->env['DB_SOCKET'] ?? '';
        $dsn = 'mysql:'.($socket !== '' ? 'unix_socket='.$socket : 'host='.($this->env['DB_HOST'] ?? '127.0.0.1').';port='.($this->env['DB_PORT'] ?? 3306));
        if (! $server) {
            $dsn .= ';dbname='.$database;
        }$dsn .= ';charset=utf8mb4';

        return new PDO($dsn, $this->env['DB_USERNAME'] ?? '', $this->env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    public function authenticate(string $token, string $username = '', string $password = ''): bool
    {
        $file = $this->privateRoot().'/access.json';
        $lock = fopen($this->privateRoot().'/.auth-lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if ($token !== '' && is_file($file)) {
                $data = json_decode(file_get_contents($file), true);
                if (($data['expires'] ?? 0) > time() && hash_equals($data['hash'] ?? '', hash('sha256', $token))) {
                    unlink($file);

                    return true;
                }
            }
            if ($username !== '' && $password !== '') {
                $pdo = $this->connect();
                $q = $pdo->prepare('SELECT password,is_active,is_system_admin,must_change_password,locked_at FROM `'.$this->prefix().'users` WHERE username=?');
                $q->execute([$username]);
                $u = $q->fetch();
                $locked = ! empty($u['locked_at']) && strtotime((string) $u['locked_at']) > time() - 15 * 60;

                return $u && ! $locked && (bool) $u['is_active'] && (bool) $u['is_system_admin'] && ! (bool) $u['must_change_password'] && password_verify($password, $u['password']);
            }

            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function tables(PDO $pdo): array
    {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter($rows, fn ($t) => preg_match('/^[a-z0-9_]+$/D', $t) && str_starts_with($t, $this->prefix())));
    }

    public function backup(PDO $pdo, ?string $only = null): string
    {
        $tables = $this->tables($pdo);
        if ($only) {
            if (! in_array($only, $tables)) {
                throw new RuntimeException('Tablo bulunamadı.');
            }$tables = [$only];
        }
        $sql = "SET FOREIGN_KEY_CHECKS=0;\n";
        $bytes = 0;
        foreach ($tables as $table) {
            $schema = array_values($pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch());
            $sql .= 'DROP TABLE IF EXISTS `'.$table."`;\n".$schema[1].";\n";
            $q = $pdo->query('SELECT * FROM `'.$table.'`');
            while ($r = $q->fetch()) {
                $sql .= 'INSERT INTO `'.$table.'` ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($r))).') VALUES ('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)).");\n";
                if (strlen($sql) > 64 * 1024 * 1024) {
                    throw new RuntimeException('Kurtarma yedeği 64 MB sınırını aşıyor.');
                }
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $path = $this->privateRoot().'/emergency-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        file_put_contents($path, $sql, LOCK_EX);
        chmod($path, 0600);

        return basename($path);
    }

    public function sql(PDO $pdo, string $sql, bool $write, string $confirmation): array
    {
        $reader = new SqlDump;
        $statements = $reader->statements($sql);
        if (count($statements) !== 1) {
            throw new RuntimeException('Tek SQL komutu girin.');
        }$sql = $statements[0];
        if (! preg_match('/^(?:SELECT|EXPLAIN|INSERT|UPDATE|DELETE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+(?:TABLE\s+)?)/i', $sql)) {
            throw new RuntimeException('Bu SQL komutu desteklenmez.');
        }
        $outside = cte_sql_dis_metin($sql);
        if (preg_match('/\b(?:GET_LOCK|RELEASE_LOCK|MYSQL|SYS|ATTACH|DETACH|PRAGMA|DATABASE|GRANT|REVOKE|FEDERATED|CONNECTION|DIRECTORY|TABLESPACE|OUTFILE|DUMPFILE|INFILE|LOAD_FILE|SLEEP|BENCHMARK|PREPARE|EXECUTE|PROCEDURE|TRIGGER|FUNCTION|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA)\b|@@|@\w|\/\*|--|#/i', $outside)) {
            throw new RuntimeException('SQL kurulum dışına erişemez.');
        }
        preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE|REFERENCES|LIKE|RENAME\s+TO)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(`?[a-zA-Z0-9_]+`?)(\s*\.)?/i', $sql, $names, PREG_SET_ORDER);
        foreach ($names as $name) {
            if (! empty($name[2]) || ! str_starts_with(trim($name[1], '`'), $this->prefix())) {
                throw new RuntimeException('Tablo yolu bu kurulumun dışında.');
            }
        }
        if (preg_match('/\bUNION\b|\bFROM\b(?:(?!\b(?:WHERE|GROUP|ORDER|LIMIT|HAVING|ON)\b)[^;])*,|^UPDATE\b(?:(?!\bSET\b)[^;])*,|^DROP\s+TABLE\b[^;]+,/i', $outside)) {
            throw new RuntimeException('Önekli kurulumda tek tablo veya açık JOIN kullanın.');
        }
        $read = preg_match('/^(?:SELECT|EXPLAIN)\b/i', $sql);
        if (! $read) {
            if (! $write || $confirmation !== 'SQL ÇALIŞTIR') {
                throw new RuntimeException('SQL ÇALIŞTIR yazarak onaylayın.');
            }$backup = $this->backup($pdo);
            $count = $pdo->exec($sql);

            return ['backup' => $backup, 'affected' => $count];
        }
        $q = $pdo->query($sql);
        $rows = [];
        while (count($rows) < 200 && ($row = $q->fetch()) !== false) {
            $rows[] = $row;
        }

        return ['rows' => $rows];
    }

    public function databaseAction(string $database, string $action, string $confirmation): array
    {
        $this->guard($database);
        if ($database === 'cte') {
            throw new RuntimeException('Paylaşılan cte veritabanı oluşturulamaz veya silinemez.');
        }
        $pdo = $this->connect($database, true);
        $backup = null;
        if ($action === 'drop') {
            if ($confirmation !== 'VERİTABANINI SİL') {
                throw new RuntimeException('VERİTABANINI SİL onayı gerekli.');
            }$backup = $this->backup($this->connect($database));
            $pdo->exec('DROP DATABASE `'.$database.'`');
        } elseif ($action === 'create') {
            $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } else {
            throw new RuntimeException('İşlem geçersiz.');
        }

        return ['database' => $database, 'action' => $action, 'backup' => $backup];
    }

    public function migrations(bool $fresh, string $confirmation): array
    {
        $this->guard($this->database());
        if ($fresh && $confirmation !== 'TEMİZ KURULUM') {
            throw new RuntimeException('TEMİZ KURULUM onayı gerekli.');
        }
        $backup = null;
        try {
            $backup = $this->backup($this->connect());
        } catch (\PDOException $e) {
            if (! str_contains($e->getMessage(), 'Unknown database')) {
                throw $e;
            }
        }
        $configured = Dotenv::createArrayBacked($this->base)->safeLoad();
        if (($configured['DB_DATABASE'] ?? '') !== $this->database()) {
            $this->nativeSchema($fresh);

            return ['backup' => $backup, 'schema' => 'Tamamlandı'];
        }
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->base.'/artisan').' '.($fresh ? 'migrate:fresh' : 'migrate').' --force';
        $out = [];
        $status = 0;
        exec($command.' 2>&1', $out, $status);
        if ($status !== 0) {
            $this->nativeSchema($fresh);
        }

        return ['backup' => $backup, 'schema' => 'Tamamlandı'];
    }

    public function nativeSchema(bool $fresh): void
    {
        $file = $this->base.'/resources/recovery/native-schema.json';
        if (! is_file($file)) {
            throw new RuntimeException('Bağımsız şema kopyası bulunamadı; SQL şemasını yükleyin. Ön yedek korundu.');
        }
        $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $pdo = $this->connect();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($fresh) {
                foreach ($this->tables($pdo) as $table) {
                    $pdo->exec('DROP TABLE `'.$table.'`');
                }
            }
            foreach ($manifest['tables'] as $name => $ddl) {
                if (! preg_match('/^[a-z0-9_]+$/D', $name)) {
                    throw new RuntimeException('Şema tablo adı geçersiz.');
                }$physical = $this->prefix().$name;
                $ddl = str_replace('__PREFIX__', $this->prefix(), $ddl);
                if (! in_array($physical, $this->tables($pdo))) {
                    $pdo->exec($ddl);
                } else {
                    $columns = $pdo->query('SHOW COLUMNS FROM `'.$physical.'`')->fetchAll(PDO::FETCH_COLUMN);
                    $start = strpos($ddl, '(');
                    $end = strrpos($ddl, ')');
                    foreach ((new SqlDump)->split(substr($ddl, $start + 1, $end - $start - 1)) as $def) {
                        if (preg_match('/^`([a-z0-9_]+)`\s+/', $def, $m) && ! in_array($m[1], $columns)) {
                            $pdo->exec('ALTER TABLE `'.$physical.'` ADD COLUMN '.$def);
                        }
                    }
                }
            }
            foreach ($manifest['seed'] as $name => $rows) {
                if (! isset($manifest['tables'][$name])) {
                    throw new RuntimeException('Şema başlangıç tablosu geçersiz.');
                }foreach ($rows as $row) {
                    $q = $pdo->prepare('INSERT IGNORE INTO `'.$this->prefix().$name.'` ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                    $q->execute(array_values($row));
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function path(string $relative, bool $missing = false): string
    {
        if (! preg_match('~^[\pL\pN _()/.-]*$~uD', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $relative)) {
            throw new RuntimeException('Dosya yolu geçersiz.');
        }
        if ($relative !== '' && ! in_array(explode('/', $relative)[0], ['app', 'resources', 'routes', 'config', 'database', 'public', 'bootstrap'])) {
            throw new RuntimeException('Bu uygulama alanına erişilemez.');
        }
        if (in_array($relative, ['public/index.php', 'public/recovery.php']) || str_starts_with($relative, 'bootstrap/cache')) {
            throw new RuntimeException('Başlangıç dosyası korunur.');
        }
        $path = $this->base;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Sembolik bağlantı kullanılamaz.');
            }
        }
        if (! $missing && ! file_exists($path)) {
            throw new RuntimeException('Dosya bulunamadı.');
        }

        return $path;
    }

    public function save(string $relative, string $text, string $checksum): array
    {
        $path = $this->path($relative);
        if (! is_file($path) || filesize($path) > 2 * 1024 * 1024 || ! hash_equals(hash_file('sha256', $path), $checksum)) {
            throw new RuntimeException('Dosya değişmiş veya düzenleme sınırını aşıyor.');
        }
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'ts', 'tsx', 'jsx', 'css', 'html', 'json', 'xml', 'md', 'txt', 'sql'])) {
            throw new RuntimeException('Bu dosya metin olarak düzenlenemez.');
        }
        $backup = $this->privateRoot().'/source-'.bin2hex(random_bytes(8)).'.bak';
        copy($path, $backup);
        chmod($backup, 0600);
        $tmp = tempnam(dirname($path), '.recovery-');
        file_put_contents($tmp, $text, LOCK_EX);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $out = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $out, $status);
            if ($status !== 0) {
                unlink($tmp);
                throw new RuntimeException('PHP sözdizimi hatalı; dosya korundu.');
            }
        }
        chmod($tmp, fileperms($path) & 0777);
        rename($tmp, $path);

        return ['file' => $relative, 'backup' => basename($backup)];
    }

    public function fileAction(string $action, array $input, ?array $upload = null): array
    {
        $relative = (string) ($input['path'] ?? '');
        $path = $this->path($relative, in_array($action, ['folder', 'upload', 'restore-file']));
        if (in_array($action, ['trash', 'move']) && in_array($relative, ['public', 'bootstrap'])) {
            throw new RuntimeException('Başlangıç dosyalarını içeren klasör korunur.');
        }if ($relative === '') {
            throw new RuntimeException('Dosya veya klasör yolunu girin.');
        }
        if ($action === 'folder') {
            if (file_exists($path) || ! is_dir(dirname($path))) {
                throw new RuntimeException('Boş bir klasör yolu ve mevcut üst klasör seçin.');
            }mkdir($path, 0700);
        } elseif ($action === 'upload') {
            if (! $upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 20 * 1024 * 1024 || ! is_uploaded_file($upload['tmp_name']) || ! is_dir(dirname($path)) || is_dir($path)) {
                throw new RuntimeException('Geçerli dosya ve hedef yol seçin; sınır 20 MB.');
            }
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'ts', 'tsx', 'jsx', 'css', 'html', 'json', 'xml', 'md', 'txt', 'sql', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'pdf', 'docx', 'xlsx', 'pptx', 'woff', 'woff2', 'ttf', 'zip'])) {
                throw new RuntimeException('Dosya türü desteklenmiyor.');
            }
            if (is_file($path)) {
                if (($input['confirmation'] ?? '') !== 'DOSYAYI DEĞİŞTİR') {
                    throw new RuntimeException('Var olan dosya için DOSYAYI DEĞİŞTİR onayı gerekli.');
                }copy($path, $this->privateRoot().'/source-'.bin2hex(random_bytes(8)).'.bak');
            }
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $out = [];
                $status = 0;
                exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($upload['tmp_name']).' 2>&1', $out, $status);
                if ($status !== 0) {
                    throw new RuntimeException('PHP sözdizimi hatalı; hedef dosya korundu.');
                }
            }
            $tmp = tempnam(dirname($path), '.upload-');
            if (! move_uploaded_file($upload['tmp_name'], $tmp)) {
                @unlink($tmp);
                throw new RuntimeException('Dosya yüklenemedi.');
            }chmod($tmp, 0600);
            rename($tmp, $path);
        } elseif (in_array($action, ['move', 'copy'])) {
            $target = $this->path((string) ($input['target'] ?? ''), true);
            if (file_exists($target) || ! is_dir(dirname($target)) || ! is_file($path)) {
                throw new RuntimeException('Mevcut dosya ve boş bir hedef dosya yolu seçin.');
            }$action === 'move' ? rename($path, $target) : copy($path, $target);
            chmod($target, 0600);
        } elseif ($action === 'trash') {
            $id = bin2hex(random_bytes(16));
            rename($path, $this->privateRoot().'/trash-'.$id);
            file_put_contents($this->privateRoot().'/trash-'.$id.'.json', json_encode(['id' => $id, 'path' => $relative, 'at' => date(DATE_ATOM)], JSON_THROW_ON_ERROR), LOCK_EX);
        } elseif ($action === 'restore-file') {
            $id = $input['id'] ?? '';
            if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new RuntimeException('Çöp kutusu kaydı geçersiz.');
            }$file = $this->privateRoot().'/trash-'.$id.'.json';
            $meta = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $path = $this->path($meta['path'], true);
            if (file_exists($path) || ! is_dir(dirname($path))) {
                throw new RuntimeException('Eski dosya yolu kullanılıyor.');
            }rename($this->privateRoot().'/trash-'.$id, $path);
            unlink($file);
        } else {
            throw new RuntimeException('Dosya işlemi geçersiz.');
        }

        return ['action' => $action, 'file' => $relative];
    }

    public function folderZip(string $relative): string
    {
        $source = $this->path($relative);
        if (! is_dir($source)) {
            throw new RuntimeException('Klasör seçin.');
        }$tmp = tempnam(sys_get_temp_dir(), 'cte-native-zip-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $count = 0;
        $bytes = 0;
        try {
            $filter = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), function ($f) use ($relative, $source) {
                $path = trim($relative.'/'.substr($f->getPathname(), strlen($source) + 1), '/');
                try {
                    $this->path($path);

                    return true;
                } catch (RuntimeException) {
                    return false;
                }
            });
            foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $f) {
                $name = substr($f->getPathname(), strlen($source) + 1);
                $bytes += $f->isFile() ? $f->getSize() : 0;
                if (++$count > 5000 || $bytes > 64 * 1024 * 1024) {
                    throw new RuntimeException('ZIP sınırı 5000 öğe ve 64 MB.');
                }$f->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($f->getPathname(), $name);
            }

            return $tmp;
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            $zip->close();
        }
    }

    public function restore(PDO $pdo, string $contents, string $extension): array
    {
        $before = $this->backup($pdo);
        $allowed = $this->tables($pdo);
        if ($extension === 'cte') {
            $key = $this->env['APP_KEY'] ?? '';
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7), true);
            }
            $crypt = new Encrypter($key, $this->env['APP_CIPHER'] ?? 'AES-256-CBC');
            $payload = json_decode($crypt->decryptString($contents), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['format'] ?? '') !== 'cte-administration-1') {
                throw new RuntimeException('CTE yedek biçimi geçersiz.');
            }
            foreach ($payload['ddl'] ?? [] as $name => $ddl) {
                $physical = $this->prefix().$name;
                if (! preg_match('/^[a-z0-9_]+$/D', $name) || ! preg_match('/^CREATE TABLE `'.preg_quote($physical, '/').'`\s*\(/', $ddl)) {
                    throw new RuntimeException('Yedek şeması geçersiz.');
                }$query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
                $query->execute([$physical]);
                if (! $query->fetchColumn()) {
                    $pdo->exec($ddl);
                } else {
                    $columns = $pdo->query('SHOW COLUMNS FROM `'.$physical.'`')->fetchAll(PDO::FETCH_COLUMN);
                    $start = strpos($ddl, '(');
                    $end = strrpos($ddl, ')');
                    foreach ((new SqlDump)->split(substr($ddl, $start + 1, $end - $start - 1)) as $definition) {
                        if (preg_match('/^`([a-z0-9_]+)`\s+/', $definition, $m) && ! in_array($m[1], $columns)) {
                            $pdo->exec('ALTER TABLE `'.$physical.'` ADD COLUMN '.$definition);
                        }
                    }
                }
            }
            $allowed = $this->tables($pdo);
            $tables = $payload['tables'];
            if (! array_key_exists('private_file_deletions', $tables) && in_array($this->prefix().'private_file_deletions', $allowed, true)) {
                $tables['private_file_deletions'] = [];
            }
            foreach ($tables as $name => $rows) {
                $physical = $this->prefix().$name;
                if (! in_array($physical, $allowed, true)) {
                    throw new RuntimeException('Yedek şeması farklı; önce şema onarımı yapın.');
                }$columns = $pdo->query('SHOW COLUMNS FROM `'.$physical.'`')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $row) {
                    if (array_diff(array_keys($row), $columns)) {
                        throw new RuntimeException('Yedekte bilinmeyen kolon var.');
                    }
                }
            }
            $active = false;
            foreach ($tables['users'] ?? [] as $u) {
                if ($u['is_active'] && $u['is_system_admin']) {
                    $active = true;
                }
            }if (! $active) {
                throw new RuntimeException('Yedekte etkin yönetici yok.');
            }
            $files = [];
            $root = $this->base.'/storage/app/private';
            foreach ($payload['files'] ?? [] as $relative => $encoded) {
                if (! preg_match('~^[\pL\pN _()/.-]+$~uD', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $relative) || str_starts_with($relative, 'operations/') || (str_starts_with($relative, 'administration/') && ! str_starts_with($relative, 'administration/files/'))) {
                    throw new RuntimeException('Yedek dosya yolu geçersiz.');
                }$path = $root;
                foreach (explode('/', $relative) as $part) {
                    $path .= '/'.$part;
                    if (is_link($path)) {
                        throw new RuntimeException('Yedek yolu sembolik bağlantı içeriyor.');
                    }
                }$data = base64_decode($encoded, true);
                if ($data === false) {
                    throw new RuntimeException('Yedek dosya içeriği geçersiz.');
                }$files[$path] = $data;
            }
            $versions = $pdo->query('SELECT id,session_version FROM `'.$this->prefix().'users`')->fetchAll(PDO::FETCH_KEY_PAIR);
            $tables['users'] = SessionGeneration::invalidateRestoredUsers($tables['users'], $versions);
            $userColumns = $pdo->query('SHOW COLUMNS FROM `'.$this->prefix().'users`')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('is_master_admin', $userColumns, true)) {
                $tables['users'] = MasterAdministrator::restore($tables['users']);
            }
            $previous = [];
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->beginTransaction();
            try {
                foreach (array_reverse(array_keys($tables)) as $name) {
                    $pdo->exec('DELETE FROM `'.$this->prefix().$name.'`');
                }foreach ($tables as $name => $rows) {
                    $columns = $pdo->query('SHOW COLUMNS FROM `'.$this->prefix().$name.'`')->fetchAll(PDO::FETCH_ASSOC);
                    $generated = array_column(array_filter($columns, fn ($column) => str_contains($column['Extra'], 'GENERATED')), 'Field');
                    foreach ($rows as $row) {
                        $row = array_diff_key($row, array_flip($generated));
                        $q = $pdo->prepare('INSERT INTO `'.$this->prefix().$name.'` ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                        // PDO's default string binding turns false into '', rejected by strict MariaDB integer columns.
                        $q->execute(array_map(static fn ($value) => is_bool($value) ? (int) $value : $value, array_values($row)));
                    }
                }SessionGeneration::rotate($this->base.'/storage');
                foreach ($files as $path => $data) {
                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), 0700, true);
                    }$previous[$path] = is_file($path) ? file_get_contents($path) : null;
                    if (is_file($path)) {
                        copy($path, $this->privateRoot().'/source-'.bin2hex(random_bytes(8)).'.bak');
                    }$tmp = tempnam(dirname($path), '.restore-');
                    if (file_put_contents($tmp, $data, LOCK_EX) === false) {
                        throw new RuntimeException('Geri yükleme dosyası yazılamadı.');
                    }chmod($tmp, 0600);
                    if (! rename($tmp, $path)) {
                        throw new RuntimeException('Geri yükleme dosyası yerleştirilemedi.');
                    }
                }$pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }foreach ($previous as $path => $data) {
                    if ($data === null) {
                        @unlink($path);
                    } else {
                        file_put_contents($path, $data, LOCK_EX);
                    }
                }throw $e;
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            return ['backup' => $before, 'restored' => true];
        }
        if ($extension !== 'sql') {
            throw new RuntimeException('SQL veya CTE yedeği seçin.');
        }$statements = (new SqlDump)->statements($contents);
        foreach ($statements as $sql) {
            if (preg_match('/^SET FOREIGN_KEY_CHECKS=[01]$/iD', $sql)) {
                continue;
            }if (! preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([a-z0-9_]+)`/i', $sql, $m) || ! str_starts_with($m[1], $this->prefix())) {
                throw new RuntimeException('Yedek SQL yalnız bu kurulumun tablo/veri komutlarını içermeli.');
            }if (preg_match('/\b(?:FEDERATED|CONNECTION|DIRECTORY|TABLESPACE|OUTFILE|DUMPFILE|LOAD_FILE|INFILE|TRIGGER|PROCEDURE|FUNCTION|SELECT|UNION|ATTACH|DATABASE|DATA\s+DIRECTORY|INDEX\s+DIRECTORY)\b/i', cte_sql_dis_metin($sql))) {
                throw new RuntimeException('SQL yedeği geçersiz.');
            }preg_match_all('/\bREFERENCES\s+(`?[a-zA-Z0-9_]+`?)(\s*\.)?/i', $sql, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                if (! empty($ref[2]) || ! str_starts_with(trim($ref[1], '`'), $this->prefix())) {
                    throw new RuntimeException('SQL yedeğinin ilişkisi kurulum dışında.');
                }
            }if (preg_match('/^INSERT/i', $sql)) {
                (new SqlDump)->parse($sql);
            }
        }
        // SQL dumps may restore session rows too; invalidate their generation before any DDL runs.
        SessionGeneration::rotate($this->base.'/storage');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['backup' => $before, 'restored' => true];
    }

    public function resetAdmin(PDO $pdo, string $username, string $password, string $repeat): array
    {
        if (mb_strlen($password) < 6 || strlen($password) > 72 || $password !== $repeat) {
            throw new RuntimeException('En az 6 karakter ve eşleşen parola tekrarı gerekli.');
        }
        if (! preg_match('/^[\pL\pN_.-]{2,100}$/uD', $username)) {
            throw new RuntimeException('Geçerli yönetici kullanıcı adı girin.');
        }
        $backup = $this->backup($pdo);
        if ((int) $pdo->query('SELECT COUNT(*) FROM `'.$this->prefix().'users`')->fetchColumn() === 0) {
            $q = $pdo->prepare('INSERT INTO `'.$this->prefix().'users` (username,full_name,password,is_system_admin,is_active,must_change_password,session_version,revision,created_at,updated_at) VALUES (?,?,?,1,1,1,0,1,NOW(),NOW())');
            $q->execute([$username, $username, password_hash($password, PASSWORD_BCRYPT)]);

            $columns = $pdo->query('SHOW COLUMNS FROM `'.$this->prefix().'users`')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('is_master_admin', $columns, true) && MasterAdministrator::fromLegacy(['username' => $username, 'is_system_admin' => true])) {
                $mark = $pdo->prepare('UPDATE `'.$this->prefix().'users` SET is_master_admin=1 WHERE username=?');
                $mark->execute([$username]);
            }

            return ['backup' => $backup, 'admin_created' => true];
        }$q = $pdo->prepare('UPDATE `'.$this->prefix().'users` SET password=?, must_change_password=1,is_active=1,failed_login_attempts=0,locked_at=NULL,remember_token=NULL,session_version=session_version+1,revision=revision+1 WHERE username=? AND is_system_admin=1');
        $q->execute([password_hash($password, PASSWORD_BCRYPT), $username]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Yönetici hesabı bulunamadı.');
        }

        return ['backup' => $backup, 'password_reset' => true];
    }
}
