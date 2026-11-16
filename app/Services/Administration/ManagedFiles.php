<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Modules\DefinitionConflict;
use App\Support\FilesystemPaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Every exposed path is checked component by component; credentials are never exposed. */
final class ManagedFiles
{
    private string $scope = 'documents';

    public function scope(string $scope): self
    {
        abort_unless(in_array($scope, ['documents', 'application']), 422);
        $copy = clone $this;
        $copy->scope = $scope;

        return $copy;
    }

    private function historyRoot(): string
    {
        $root = storage_path('app/private/administration/files');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        return $root;
    }

    public function root(): string
    {
        $root = $this->scope === 'application' ? base_path() : $this->historyRoot();
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        if (is_link($root)) {
            abort(422, 'Dosya kökü güvenli değil.');
        }

        return $root;
    }

    public function path(string $relative, bool $allowMissing = false): string
    {
        if (strlen($relative) > 300 || preg_match('~(?:^|/)(?:\.{1,2}|\.[^/]+)(?:/|$)|[\\\\\x00-\x1f:]~', $relative) || str_starts_with($relative, '/')) {
            abort(422, 'Geçersiz dosya yolu.');
        }
        if ($this->scope === 'application' && $relative !== '') {
            abort_unless(in_array(explode('/', $relative)[0], ['app', 'resources', 'routes', 'config', 'database', 'public', 'bootstrap']), 422, 'Bu uygulama yoluna erişilemez.');
        }
        if ($this->scope === 'application' && (in_array($relative, ['public/index.php', 'public/recovery.php']) || str_starts_with($relative, 'bootstrap/cache'))) {
            abort(422, 'Kurtarma ve başlangıç dosyası korunur.');
        }
        $parts = $relative === '' ? [] : explode('/', $relative);
        $path = $this->root();
        foreach ($parts as $part) {
            if ($part === '' || ! preg_match('/^[\pL\pN _().-]+$/uD', $part)) {
                abort(422, 'Geçersiz dosya adı.');
            } $path .= '/'.$part;
            if (is_link($path)) {
                abort(422, 'Sembolik bağlantılara erişilemez.');
            }
        }
        if (! $allowMissing && ! file_exists($path)) {
            abort(404);
        }

        return $path;
    }

    public function read(User $actor, string $relative): array
    {
        app(ManagementAccess::class)->actor($actor);
        $path = $this->path($relative);
        if (is_file($path)) {
            $editable = ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'pdf', 'woff', 'woff2', 'ttf', 'docx', 'xlsx', 'pptx']) && filesize($path) <= 2 * 1024 * 1024 && ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['zip', 'cte']);
            $text = $editable ? file_get_contents($path) : '';
            $editable = $editable && mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0");

            return ['scope' => $this->scope, 'path' => $relative, 'text' => $editable ? $text : '', 'editable' => $editable, 'bytes' => filesize($path), 'checksum' => hash_file('sha256', $path), 'isFile' => true, 'entries' => [], 'versions' => $this->versions($relative)];
        }
        $entries = [];
        foreach (scandir($path) as $name) {
            if ($name[0] !== '.' && ! is_link($path.'/'.$name) && ($this->scope !== 'application' || $relative !== '' || in_array($name, ['app', 'resources', 'routes', 'config', 'database', 'public', 'bootstrap']))) {
                $entries[] = ['name' => $name, 'path' => trim($relative.'/'.$name, '/'), 'directory' => is_dir($path.'/'.$name), 'bytes' => is_file($path.'/'.$name) ? filesize($path.'/'.$name) : 0, 'modified' => date('Y-m-d H:i:s', filemtime($path.'/'.$name))];
            }
        }
        usort($entries, fn ($a, $b) => ($b['directory'] <=> $a['directory']) ?: strnatcasecmp($a['name'], $b['name']));

        return ['scope' => $this->scope, 'path' => $relative, 'isFile' => false, 'entries' => $entries, 'trash' => $this->trash()];
    }

    private function trash(): array
    {
        $result = [];
        foreach (glob($this->historyRoot().'/.trash/*.json') ?: [] as $file) {
            $item = json_decode(file_get_contents($file), true);
            if (is_array($item) && ($item['scope'] ?? 'documents') === $this->scope) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function versions(string $relative): array
    {
        $result = [];
        foreach (glob($this->historyRoot().'/.versions/*.json') ?: [] as $file) {
            $item = json_decode(file_get_contents($file), true);
            if (is_array($item) && $item['path'] === $relative && ($item['scope'] ?? 'documents') === $this->scope) {
                $result[] = $item;
            }
        }
        usort($result, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $result;
    }

    private function version(string $path, string $relative): void
    {
        $root = $this->historyRoot().'/.versions';
        if (! is_dir($root)) {
            mkdir($root, 0700);
        }$id = bin2hex(random_bytes(16));
        copy($path, $root.'/'.$id);
        chmod($root.'/'.$id, 0600);
        file_put_contents($root.'/'.$id.'.json', json_encode(['scope' => $this->scope, 'id' => $id, 'path' => $relative, 'at' => now()->toDateTimeString(), 'checksum' => hash_file('sha256', $path)], JSON_THROW_ON_ERROR));
    }

    public function mutate(User $actor, array $input): array
    {
        $data = Validator::make($input, ['action' => ['required', 'in:folder,save,copy,move,delete,restore,version,extract'], 'path' => ['required', 'string', 'max:300'], 'target' => ['nullable', 'string', 'max:300'], 'checksum' => ['nullable', 'string', 'size:64'], 'text' => ['present_if:action,save', 'nullable', 'string', 'max:2097152'], 'version_id' => ['nullable', 'regex:/^[a-f0-9]{32}$/D'], 'trash_id' => ['nullable', 'regex:/^[a-f0-9]{32}$/D']])->validate();
        $lock = fopen($this->historyRoot().'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $actor = app(ManagementAccess::class)->actor($actor);
            $path = $this->path($data['path'], in_array($data['action'], ['folder', 'save', 'restore']));
            if ($this->scope === 'application' && in_array($data['action'], ['move', 'delete']) && in_array($data['path'], ['public', 'bootstrap'])) {
                abort(422, 'Başlangıç dosyalarını içeren klasör korunur.');
            }
            if ($data['action'] === 'folder') {
                abort_if(file_exists($path) || ! is_dir(dirname($path)), 422, 'Klasör yolu kullanılıyor ya da üst klasör bulunamadı.');
                mkdir($path, 0700);
            } elseif ($data['action'] === 'save') {
                $this->extension($path);
                abort_unless(is_dir(dirname($path)), 422);
                abort_if(is_dir($path), 422);
                $prior = is_file($path) ? hash_file('sha256', $path) : hash('sha256', '');
                if (! hash_equals($prior, $data['checksum'] ?? '')) {
                    throw new DefinitionConflict;
                }
                if (is_file($path)) {
                    $this->version($path, $data['path']);
                }
                $tmp = tempnam(dirname($path), '.write-');
                file_put_contents($tmp, $data['text'] ?? '', LOCK_EX);
                chmod($tmp, 0600);
                $this->lint($tmp, $path);
                rename($tmp, $path);
            } elseif (in_array($data['action'], ['copy', 'move'])) {
                $target = $this->path($data['target'] ?? '', true);
                abort_if(file_exists($target) || ! is_dir(dirname($target)) || str_starts_with($target, $path.'/'), 422, 'Kullanılmayan, kaynak dışında bir hedef seçin.');
                if (is_file($path)) {
                    $this->extension($target);
                    if ($data['action'] === 'copy') {
                        copy($path, $target);
                    } else {
                        rename($path, $target);
                    }chmod($target, 0600);
                } elseif ($data['action'] === 'move') {
                    rename($path, $target);
                } else {
                    $entries = [];
                    $bytes = 0;
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                        abort_if($file->isLink(), 422, 'Klasör sembolik bağlantı içeriyor.');
                        $relative = FilesystemPaths::relativeTo($path, $file->getPathname());
                        $this->path(trim(($data['target'] ?? '').'/'.$relative, '/'), true);
                        if ($file->isFile()) {
                            $this->extension($relative);
                        }$bytes += $file->isFile() ? $file->getSize() : 0;
                        $entries[] = [$file->getPathname(), $relative, $file->isDir()];
                        abort_if(count($entries) > 1000 || $bytes > 20 * 1024 * 1024, 422, 'Kopyalama sınırı 1000 öğe ve 20 MB.');
                    }
                    mkdir($target, 0700);
                    foreach ($entries as [$source,$relative,$directory]) {
                        if ($directory) {
                            mkdir($target.'/'.$relative, 0700);
                        } else {
                            copy($source, $target.'/'.$relative);
                            chmod($target.'/'.$relative, 0600);
                        }
                    }
                }
            } elseif ($data['action'] === 'delete') {
                abort_if($path === $this->root(), 422);
                $trash = $this->historyRoot().'/.trash';
                if (! is_dir($trash)) {
                    mkdir($trash, 0700);
                }
                $id = bin2hex(random_bytes(16));
                rename($path, $trash.'/'.$id);
                file_put_contents($trash.'/'.$id.'.json', json_encode(['scope' => $this->scope, 'id' => $id, 'path' => $data['path'], 'deleted_at' => now()->toDateTimeString()], JSON_THROW_ON_ERROR));
            } elseif ($data['action'] === 'extract') {
                abort_unless(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip', 422, 'ZIP dosyası seçin.');
                $target = $this->path($data['target'] ?? '', true);
                abort_if(file_exists($target) || ! is_dir(dirname($target)), 422, 'Kullanılmayan bir hedef klasör seçin.');
                $zip = new \ZipArchive;
                abort_unless($zip->open($path, \ZipArchive::RDONLY) === true, 422, 'Arşiv okunamadı.');
                $staging = $this->historyRoot().'/.unpack-'.bin2hex(random_bytes(16));
                try {
                    abort_if($zip->numFiles > 1000, 422, 'Arşivde en fazla 1000 öğe olabilir.');
                    $entries = [];
                    $bytes = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $name = rtrim($stat['name'], '/');
                        $this->path(trim(($data['target'] ?? '').'/'.$name, '/'), true);
                        $directory = str_ends_with($stat['name'], '/');
                        if (! $directory) {
                            $this->extension($name);
                        }$bytes += $stat['size'];
                        abort_if($bytes > 20 * 1024 * 1024, 422, 'Açılan arşiv en fazla 20 MB olabilir.');
                        $opsys = 0;
                        $attributes = 0;
                        $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                        abort_if((($attributes >> 16) & 0170000) === 0120000, 422, 'Arşiv sembolik bağlantı içeriyor.');
                        abort_if(isset($entries[$name]), 422, 'Arşivde tekrarlanan yol var.');
                        $entries[$name] = ['index' => $i, 'directory' => $directory];
                    }
                    foreach ($entries as $name => $entry) {
                        $parent = dirname($name);
                        while ($parent !== '.') {
                            abort_if(isset($entries[$parent]) && ! $entries[$parent]['directory'], 422, 'Arşiv yolları birbiriyle çakışıyor.');
                            $parent = dirname($parent);
                        }
                    }
                    mkdir($staging, 0700);
                    foreach ($entries as $name => $entry) {
                        $destination = $staging.'/'.$name;
                        if ($entry['directory']) {
                            if (! is_dir($destination)) {
                                mkdir($destination, 0700, true);
                            }
                        } else {
                            if (! is_dir(dirname($destination))) {
                                mkdir(dirname($destination), 0700, true);
                            }$contents = $zip->getFromIndex($entry['index']);
                            abort_unless($contents !== false, 422, 'Arşiv öğesi okunamadı.');
                            file_put_contents($destination, $contents, LOCK_EX);
                            chmod($destination, 0600);
                            $this->lint($destination, $destination);
                        }
                    }
                    rename($staging, $target);
                } finally {
                    $zip->close();
                    if (is_dir($staging)) {
                        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                        }rmdir($staging);
                    }
                }
            } elseif ($data['action'] === 'version') {
                $id = $data['version_id'] ?? '';
                abort_unless(preg_match('/^[a-f0-9]{32}$/D', $id), 422);
                $version = $this->historyRoot().'/.versions/'.$id;
                abort_unless(is_file($path) && is_file($version.'.json') && is_file($version), 404);
                $meta = json_decode(file_get_contents($version.'.json'), true, flags: JSON_THROW_ON_ERROR);
                abort_unless($meta['path'] === $data['path'] && ($meta['scope'] ?? 'documents') === $this->scope && hash_equals($meta['checksum'], hash_file('sha256', $version)), 422);
                if (! hash_equals(hash_file('sha256', $path), $data['checksum'] ?? '')) {
                    throw new DefinitionConflict;
                }
                $this->version($path, $data['path']);
                $temp = tempnam(dirname($path), '.version-');
                copy($version, $temp);
                chmod($temp, 0600);
                rename($temp, $path);
            } else {
                $id = $data['trash_id'] ?? '';
                abort_unless(preg_match('/^[a-f0-9]{32}$/D', $id), 422);
                $trash = $this->historyRoot().'/.trash/'.$id;
                abort_unless(is_file($trash.'.json'), 404);
                $meta = json_decode(file_get_contents($trash.'.json'), true, flags: JSON_THROW_ON_ERROR);
                abort_unless($meta['path'] === $data['path'] && ($meta['scope'] ?? 'documents') === $this->scope && ! file_exists($path) && is_dir(dirname($path)), 422, 'Eski dosya yolu kullanılıyor.');
                rename($trash, $path);
                unlink($trash.'.json');
            }
            app(AuditLog::class)->record($actor, 'administration_file', 0, $data['action'], ['file_name' => basename($data['path'])]);

            return ['saved' => true];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function lint(string $temporary, string $destination): void
    {
        if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'php') {
            return;
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporary).' 2>&1', $out, $code);
        if ($code !== 0) {
            @unlink($temporary);
            throw ValidationException::withMessages(['file' => 'PHP sözdizimi hatalı. Dosya değiştirilmedi.']);
        }
    }

    public function extension(string $path): void
    {
        abort_if($this->scope === 'documents' && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'css', 'tsx', 'ts', 'jsx', 'html']), 422, 'Kaynak dosyaları uygulama alanından yönetin.');
        abort_unless(in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['txt', 'json', 'csv', 'sql', 'md', 'log', 'zip', 'cte', 'php', 'css', 'js', 'tsx', 'ts', 'jsx', 'html', 'xml', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'pdf', 'docx', 'xlsx', 'pptx', 'woff', 'woff2', 'ttf']), 422, 'Bu dosya türü desteklenmiyor.');
    }

    public function upload(User $actor, string $directory, UploadedFile $file): void
    {
        app(ManagementAccess::class)->actor($actor);
        abort_if($file->getSize() > 20 * 1024 * 1024, 422, 'En fazla 20 MB yükleyin.');
        $path = $this->path(trim($directory.'/'.$file->getClientOriginalName(), '/'), true);
        $this->extension($path);
        abort_if(file_exists($path) || ! is_dir(dirname($path)), 422, 'Dosya yolu kullanılıyor.');
        $lock = fopen($this->historyRoot().'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            app(ManagementAccess::class)->actor($actor);
            abort_if(file_exists($path), 422);
            $this->lint($file->getPathname(), $path);
            $file->move(dirname($path), basename($path));
            chmod($path, 0600);
            app(AuditLog::class)->record($actor, 'administration_file', 0, 'uploaded', ['file_name' => basename($path)]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function zip(User $actor, string $relative): string
    {
        app(ManagementAccess::class)->actor($actor);
        $source = $this->path($relative);
        abort_unless(is_dir($source), 422);
        $temp = tempnam(sys_get_temp_dir(), 'cte-folder-');
        $zip = new \ZipArchive;
        $zip->open($temp, \ZipArchive::OVERWRITE);
        $count = 0;
        $bytes = 0;
        try {
            $filter = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), function ($file) use ($source, $relative) {
                $name = FilesystemPaths::relativeTo($source, $file->getPathname());
                $full = trim($relative.'/'.$name, '/');
                if (preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $name)) {
                    return false;
                }if ($this->scope === 'application' && (! in_array(explode('/', $full)[0], ['app', 'resources', 'routes', 'config', 'database', 'public', 'bootstrap']) || str_starts_with($full, 'bootstrap/cache') || in_array($full, ['public/index.php', 'public/recovery.php']))) {
                    return false;
                }

                return true;
            });
            foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $name = FilesystemPaths::relativeTo($source, $file->getPathname());
                if (preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $name)) {
                    continue;
                }
                $this->path(trim($relative.'/'.$name, '/'));
                abort_if($file->isLink(), 422);
                $bytes += $file->isFile() ? $file->getSize() : 0;
                abort_if(++$count > 5000 || $bytes > 64 * 1024 * 1024, 422, 'ZIP sınırı 5000 öğe ve 64 MB.');
                if ($file->isDir()) {
                    $zip->addEmptyDir($name);
                } else {
                    $zip->addFile($file->getPathname(), $name);
                }
            }
            $zip->close();

            return $temp;
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($temp);
            throw $e;
        }
    }

    public function uploadMany(User $actor, string $directory, array $files, array $paths): void
    {
        app(ManagementAccess::class)->actor($actor);
        abort_if(count($files) > 1000, 422);
        $destinations = [];
        $bytes = 0;
        foreach ($files as $i => $file) {
            $name = $paths[$i] ?? $file->getClientOriginalName();
            $path = $this->path(trim($directory.'/'.$name, '/'), true);
            $this->extension($path);
            $this->lint($file->getPathname(), $path);
            $bytes += $file->getSize();
            abort_if($bytes > 20 * 1024 * 1024 || isset($destinations[$path]) || file_exists($path), 422, 'Yol kullanılıyor veya toplam yükleme 20 MB sınırını aşıyor.');
            $destinations[$path] = $file;
        }
        $lock = fopen($this->historyRoot().'/.lock', 'c');
        flock($lock, LOCK_EX);
        $written = [];
        try {
            app(ManagementAccess::class)->actor($actor);
            foreach ($destinations as $path => $file) {
                abort_if(file_exists($path), 409);
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }$file->move(dirname($path), basename($path));
                chmod($path, 0600);
                $written[] = $path;
            }app(AuditLog::class)->record($actor, 'administration_file', 0, 'uploaded', ['file_count' => count($files)]);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                @unlink($path);
            }throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
