<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Imports\ImportTargetGuard;
use App\Services\Operations\PrivateFileCleanup;
use App\Services\Workflows\CaseFields;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class DatabaseTools
{
    public function databases(): array
    {
        return [DB::connection()->getDatabaseName()];
    }

    public function connection(?string $database = null): Connection
    {
        abort_unless(! $database || $database === DB::connection()->getDatabaseName(), 422, 'Yalnız uygulamanın cte veritabanı kullanılabilir.');

        return DB::connection();
    }

    public function inspect(User $actor, ?string $database = null, ?string $table = null, int $page = 1): array
    {
        app(ManagementAccess::class)->actor($actor);
        $c = $this->connection($database);
        $schema = $c->getSchemaBuilder();
        $tables = [];
        $rows = [];
        $columns = [];
        $prefix = $c->getTablePrefix();
        foreach ($schema->getTableListing(schema: $schema->getCurrentSchemaListing(), schemaQualified: false) as $name) {
            if (str_starts_with($name, $prefix)) {
                $logical = substr($name, strlen($prefix));
                $tables[] = ['name' => $logical, 'count' => $c->table($logical)->count(), 'columns' => count($schema->getColumns($logical))];
            }
        }
        $total = 0;
        if ($table) {
            abort_unless(in_array($table, array_column($tables, 'name'), true), 422);
            $columns = $schema->getColumns($table);
            $total = $c->table($table)->count();
            $page = min($page, max(1, (int) ceil($total / 50)));
            $names = array_column($columns, 'name');
            $safe = array_values(array_filter($names, fn ($n) => ! preg_match('/password|secret|token|hash|credential|payload|values|content|details|body|key|session|phone|email|tc_identity/i', $n)));
            if ($safe) {
                $rows = $c->table($table)->orderBy(in_array('id', $names) ? 'id' : $names[0])->offset(($page - 1) * 50)->limit(50)->get($safe)->map(fn ($r) => (array) $r)->all();
            }
        }

        return ['databases' => $this->databases(), 'database' => $c->getDatabaseName(), 'tables' => $tables, 'rows' => $rows, 'columns' => $columns, 'pagination' => ['page' => $page, 'pages' => max(1, (int) ceil($total / 50)), 'total' => $total]];
    }

    public function validateSql(string $sql, Connection $c): string
    {
        $statements = app(SqlDump::class)->statements($sql);
        abort_unless(count($statements) === 1, 422, 'Bir defada tek SQL komutu çalıştırın.');
        $statement = $statements[0];
        require_once resource_path('recovery/sql-reader.php');
        $outside = cte_sql_dis_metin($statement);
        abort_unless(preg_match('/^(SELECT|EXPLAIN|INSERT|UPDATE|DELETE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+(?:TABLE\s+)?)/i', $statement), 422, 'Bu komut uygulama veritabanı aracıyla çalıştırılamaz.');
        abort_if(preg_match('/\b(?:FEDERATED|CONNECTION|DIRECTORY|TABLESPACE|OUTFILE|DUMPFILE|INFILE|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL|SYS|PREPARE|EXECUTE|GRANT|REVOKE|USE|PROCEDURE|TRIGGER|FUNCTION|DATABASE|ATTACH|DETACH|PRAGMA)\b|@@|@\w|\/\*|--|#/i', $outside), 422, 'Sorgu kurulum dışına erişemez veya sunucu ayarlarını değiştiremez.');
        preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE|REFERENCES|LIKE|RENAME\s+TO)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(`?[a-zA-Z0-9_]+`?)(\s*\.)?/i', $statement, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            abort_if(! empty($m[2]), 422, 'Başka veritabanına erişilemez.');
            $name = trim($m[1], '`');
            abort_unless(str_starts_with($name, $c->getTablePrefix()), 422, 'Tablo bu kurulumun önekini taşımalı.');
        }
        // Comma joins and UNION can hide an extra table from a lightweight namespace checker.
        abort_if(preg_match('/\bUNION\b|\bFROM\b(?:(?!\b(?:WHERE|GROUP|ORDER|LIMIT|HAVING|ON)\b)[^;])*,|^UPDATE\b(?:(?!\bSET\b)[^;])*,|^DROP\s+TABLE\b[^;]+,/i', $outside), 422, 'Önekli kurulumda tek tablo veya açık JOIN kullanın.');

        return $statement;
    }

    public function execute(User $actor, array $input): array
    {
        $d = Validator::make($input, ['sql' => ['required', 'string', 'max:200000'], 'database' => ['nullable', 'string', 'max:100'], 'confirmed' => ['sometimes', 'accepted'], 'confirmation' => ['nullable', 'string'], 'checksum' => ['nullable', 'size:64']])->validate();
        $actor = app(ManagementAccess::class)->actor($actor);
        $c = $this->connection($d['database'] ?? null);
        $sql = $this->validateSql($d['sql'], $c);
        $read = (bool) preg_match('/^(?:SELECT|EXPLAIN)\b/i', $sql);
        if ($read) {
            $statement = $c->getPdo()->prepare($sql);
            $statement->execute();
            $rows = [];
            while (count($rows) < 200 && ($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }

            return ['rows' => $rows, 'limit' => 200];
        }
        $checksum = hash('sha256', $sql.'|'.$c->getDatabaseName());
        if (empty($d['confirmed'])) {
            return ['preview' => true, 'checksum' => $checksum, 'database' => $c->getDatabaseName(), 'sql' => $sql, 'confirmation' => 'SQL ÇALIŞTIR'];
        }
        abort_unless(($d['confirmation'] ?? '') === 'SQL ÇALIŞTIR' && hash_equals($checksum, $d['checksum'] ?? ''), 422, 'Önizlemeyi ve SQL ÇALIŞTIR onayını doğrulayın.');
        app(ImportTargetGuard::class)->assertSafe($c->getName());
        $backup = $this->preBackup($actor, $c, 'before-sql');
        $affected = $c->getPdo()->exec($sql);
        app(AuditLog::class)->record($actor, 'administration_database', 0, 'sql_executed', ['affected' => $affected, 'backup' => $backup, 'database' => $c->getDatabaseName()]);

        return ['affected' => $affected, 'backup' => $backup];
    }

    private function preBackup(User $actor, Connection $c, string $reason): string
    {
        if ($c->getDatabaseName() === DB::connection()->getDatabaseName()) {
            return app(ManagedDatabase::class)->create($actor, $reason)['name'];
        }
        $payload = ['format' => 'cte-administration-1', 'database' => $c->getDatabaseName(), 'at' => now()->toIso8601String(), 'schema' => [], 'tables' => [], 'files' => [], 'ddl' => []];
        $bytes = 0;
        foreach ($c->getSchemaBuilder()->getTableListing(schema: $c->getSchemaBuilder()->getCurrentSchemaListing(), schemaQualified: false) as $physical) {
            $table = substr($physical, strlen($c->getTablePrefix()));
            $payload['schema'][$table] = array_map(fn ($col) => ['name' => $col['name'], 'type' => $col['type_name'], 'nullable' => $col['nullable']], $c->getSchemaBuilder()->getColumns($table));
            $payload['tables'][$table] = $c->table($table)->get()->map(fn ($r) => (array) $r)->all();
            $bytes += strlen(json_encode($payload['tables'][$table]));
            if ($bytes > 64 * 1024 * 1024) {
                throw new \RuntimeException('Ön yedek 64 MB sınırını aşıyor.');
            }if (in_array($c->getDriverName(), ['mysql', 'mariadb'])) {
                $payload['ddl'][$table] = array_values((array) $c->selectOne('SHOW CREATE TABLE `'.$physical.'`'))[1];
            }
        }
        $root = app(ManagedDatabase::class)->root();
        $name = 'cte-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.cte';
        $path = $root.'/'.$name;
        file_put_contents($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), LOCK_EX);
        chmod($path, 0600);
        DB::table('administration_backups')->insert(['name' => $name, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'summary' => json_encode(['tables' => array_map('count', $payload['tables']), 'files' => 0, 'reason' => $reason, 'database' => $c->getDatabaseName()]), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

        return $name;
    }

    public function structure(User $actor, array $input): array
    {
        $d = Validator::make($input, ['operation' => ['required', 'in:create,drop,empty,add-column,drop-column,add-index,drop-index'], 'table' => ['required', 'regex:/^[a-z][a-z0-9_]{0,99}$/D'], 'column' => ['nullable', 'regex:/^[a-z][a-z0-9_]{0,49}$/D'], 'type' => ['nullable', 'in:text,longtext,integer,decimal,date,datetime,time,boolean'], 'layout' => ['nullable', 'in:plain,institution'], 'confirmed' => ['accepted'], 'confirmation' => ['required', 'in:TABLOYU DEĞİŞTİR'], 'database' => ['nullable', 'string', 'max:100']])->validate();
        $actor = app(ManagementAccess::class)->actor($actor);
        $c = $this->connection($d['database'] ?? null);
        app(ImportTargetGuard::class)->assertSafe($c->getName());
        $s = $c->getSchemaBuilder();
        $table = $d['table'];
        if ($d['operation'] === 'create') {
            abort_unless(str_starts_with($table, 'custom_') && ! $s->hasTable($table), 422, 'Yeni tablo custom_ koduyla başlamalı.');
        } else {
            abort_unless($s->hasTable($table), 422);
        }
        $backup = $this->preBackup($actor, $c, 'before-table-change');
        if ($d['operation'] === 'create') {
            $s->create($table, function (Blueprint $t) use ($d) {
                $t->id();
                if (($d['layout'] ?? 'plain') === 'institution') {
                    $t->string('kind', 40);
                    $t->json('values');
                    $t->string('natural_key', 100)->nullable();
                    $t->dateTime('event_on')->nullable();
                    $t->integer('position')->default(0);
                    $t->boolean('is_active')->default(true);
                    $t->unsignedInteger('revision')->default(1);
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->unique(['kind', 'natural_key']);
                }$t->timestamps();
            });
        } elseif ($d['operation'] === 'drop') {
            $s->drop($table);
        } elseif ($d['operation'] === 'empty') {
            $c->table($table)->delete();
        } else {
            $column = $d['column'] ?? '';
            abort_unless($column !== '', 422);
            if ($d['operation'] === 'add-column') {
                $s->table($table, function (Blueprint $t) use ($d, $column) {
                    match ($d['type'] ?? 'text') {
                        'integer' => $t->integer($column)->nullable(),'decimal' => $t->decimal($column, 18, 4)->nullable(),'boolean' => $t->boolean($column)->nullable(),'date' => $t->date($column)->nullable(),'datetime' => $t->dateTime($column)->nullable(),'time' => $t->time($column)->nullable(),default => $t->text($column)->nullable()
                    };
                });
            } elseif ($d['operation'] === 'drop-column') {
                $protected = $this->protectedColumns($table);
                abort_if(in_array($column, $protected), 422, 'Bu kolon uygulama veya ilişki tarafından kullanılıyor.');
                $s->table($table, fn (Blueprint $t) => $t->dropColumn($column));
            } elseif ($d['operation'] === 'add-index') {
                $s->table($table, fn (Blueprint $t) => $t->index($column));
            } else {
                $s->table($table, fn (Blueprint $t) => $t->dropIndex($column));
            }
        }
        app(AuditLog::class)->record($actor, 'administration_database', 0, $d['operation'], ['table' => $table, 'column' => $d['column'] ?? null, 'backup' => $backup]);

        return ['backup' => $backup, 'operation' => $d['operation']];
    }

    private function protectedColumns(string $table): array
    {
        $protected = ['id', 'created_at', 'updated_at', 'revision'];
        foreach (Schema::getForeignKeys($table) as $fk) {
            $protected = array_merge($protected, $fk['columns']);
        }
        if ($table === 'person_case_data') {
            $protected = array_merge($protected, array_keys(app(CaseFields::class)->all()));
        }

        return array_unique($protected);
    }

    public function reset(User $actor, array $input): array
    {
        $d = Validator::make($input, ['kind' => ['required', 'in:records,all'], 'confirmed' => ['accepted'], 'confirmation' => ['required', 'in:VERİLERİ SIFIRLA']])->validate();
        app(ManagementAccess::class)->actor($actor);
        $backup = app(ManagedDatabase::class)->create($actor, 'before-reset');
        if ($d['kind'] === 'records') {
            $tables = ['person_document_cleanup', 'person_documents', 'person_notes', 'person_photos', 'housing_occupancies', 'person_locations', 'person_status_assignments', 'person_case_data', 'person_case_effects', 'person_leaves', 'sentence_terms', 'module_record_options', 'module_record_index', 'module_records', 'announcement_reads', 'institution_records', 'duty_unavailable', 'meal_files', 'chat_messages', 'message_attachments', 'personal_messages', 'people'];
            $bound = DB::table('portal_pages')->whereNotNull('data_table')->pluck('data_table')->all();
            foreach ($bound as $table) {
                if ($table !== '' && Schema::hasTable($table) && ! array_diff(['kind', 'values', 'natural_key', 'revision'], Schema::getColumnListing($table))) {
                    $tables[] = $table;
                }
            }
            DB::transaction(function () use ($tables, $actor) {
                app(ManagementAccess::class)->actor($actor, true);
                $cleanup = app(PrivateFileCleanup::class);
                if (Schema::hasTable('message_attachments')) {
                    $cleanup->enqueue(DB::table('message_attachments')->whereNotNull('path')->pluck('path')->all());
                }
                $files = array_merge(
                    Schema::hasTable('person_photos') ? DB::table('person_photos')->whereNotNull('path')->pluck('path')->all() : [],
                    Schema::hasTable('meal_files') ? DB::table('meal_files')->whereNotNull('path')->pluck('path')->all() : [],
                );
                foreach (array_unique($tables) as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
                if ($files !== []) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($files));
                }
                $cleanup->afterCommit();
                app(AuditLog::class)->record($actor, 'administration_database', 0, 'records_reset');
            });

            return ['backup' => $backup['name']];
        }
        // Full initialization preserves the signing administrator and installation identity.
        $user = (array) DB::table('users')->find($actor->id);
        $installation = (array) DB::table('portal_installations')->find(1);
        $savedBackup = (array) DB::table('administration_backups')->find($backup['id']);
        $status = Artisan::call('migrate:fresh', ['--force' => true]);
        if ($status !== 0) {
            throw new \RuntimeException('Temiz kurulum tamamlanamadı. Ön yedek korundu.');
        }
        $user['department_id'] = null;
        $user['job_title_id'] = null;
        $user['session_version']++;
        $user['remember_token'] = null;
        DB::table('users')->insert($user);
        DB::table('portal_installations')->updateOrInsert(['id' => 1], $installation);
        DB::table('administration_backups')->insert($savedBackup);

        return ['backup' => $backup['name'], 'redirect' => route('login')];
    }

    private function expectedFields(): array
    {
        return app(FieldDefinitions::class)->all(app(CaseFields::class)->defaults(), null, true);
    }

    public function diagnose(): array
    {
        $columns = Schema::getColumnListing('person_case_data');
        $expected = array_keys($this->expectedFields());
        $missing = array_values(array_diff($expected, $columns));
        $orphans = array_values(array_diff($columns, $expected, ['id', 'person_id', 'created_at', 'updated_at'], array_filter($columns, fn ($c) => str_ends_with($c, '_revision'))));
        $pending = Artisan::call('migrate:status');
        $checks = [];
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
            foreach (DB::select('SELECT TABLE_NAME as name, ENGINE as engine, TABLE_COLLATION as collation FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $row) {
                if (str_starts_with($row->name, DB::connection()->getTablePrefix())) {
                    $checks[] = (array) $row;
                }
            }
        }

        return ['missing_fields' => $missing, 'unused_columns' => $orphans, 'tables' => $checks, 'migration_status' => Artisan::output(), 'source_files' => ['case_fields' => hash_file('sha256', resource_path('defaults/person-workflows.json')), 'institution_fields' => hash_file('sha256', resource_path('defaults/institution-workflows.json'))]];
    }

    public function repair(User $actor, array $input): array
    {
        $d = Validator::make($input, ['confirmed' => ['accepted'], 'cleanup' => ['sometimes', 'array'], 'cleanup.*' => ['regex:/^[a-z][a-z0-9_]*$/D']])->validate();
        $backup = app(ManagedDatabase::class)->create($actor, 'before-schema-repair');
        $diagnosis = $this->diagnose();
        foreach ($d['cleanup'] ?? [] as $column) {
            abort_unless(in_array($column, $diagnosis['unused_columns']), 422);
            abort_if(DB::table('person_case_data')->whereNotNull($column)->where($column, '!=', '')->exists(), 422, 'Dolu artık kolon silinemez.');
            Schema::table('person_case_data', fn (Blueprint $t) => $t->dropColumn($column));
        }
        foreach ($diagnosis['missing_fields'] as $column) {
            $field = $this->expectedFields()[$column];
            Schema::table('person_case_data', function (Blueprint $t) use ($column, $field) {
                match ($field['type']) {
                    'integer','option' => $t->integer($column)->nullable(),'decimal' => $t->decimal($column, 18, 4)->nullable(),'boolean' => $t->boolean($column)->nullable(),'datetime' => $t->dateTime($column)->nullable(),'date' => $t->date($column)->nullable(),'time' => $t->time($column)->nullable(),default => $t->text($column)->nullable()
                };
            });
        }
        foreach ($diagnosis['tables'] as $table) {
            $name = $table['name'];
            if (! preg_match('/^[a-z0-9_]+$/D', $name)) {
                continue;
            }if (strtoupper($table['engine']) !== 'INNODB') {
                DB::statement('ALTER TABLE `'.$name.'` ENGINE=InnoDB');
            }if (! str_starts_with($table['collation'] ?? '', 'utf8mb4_')) {
                DB::statement('ALTER TABLE `'.$name.'` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
        }
        Artisan::call('migrate', ['--force' => true]);

        return ['backup' => $backup['name'], 'diagnosis' => $this->diagnose()];
    }
}
