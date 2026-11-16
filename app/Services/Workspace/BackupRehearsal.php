<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagementAccess;
use App\Services\Audit\AuditLog;
use App\Services\Operations\OperationalState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Restore and compare a panel snapshot only in a newly created disposable database. */
final class BackupRehearsal
{
    public function read(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);

        return ['supported' => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true),
            'backups' => DB::table('administration_backups')->whereNull('archived_at')->orderByDesc('id')->limit(50)->get(['id', 'name', 'created_at', 'bytes'])->all(),
            'last' => app(OperationalState::class)->read()['rehearsal'] ?? null];
    }

    public function run(User $actor, int $id): array
    {
        $actor = app(ManagementAccess::class)->actor($actor);
        abort_unless(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 422, 'Gerçek geri yükleme provası MariaDB/MySQL bağlantısı gerektirir.');
        $lock = fopen(app(ManagedDatabase::class)->root().'/.rehearsal-lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Prova kilidi açılamadı.');
        }
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 409, 'Başka bir geri yükleme provası sürüyor.');

            return $this->perform($actor, $id);
        } catch (\Throwable $error) {
            app(OperationalState::class)->update(function ($state) {
                $previous = $state['rehearsal'] ?? [];

                return array_replace($state, ['rehearsal' => ['status' => 'failed', 'at' => now()->toIso8601String(),
                    'lastSuccessAt' => ($previous['status'] ?? '') === 'passed' ? $previous['at'] : ($previous['lastSuccessAt'] ?? null)]]);
            });
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function perform(User $actor, int $id): array
    {
        $database = app(ManagedDatabase::class);
        $database->verify($actor, $id);
        $backup = $database->backup($actor, $id);
        $payload = json_decode(Crypt::decryptString(file_get_contents($backup['path'])), true, 512, JSON_THROW_ON_ERROR);
        $target = 'cte_restore_'.bin2hex(random_bytes(12));
        $directory = storage_path('app/private/administration/rehearsals/'.bin2hex(random_bytes(12)));
        $settings = DB::connection()->getConfig();
        $prefix = DB::connection()->getTablePrefix();
        abort_if($target === $settings['database'], 422);
        foreach ($payload['ddl'] as $name => $ddl) {
            if (! preg_match('/^[a-z0-9_]+$/D', $name) || ! preg_match('/^CREATE TABLE `'.preg_quote($prefix.$name, '/').'`\s*\(/', $ddl)
                || preg_match('/`[^`]+`\s*\.\s*`|;\s*\S/', $ddl)) {
                throw new RuntimeException('Prova şeması başka veritabanına başvuramaz.');
            }
        }
        if ($payload['ddl'] === []) {
            throw new RuntimeException('Yedekte MariaDB şema tanımları yok.');
        }
        $created = false;
        $started = hrtime(true);
        try {
            DB::statement('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.portal_rehearsal' => array_replace($settings, ['database' => $target, 'url' => null])]);
            DB::purge('portal_rehearsal');
            $copy = DB::connection('portal_rehearsal');
            $copy->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($payload['ddl'] as $ddl) {
                $copy->unprepared($ddl);
            }
            $counts = [];
            foreach ($payload['tables'] as $table => $rows) {
                $generated = array_column(array_filter($copy->getSchemaBuilder()->getColumns($table), fn ($column) => $column['generation'] !== null), 'name');
                $insertable = array_map(fn ($row) => array_diff_key($row, array_flip($generated)), $rows);
                foreach (array_chunk($insertable, 100) as $chunk) {
                    $copy->table($table)->insert($chunk);
                }
                $expected = [];
                foreach ($rows as $row) {
                    $hash = $this->rowHash($row);
                    $expected[$hash] = ($expected[$hash] ?? 0) + 1;
                }
                $actualCount = 0;
                foreach ($copy->table($table)->cursor() as $row) {
                    $hash = $this->rowHash((array) $row);
                    if (($expected[$hash] ?? 0) < 1) {
                        throw new RuntimeException('Prova satır içeriği yedekle eşleşmedi.');
                    }
                    $expected[$hash]--;
                    $actualCount++;
                }
                if ($actualCount !== count($rows) || array_sum($expected) !== 0) {
                    throw new RuntimeException('Prova kayıt sayısı eşleşmedi.');
                }
                $counts[$table] = $actualCount;
            }
            File::makeDirectory($directory, 0700, true);
            foreach ($payload['files'] as $relative => $encoded) {
                if (! preg_match('~^[\pL\pN _()/.-]+$~uD', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                    throw new RuntimeException('Prova dosya yolu geçersiz.');
                }
                $bytes = base64_decode($encoded, true);
                if ($bytes === false) {
                    throw new RuntimeException('Prova dosyası çözülemedi.');
                }
                $path = $directory.'/'.$relative;
                File::ensureDirectoryExists(dirname($path), 0700);
                if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || ! hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) {
                    throw new RuntimeException('Prova özel dosyası doğrulanamadı.');
                }
                chmod($path, 0600);
            }
            $result = ['status' => 'passed', 'backupId' => $id, 'tables' => count($counts), 'rows' => array_sum($counts), 'files' => count($payload['files']), 'durationMs' => (int) ((hrtime(true) - $started) / 1000000), 'at' => now()->toIso8601String()];
        } finally {
            DB::purge('portal_rehearsal');
            try {
                if ($created) {
                    DB::statement('DROP DATABASE `'.$target.'`');
                }
            } finally {
                if (is_dir($directory) && ! File::deleteDirectory($directory)) {
                    throw new RuntimeException('Prova dosyaları temizlenemedi.');
                }
            }
        }
        $result['temporaryResourcesCleaned'] = true;
        app(OperationalState::class)->update(fn ($state) => array_replace($state, ['rehearsal' => $result]));
        app(AuditLog::class)->record($actor, 'backup_rehearsal', $id, 'verified', array_intersect_key($result, array_flip(['tables', 'rows', 'files', 'durationMs'])));

        return $result;
    }

    private function rowHash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode(array_map(fn ($value) => $value === null ? null : base64_encode((string) $value), $row), JSON_THROW_ON_ERROR));
    }
}
