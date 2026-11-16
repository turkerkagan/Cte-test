<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Imports\ImportTargetGuard;
use App\Services\Operations\OperationalState;
use App\Services\Operations\PortalHealth;
use App\Services\Security\MasterAdministrator;
use App\Services\Security\SessionGeneration;
use App\Support\FilesystemPaths;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class ManagedDatabase
{
    private function builder(): Builder
    {
        return DB::connection()->getSchemaBuilder();
    }

    public function root(): string
    {
        $root = storage_path('app/private/administration/backups');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        return $root;
    }

    public function tables(): array
    {
        $prefix = DB::connection()->getTablePrefix();
        $tables = [];
        foreach ($this->builder()->getTableListing(schema: $this->builder()->getCurrentSchemaListing(), schemaQualified: false) as $name) {
            if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                continue;
            } $logical = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            if (preg_match('/^[a-z0-9_]+$/D', $logical)) {
                $tables[] = $logical;
            }
        }
        sort($tables);

        return $tables;
    }

    private function schema(): array
    {
        $result = [];
        foreach ($this->tables() as $table) {
            $result[$table] = array_map(fn ($c) => ['name' => $c['name'], 'type' => $c['type_name'], 'nullable' => $c['nullable']], $this->builder()->getColumns($table));
        }

        return $result;
    }

    private function compatibleSchema(array $snapshot): bool
    {
        $current = $this->schema();
        // Older backups predate this operational queue; its safe restore default is empty.
        if (! array_key_exists('private_file_deletions', $snapshot)) {
            unset($current['private_file_deletions']);
        }
        if (array_diff(array_keys($snapshot), array_keys($current)) || array_diff(array_keys($current), array_keys($snapshot))) {
            return false;
        }
        foreach ($snapshot as $table => $columns) {
            $old = collect($columns)->keyBy('name');
            $now = collect($current[$table])->keyBy('name');
            foreach ($old as $name => $definition) {
                if (! isset($now[$name]) || $definition !== $now[$name]) {
                    return false;
                }
            }foreach ($this->builder()->getColumns($table) as $column) {
                if (! $old->has($column['name']) && ! $column['nullable'] && $column['default'] === null) {
                    return false;
                }
            }
        }

        return true;
    }

    public function read(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $state = Validator::make($input, ['tab' => ['nullable', 'in:health,tables,backups,repair,conversion'], 'table' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'database' => ['nullable', 'string', 'max:100']])->validate();
        $state['tab'] ??= 'tables';
        $table = $state['table'] ?? null;
        $inspection = app(DatabaseTools::class)->inspect($actor, $state['database'] ?? null, $table, (int) ($state['page'] ?? 1));

        return $inspection + ['health' => app(PortalHealth::class)->snapshot(), 'queryState' => $state,
            'backups' => DB::table('administration_backups')->whereNull('archived_at')->orderByDesc('id')->get(['id', 'name', 'bytes', 'summary', 'created_at'])->map(function ($r) {
                $r->summary = json_decode($r->summary, true, flags: JSON_THROW_ON_ERROR);

                return (array) $r;
            })->all(),
            'plans' => app(BackupPlans::class)->read(), 'plan' => app(ManagementSettings::class)->read('backup_plan', ['enabled' => false, 'time' => '02:00', 'keep' => 14])];
    }

    private function privateFiles(): array
    {
        $root = storage_path('app/private');
        if (! is_dir($root)) {
            return [];
        } $result = [];
        $iterator = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), function ($file) use ($root) {
            $relative = FilesystemPaths::relativeTo($root, $file->getPathname());
            if (preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $relative) || str_starts_with($relative, 'operations') || (str_starts_with($relative, 'administration') && $relative !== 'administration' && $relative !== 'administration/files' && ! str_starts_with($relative, 'administration/files/'))) {
                return false;
            }
            if ($file->isLink()) {
                throw new RuntimeException('Özel dosyalarda sembolik bağlantı bulundu.');
            }

            return true;
        });
        foreach (new \RecursiveIteratorIterator($iterator) as $file) {
            if ($file->isFile()) {
                $result[FilesystemPaths::relativeTo($root, $file->getPathname())] = $file;
            }
        }ksort($result);

        return $result;
    }

    public function create(User $actor, string $reason = 'manual'): array
    {
        app(ManagementAccess::class)->actor($actor);
        app(ImportTargetGuard::class)->assertSafe();
        $lock = fopen($this->root().'/.lock', 'c');
        flock($lock, LOCK_EX);
        $path = null;
        try {
            $payload = DB::transaction(function () use ($actor) {
                app(ManagementAccess::class)->actor($actor, true);
                $tables = [];
                $bytes = 0;
                foreach ($this->tables() as $table) {
                    if (in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'administration_backups'])) {
                        continue;
                    }
                    $names = $this->builder()->getColumnListing($table);
                    $query = DB::table($table)->orderBy(in_array('id', $names) ? 'id' : $names[0]);
                    $tables[$table] = [];
                    foreach ($query->cursor() as $row) {
                        $entry = (array) $row;
                        $bytes += strlen(json_encode($entry, JSON_THROW_ON_ERROR));
                        if ($bytes > 64 * 1024 * 1024) {
                            throw new RuntimeException('Panel yedeği sınırı 64 MB. Tam yedek için portal:backup komutunu kullanın.');
                        } $tables[$table][] = $entry;
                    }
                }
                $ddl = [];
                if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
                    foreach ($this->tables() as $name) {
                        $ddl[$name] = array_values((array) DB::selectOne('SHOW CREATE TABLE `'.DB::connection()->getTablePrefix().$name.'`'))[1];
                    }
                }
                // Keep the installation write lock until both database rows and their files are copied.
                $files = [];
                $fileBytes = 0;
                foreach ($this->privateFiles() as $relative => $file) {
                    $contents = file_get_contents($file->getPathname());
                    if ($contents === false) {
                        throw new RuntimeException('Yedek dosyası okunamadı.');
                    }
                    $fileBytes += strlen($contents);
                    if ($fileBytes > 64 * 1024 * 1024) {
                        throw new RuntimeException('Panel dosya yedeği sınırı 64 MB.');
                    }
                    $files[$relative] = base64_encode($contents);
                }
                foreach (['person_photos', 'meal_files', 'message_attachments'] as $table) {
                    foreach ($tables[$table] ?? [] as $row) {
                        if ($table === 'message_attachments' && (! empty($row['deleted_at']) || $row['expires_at'] < now()->toDateTimeString())) {
                            continue;
                        }
                        if (! empty($row['path']) && ! array_key_exists($row['path'], $files)) {
                            throw new RuntimeException('Kayıtta kullanılan özel dosya eksik; yedek oluşturulmadı.');
                        }
                    }
                }

                return ['ddl' => $ddl, 'format' => 'cte-administration-1', 'at' => now()->toIso8601String(), 'schema' => $this->schema(), 'tables' => $tables, 'files' => $files];
            });
            $counts = array_map('count', $payload['tables']);
            $name = 'cte-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.cte';
            $path = $this->root().'/'.$name;
            $contents = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            file_put_contents($path, $contents, LOCK_EX);
            chmod($path, 0600);
            $summary = ['tables' => $counts, 'files' => count($payload['files']), 'reason' => $reason, 'schema_hash' => hash('sha256', json_encode($payload['schema'], JSON_THROW_ON_ERROR))];
            $id = DB::transaction(function () use ($actor, $name, $path, $summary, $counts, $payload) {
                $id = DB::table('administration_backups')->insertGetId(['name' => $name, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                app(AuditLog::class)->record($actor, 'administration_backup', $id, 'created', ['table_count' => count($counts), 'file_count' => count($payload['files'])]);

                return $id;
            });
            $this->recordBackupResult(true);

            return ['id' => $id, 'name' => $name, 'summary' => $summary];
        } catch (\Throwable $e) {
            if ($path && is_file($path)) {
                unlink($path);
            } $this->recordBackupResult(false);
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recordBackupResult(bool $success): void
    {
        try {
            app(OperationalState::class)->result('backup', $success);
        } catch (\Throwable) {
            // A monitoring failure must not invalidate an already committed backup.
        }
    }

    public function backup(User $actor, int $id): array
    {
        app(ManagementAccess::class)->actor($actor);
        $row = DB::table('administration_backups')->where('id', $id)->whereNull('archived_at')->first();
        abort_if(! $row, 404);
        abort_unless(preg_match('/^cte-[a-z0-9-]+\.cte$/D', $row->name), 422);
        $path = $this->root().'/'.$row->name;
        abort_unless(is_file($path) && ! is_link($path) && hash_equals($row->sha256, hash_file('sha256', $path)), 422, 'Yedek dosyası doğrulanamadı.');

        return ['row' => $row, 'path' => $path];
    }

    public function verify(User $actor, int $id): array
    {
        $backup = $this->backup($actor, $id);
        $payload = json_decode(Crypt::decryptString(file_get_contents($backup['path'])), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($payload['format'] ?? '') === 'cte-administration-1', 422);

        return ['verified' => true, 'created_at' => $payload['at'], 'checksum' => $backup['row']->sha256, 'tables' => array_map('count', $payload['tables']), 'files' => count($payload['files']), 'schemaMatches' => $this->compatibleSchema($payload['schema'])];
    }

    public function restore(User $actor, int $id, array $input): array
    {
        $data = Validator::make($input, ['confirmed' => ['accepted'], 'confirmation' => ['required', 'in:GERİ YÜKLE'], 'checksum' => ['required', 'string', 'size:64']])->validate();
        app(ImportTargetGuard::class)->assertSafe();
        $backup = $this->backup($actor, $id);
        abort_unless(hash_equals($backup['row']->sha256, $data['checksum']), 409, 'Yedek değişti.');
        $payload = json_decode(Crypt::decryptString(file_get_contents($backup['path'])), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($payload['format'] ?? '') === 'cte-administration-1' && $this->compatibleSchema($payload['schema']), 422, 'Şema farklı; bu yedek mevcut sürüme doğrudan geri yüklenemez.');
        if (! array_key_exists('private_file_deletions', $payload['tables']) && $this->builder()->hasTable('private_file_deletions')) {
            $payload['tables']['private_file_deletions'] = [];
        }
        $expected = array_values(array_diff($this->tables(), ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'administration_backups']));
        $actual = array_keys($payload['tables']);
        sort($actual);
        sort($expected);
        abort_unless($actual === $expected, 422, 'Yedek tablo grubu eksik.');
        $ids = array_column($payload['tables']['users'], 'id');
        abort_unless(collect($payload['tables']['users'])->contains(fn ($u) => (bool) $u['is_system_admin'] && (bool) $u['is_active']), 422, 'Yedekte etkin yönetici bulunamadı.');
        foreach ($payload['tables'] as $table => $rows) {
            foreach ($rows as $row) {
                abort_unless(array_diff(array_keys($row), $this->builder()->getColumnListing($table)) === [], 422);
            }
        }
        $files = [];
        $root = FilesystemPaths::normalize(storage_path('app/private'));
        foreach ($payload['files'] as $relative => $encoded) {
            abort_unless(preg_match('~^[\pL\pN _()/.-]+$~uD', $relative) && ! preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $relative) && ! str_contains($relative, '..') && ! str_starts_with($relative, '/') && (! str_starts_with($relative, 'administration/') || str_starts_with($relative, 'administration/files/')) && ! str_starts_with($relative, 'operations/'), 422, 'Yedek dosya yolu geçersiz.');
            $path = $root.'/'.$relative;
            $part = $root;
            foreach (explode('/', $relative) as $piece) {
                $part .= '/'.$piece;
                abort_if(is_link($part), 422);
            }
            $decoded = base64_decode($encoded, true);
            abort_unless($decoded !== false, 422);
            $files[$path] = $decoded;
        }
        $payload['tables']['migrations'] = DB::table('migrations')->get()->map(fn ($r) => (array) $r)->all();
        $before = $this->create($actor, 'before-restore');
        $previous = [];
        $maintenance = app()->isDownForMaintenance();
        if (! $maintenance) {
            Artisan::call('down', ['--retry' => 5]);
        }
        try {
            $this->builder()->disableForeignKeyConstraints();
            try {
                DB::transaction(function () use ($actor, $payload, $files, &$previous, $ids) {
                    app(ManagementAccess::class)->actor($actor, true);
                    $payload['tables']['users'] = MasterAdministrator::restore(SessionGeneration::invalidateRestoredUsers($payload['tables']['users'], DB::table('users')->pluck('session_version', 'id')->all()));
                    foreach (array_reverse(array_keys($payload['tables'])) as $table) {
                        DB::table($table)->delete();
                    }
                    foreach ($payload['tables'] as $table => $rows) {
                        $generated = array_column(array_filter($this->builder()->getColumns($table), fn ($column) => $column['generation'] !== null), 'name');
                        $insertable = array_map(fn ($row) => array_diff_key($row, array_flip($generated)), $rows);
                        foreach (array_chunk($insertable, 100) as $chunk) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                    DB::table('administration_backups')->whereNotIn('created_by', $ids)->update(['created_by' => null]);
                    SessionGeneration::rotate(storage_path());
                    foreach ($this->privateFiles() as $relative => $file) {
                        $path = FilesystemPaths::normalize($file->getPathname());
                        if (! isset($files[$path])) {
                            $previous[$path] = file_get_contents($path);
                            unlink($path);
                        }
                    }
                    foreach ($files as $path => $contents) {
                        $previous[$path] = is_file($path) ? file_get_contents($path) : null;
                        if (! is_dir(dirname($path))) {
                            mkdir(dirname($path), 0700, true);
                        }$tmp = tempnam(dirname($path), '.restore-');
                        file_put_contents($tmp, $contents, LOCK_EX);
                        chmod($tmp, 0600);
                        rename($tmp, $path);
                    }
                    if (in_array($actor->id, $ids)) {
                        app(AuditLog::class)->record($actor, 'administration_backup', 0, 'restored', ['table_count' => count($payload['tables'])]);
                    }
                });
            } finally {
                $this->builder()->enableForeignKeyConstraints();
            }
        } catch (\Throwable $e) {
            foreach ($previous as $path => $contents) {
                if ($contents === null) {
                    @unlink($path);
                } else {
                    file_put_contents($path, $contents, LOCK_EX);
                }
            } throw $e;
        } finally {
            if (! $maintenance) {
                Artisan::call('up');
            }
        }

        return ['restored' => true, 'before_backup_id' => $before['id'], 'redirect' => route('login')];
    }

    public function archive(User $actor, int $id): void
    {
        $this->backup($actor, $id);
        DB::transaction(function () use ($actor, $id) {
            $actor = app(ManagementAccess::class)->actor($actor, true);
            DB::table('administration_backups')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'administration_backup', $id, 'archived', []);
        });
    }

    public function plan(User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'values' => ['required', 'array:enabled,time,keep'], 'values.enabled' => ['required', 'boolean'], 'values.time' => ['required', 'date_format:H:i'], 'values.keep' => ['required', 'integer', 'between:1,90']])->validate();
        app(ManagementSettings::class)->save($actor, 'backup_plan', $data['values'], $data['revision']);
    }

    public function repair(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);
        app(ImportTargetGuard::class)->assertSafe();
        $backup = $this->create($actor, 'before-repair');
        $status = Artisan::call('migrate', ['--force' => true]);
        if ($status !== 0) {
            throw new RuntimeException('Şema bakımı tamamlanamadı. Ön yedek korundu.');
        }
        $checks = ['migration' => 'success', 'backup_id' => $backup['id'], 'health' => app(PortalHealth::class)->snapshot()];
        app(AuditLog::class)->record($actor, 'administration_system', 0, 'repaired', ['backup_id' => $backup['id']]);

        return $checks;
    }
}
